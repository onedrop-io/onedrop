<?php

namespace App\Sandbox;

use App\Models\Project;
use App\Models\Sandbox;
use App\Models\Skill;
use Illuminate\Support\Facades\Log;

/**
 * Agent skills inside a project's sandbox (SKILL-003, SKILL-004), through docker/sandbox/skills.php: the project's
 * own skills, and putting the skills turned on in the project where its agent looks for them before each run.
 */
class SandboxSkills
{
    public const SCRIPT = '/opt/onedrop/skills.php';

    public const GUIDE = '/opt/onedrop/guides/skills.md';

    public function __construct(protected SandboxProvider $provider, protected WorkspaceFiles $files) {}

    /**
     * The skills in the project's repository.
     *
     * @return list<array{name: string, description: string|null, path: string}>
     *
     * @throws SandboxException|SkillException
     */
    public function projectSkills(Sandbox $sandbox): array
    {
        return array_values(array_map(function (array $skill) {
            $fields = SkillDocument::parse((string) $skill['head'])['fields'];
            $description = trim($fields['description'] ?? '');

            return [
                'name' => (string) (($fields['name'] ?? '') !== '' ? $fields['name'] : $skill['folder']),
                'description' => $description === '' ? null : mb_substr($description, 0, SkillDocument::MAX_DESCRIPTION),
                'path' => (string) $skill['path'],
            ];
        }, $this->call($sandbox, ['op' => 'list'])['skills']));
    }

    /**
     * A project skill's files, by path in its folder.
     *
     * @return array<string, string>
     *
     * @throws SandboxException|SkillException
     */
    public function projectSkillFiles(Sandbox $sandbox, string $path): array
    {
        $files = [];

        foreach ($this->call($sandbox, ['op' => 'export', 'path' => $path])['files'] as $file) {
            $files[(string) $file['path']] = (string) base64_decode((string) $file['data'], true);
        }

        return $files;
    }

    /**
     * Put the project's skills where the agent ($agent, an AgentHarness value) looks for them. Uploads the user's
     * skills only when the sandbox's copy is out of date. Never throws: a run goes on without skills.
     */
    public function install(Sandbox $sandbox, Project $project, string $agent): void
    {
        try {
            $bundle = $this->bundle($project);
            $hash = sha1($bundle);
            $request = ['op' => 'sync', 'agent' => $agent, 'hash' => $hash];

            if ($this->call($sandbox, $request)['current'] ?? false) {
                return;
            }

            $path = '/tmp/onedrop-skills-'.bin2hex(random_bytes(6)).'.b64';
            $this->files->writeChunks($sandbox, $path, base64_encode($bundle), "Couldn't upload the skills.");
            $this->call($sandbox, [...$request, 'bundle' => $path]);
        } catch (SandboxException|SkillException $e) {
            Log::warning('Could not put agent skills in place', ['project' => $project->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * The skills turned on in the project, as the JSON bundle skills.php unpacks. The first one with a name wins.
     */
    public function bundle(Project $project): string
    {
        $skills = $project->skills()->get()
            ->unique('name')
            ->map(fn (Skill $skill) => ['name' => $skill->name, 'content' => $skill->content, 'files' => $skill->files ?? []])
            ->values()
            ->all();

        return json_encode(['skills' => $skills], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** The chat message asking the agent to write a new project skill. */
    public static function createRequest(string $description): string
    {
        return 'Create an agent skill for this project: '.trim($description).' Follow the guide at '.self::GUIDE.'.';
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     *
     * @throws SandboxException|SkillException
     */
    protected function call(Sandbox $sandbox, array $request): array
    {
        $result = $this->provider->exec($sandbox->external_id, ['php', self::SCRIPT], [
            'APP_SKILLS_REQUEST' => json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $response = json_decode(trim($result->output), true);

        if (! is_array($response) || ! isset($response['ok']) || ($response['error'] ?? null) === 'Unknown operation.') {
            throw new SandboxException(__("This sandbox's skills tool is missing or out of date. Open the project again to update its sandbox."));
        }

        if ($response['ok'] !== true) {
            throw new SkillException((string) ($response['error'] ?? __('The skills request failed.')));
        }

        return is_array($response['data'] ?? null) ? $response['data'] : [];
    }
}
