<?php

namespace App\Http\Controllers;

use App\Actions\DeleteProject;
use App\Concerns\RendersWorkspace;
use App\Concerns\ValidatesAgentSelection;
use App\Enums\AppTemplate;
use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Enums\SandboxStatus;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Jobs\CreateSandbox;
use App\Jobs\RegenerateProjectName;
use App\Jobs\RunAgentTask;
use App\Models\Attachment;
use App\Models\Project;
use App\Sandbox\Agents\ModelCatalog;
use App\Sandbox\Agents\ProjectNamer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    use RendersWorkspace, ValidatesAgentSelection;

    /**
     * Show the "what are we working on today?" prompt.
     */
    public function create(Request $request, ModelCatalog $catalog): Response
    {
        $harness = $catalog->defaultHarness($request->user());
        $selection = $catalog->defaultSelection($request->user(), $harness);

        return Inertia::render('projects/create', [
            'defaultAi' => $request->user()->agentConnections()->firstWhere('is_default', true)?->provider->label(),
            'agent' => $selection ? $catalog->describe($selection, $harness) : null,
            'templates' => AppTemplate::options(),
        ]);
    }

    /**
     * Create a project from a description (or a template's) and start the agent on it.
     */
    public function store(StoreProjectRequest $request, ModelCatalog $catalog): RedirectResponse
    {
        $prompt = (string) $request->validated('prompt');
        $template = $request->enum('template', AppTemplate::class);
        $agent = $this->validatedAgentSelection($request, $request->user(), $catalog) ?? [];

        if ($agent) {
            $request->user()->rememberModel($agent['agent_provider'], $agent['agent_model']);
        }

        $project = $request->user()->projects()->create([
            'name' => $template?->label() ?? Project::nameFromPrompt($prompt),
            'prompt' => $prompt,
            ...$agent,
        ]);

        // Show "Thinking…" right away: the page only polls for updates while the agent is working,
        // and the queued run may take a moment to start.
        $project->update(['status' => ProjectStatus::Working]);

        $message = $project->messages()->create([
            'role' => MessageRole::User,
            'content' => $prompt,
        ]);

        foreach ($request->file('attachments', []) as $file) {
            Attachment::store($message, $file);
        }

        $project->sandbox()->create([
            'provider' => config('sandbox.provider'),
            'status' => SandboxStatus::Creating,
        ]);

        Bus::chain([
            new CreateSandbox($project),
            new RunAgentTask($project, $message),
        ])->dispatch();

        return to_route('projects.show', $project);
    }

    /**
     * Show the chat + preview workspace, on the project's main chat.
     */
    public function show(Request $request, Project $project): Response
    {
        Gate::authorize('view', $project);

        return $this->renderWorkspace($request, $project, $project);
    }

    /**
     * Rename, pin, mark unread, or archive the project from its sidebar menu. None of these move it in "Recent".
     */
    public function update(UpdateProjectRequest $request, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);

        $changes = [];

        if ($request->has('name')) {
            $changes['name'] = Str::squish((string) $request->validated('name'));
        }

        foreach (['pinned' => 'pinned_at', 'archived' => 'archived_at'] as $input => $column) {
            if ($request->has($input)) {
                $changes[$column] = $request->boolean($input) ? ($project->{$column} ?? now()) : null;
            }
        }

        if ($request->has('unread')) {
            $changes['read_at'] = $request->boolean('unread') ? null : now();
        }

        Project::withoutTimestamps(fn () => $project->update($changes));

        // Opening the project marks it read again, so leave it for the new-project page.
        if ($request->boolean('unread') && $this->cameFrom($project)) {
            return to_route('dashboard');
        }

        return back();
    }

    /**
     * Have the project's AI title it from the chat. The sidebar shows it's being named until the job is done.
     */
    public function regenerateName(Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);

        ProjectNamer::markNaming($project);
        RegenerateProjectName::dispatch($project);

        return back();
    }

    /**
     * Delete the project: take its app offline, then remove its chat, attachments, and sandbox.
     */
    public function destroy(Project $project, DeleteProject $deleteProject): RedirectResponse
    {
        Gate::authorize('delete', $project);

        $deleteProject->handle($project);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Deleted “:name”.', ['name' => $project->name])]);

        return $this->cameFrom($project) ? to_route('dashboard') : back();
    }

    /**
     * Whether the request was made from one of the project's own pages (its workspace, a task, or its board).
     */
    protected function cameFrom(Project $project): bool
    {
        $previous = Str::before(url()->previous(), '?');
        $workspace = route('projects.show', $project);

        return $previous === $workspace || Str::startsWith($previous, "{$workspace}/");
    }
}
