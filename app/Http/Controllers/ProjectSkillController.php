<?php

namespace App\Http\Controllers;

use App\Enums\SandboxStatus;
use App\Enums\SkillSource;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\Skill;
use App\Models\User;
use App\Sandbox\Agents\AgentQueue;
use App\Sandbox\GitHubSkillImporter;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxSkills;
use App\Sandbox\SkillDocument;
use App\Sandbox\SkillException;
use App\Sandbox\SkillPackage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use ZipArchive;

/**
 * Tools → Agent Skills (SKILL-001..003): the skills the user can turn on in a project, adding them, and the project's own.
 */
class ProjectSkillController extends Controller
{
    /** The most an uploaded zip may unpack to, before the skill's own limit is checked. */
    protected const MAX_UNZIPPED_BYTES = 8 * 1024 * 1024;

    /**
     * The user's skills and the shared ones, with whether each is on in the project.
     */
    public function index(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        return response()->json(['skills' => $this->skills($request->user(), $project)]);
    }

    /**
     * Write a new skill; it's turned on in the project.
     */
    public function store(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('update', $project);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:'.SkillDocument::MAX_NAME],
            'description' => ['required', 'string', 'max:'.SkillDocument::MAX_DESCRIPTION],
            'instructions' => ['required', 'string', 'max:200000'],
            'shared' => ['sometimes', 'boolean'],
        ]);

        return $this->add($request->user(), $project, [
            'name' => $data['name'],
            'description' => trim($data['description']),
            'content' => SkillDocument::compose($data['name'], $data['description'], $data['instructions']),
            'files' => [],
            'shared' => (bool) ($data['shared'] ?? false),
            'source' => SkillSource::Written,
        ]);
    }

    /**
     * Import a skill from a GitHub link; it's turned on in the project.
     */
    public function import(Request $request, Project $project, GitHubSkillImporter $importer): JsonResponse
    {
        Gate::authorize('update', $project);

        $url = $request->validate(['url' => ['required', 'string', 'max:500']])['url'];

        try {
            $skill = $importer->import($request->user(), $url);
        } catch (SkillException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->add($request->user(), $project, [
            ...collect($skill)->except('url')->all(),
            'source' => SkillSource::GitHub,
            'source_url' => $skill['url'],
        ]);
    }

    /**
     * Upload a SKILL.md or a zip of a skill folder; it's turned on in the project.
     */
    public function upload(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('update', $project);

        $file = $request->validate(['file' => ['required', 'file', 'max:5120']])['file'];

        try {
            $skill = SkillPackage::fromFiles($this->uploadedFiles($file));
        } catch (SkillException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->add($request->user(), $project, [...$skill, 'source' => SkillSource::Upload]);
    }

    /**
     * Ask the agent to write a new project skill.
     */
    public function create(Request $request, Project $project, AgentQueue $queue): JsonResponse
    {
        Gate::authorize('update', $project);

        $description = $request->validate(['description' => ['required', 'string', 'max:2000']])['description'];

        return response()->json([
            'queued' => (bool) $queue->send($project, SandboxSkills::createRequest($description))->queued,
        ]);
    }

    /**
     * Turn one of the user's skills, or a shared one, on or off in the project.
     */
    public function toggle(Request $request, Project $project, Skill $skill): JsonResponse
    {
        Gate::authorize('update', $project);
        Gate::authorize('view', $skill);

        $enabled = (bool) $request->validate(['enabled' => ['required', 'boolean']])['enabled'];

        if (! $enabled) {
            $project->skills()->detach($skill);
        } elseif (! $project->skills()->whereKey($skill->id)->exists()) {
            if (! $skill->isVisibleTo($project->user)) {
                return response()->json(['message' => __('Share ":name" first, so the project\'s owner can use it.', ['name' => $skill->name])], 422);
            }

            if ($project->skills()->where('name', $skill->name)->exists()) {
                return response()->json(['message' => __('A skill named ":name" is already on in this project. Turn that one off first.', ['name' => $skill->name])], 422);
            }

            $project->skills()->attach($skill);
        }

        return response()->json(['skills' => $this->skills($request->user(), $project)]);
    }

    /**
     * The skills in the project's repository.
     */
    public function projectIndex(Project $project, SandboxSkills $skills): JsonResponse
    {
        Gate::authorize('view', $project);

        return $this->fromSandbox($project, fn (Sandbox $sandbox) => ['skills' => $skills->projectSkills($sandbox)]);
    }

    /**
     * One project skill's SKILL.md and the names of its other files.
     */
    public function projectShow(Request $request, Project $project, SandboxSkills $skills): JsonResponse
    {
        Gate::authorize('view', $project);

        $path = $request->validate(['path' => ['required', 'string', 'max:300']])['path'];

        return $this->fromSandbox($project, function (Sandbox $sandbox) use ($skills, $path) {
            $files = $skills->projectSkillFiles($sandbox, $path);
            $paths = array_values(array_diff(array_keys($files), ['SKILL.md']));

            return ['skill' => [
                'content' => SkillDocument::parse($files['SKILL.md'] ?? '')['body'],
                'files' => $paths,
            ]];
        });
    }

    /**
     * Save a copy of a project skill to the user's skills (not turned on: the project already has it).
     */
    public function saveProject(Request $request, Project $project, SandboxSkills $skills): JsonResponse
    {
        Gate::authorize('update', $project);

        $path = $request->validate(['path' => ['required', 'string', 'max:300']])['path'];

        return $this->fromSandbox($project, function (Sandbox $sandbox) use ($request, $project, $skills, $path) {
            $skill = SkillPackage::fromFiles($skills->projectSkillFiles($sandbox, $path));

            if ($problem = $this->nameTaken($request->user(), $skill['name'])) {
                throw new SkillException($problem);
            }

            $request->user()->skills()->create([...$skill, 'source' => SkillSource::Project]);

            return ['skills' => $this->skills($request->user(), $project)];
        });
    }

    /**
     * Add a skill to the user's skills and turn it on in the project.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function add(User $user, Project $project, array $attributes): JsonResponse
    {
        $problem = SkillDocument::problem($attributes['name'], $attributes['description'])
            ?? $this->nameTaken($user, $attributes['name']);

        if ($problem) {
            return response()->json(['message' => $problem, 'errors' => ['name' => [$problem]]], 422);
        }

        $skill = $user->skills()->create($attributes);

        if (! $project->skills()->where('name', $skill->name)->exists()) {
            $project->skills()->attach($skill);
        }

        return response()->json(['skill' => $skill->id, 'skills' => $this->skills($user, $project)], 201);
    }

    protected function nameTaken(User $user, string $name): ?string
    {
        if (! $user->skills()->where('name', $name)->exists()) {
            return null;
        }

        return __('You already have a skill named ":name". Rename or delete it first.', ['name' => $name]);
    }

    /**
     * The skills the user can see, for the panel.
     *
     * @return list<array<string, mixed>>
     */
    protected function skills(User $user, Project $project): array
    {
        $enabled = $project->skills()->pluck('skills.id')->all();

        return array_values(Skill::query()
            ->visibleTo($user)
            ->orWhereIn('id', $enabled)
            ->with('user:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (Skill $skill) => SkillController::present($skill, $user) + ['enabled' => in_array($skill->id, $enabled, true)])
            ->all());
    }

    /**
     * An uploaded SKILL.md, or the files in an uploaded zip.
     *
     * @return array<string, string>
     *
     * @throws SkillException
     */
    protected function uploadedFiles(UploadedFile $file): array
    {
        $zip = new ZipArchive;

        if ($zip->open($file->getRealPath(), ZipArchive::RDONLY) !== true) {
            return ['SKILL.md' => (string) file_get_contents($file->getRealPath())];
        }

        $files = [];
        $total = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = (string) ($stat['name'] ?? '');

            if ($name === '' || str_ends_with($name, '/')) {
                continue;
            }

            $total += (int) ($stat['size'] ?? 0);

            if ($total > self::MAX_UNZIPPED_BYTES || count($files) >= 500) {
                $zip->close();

                throw new SkillException(__('The skill is too big: skills can have up to :files files and 1 MB.', ['files' => SkillPackage::MAX_FILES]));
            }

            $files[$name] = (string) $zip->getFromIndex($i);
        }

        $zip->close();

        return $files;
    }

    /**
     * @param  callable(Sandbox): array<string, mixed>  $call
     */
    protected function fromSandbox(Project $project, callable $call): JsonResponse
    {
        $sandbox = $project->sandbox;

        if ($sandbox?->status !== SandboxStatus::Running || $sandbox->external_id === null) {
            return response()->json(['message' => __("The project's sandbox isn't running.")], 409);
        }

        try {
            return response()->json($call($sandbox));
        } catch (SkillException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (SandboxException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }
}
