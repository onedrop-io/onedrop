<?php

namespace App\Http\Controllers;

use App\Actions\DeleteProject;
use App\Concerns\RendersWorkspace;
use App\Concerns\ValidatesAgentSelection;
use App\Enums\AppTemplate;
use App\Enums\GitSyncStatus;
use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Enums\SandboxStatus;
use App\Http\Middleware\ResolveOrganization;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Jobs\ApplyRegistryTemplate;
use App\Jobs\CreateSandbox;
use App\Jobs\ImportRepository;
use App\Jobs\RegenerateProjectName;
use App\Jobs\RunAgentTask;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Sandbox\Agents\ModelCatalog;
use App\Sandbox\Agents\ProjectNamer;
use App\Sandbox\GitException;
use App\Sandbox\GitHubApp;
use App\Sandbox\RepositoryImport;
use App\Sandbox\Templates\TemplateCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    use RendersWorkspace, ValidatesAgentSelection;

    /**
     * Show the "what are we working on today?" prompt.
     */
    public function create(Request $request, ModelCatalog $catalog, GitHubApp $github, TemplateCatalog $templates): Response
    {
        $agent = $catalog->newProjectAgent($request->user());

        return Inertia::render('projects/create', [
            'defaultAi' => $request->user()->agentConnections()->firstWhere('is_default', true)?->provider->label(),
            'agent' => $agent ? $catalog->describe(['provider' => $agent['agent_provider'], 'model' => $agent['agent_model'], 'variant' => $agent['agent_variant']], $agent['agent_harness']) : null,
            'templates' => AppTemplate::options(),
            // Every free open-source app under the built-in templates (PRJ-012), after the page shows, since the registry may be slow.
            'apps' => Inertia::defer(fn () => $templates->apps()),
            // The popular apps with a picture each, for the coverflow above them; its own group, since finding a picture
            // can mean fetching each app's website.
            'featured' => Inertia::defer(fn () => $templates->featured(), 'featured'),
            'compose' => $templates->canRunCompose(),
            // "Remix this" on a share page (SHARE-002) opens this page with the shared prompt filled in.
            'remix' => $request->session()->pull('remix'),
            // Importing a repository (PRJ-009): private GitHub ones need the GitHub App.
            'github' => [
                'configured' => $configured = $github->configured(),
                'signed_in' => $configured && $request->user()->githubInstallations()->exists() && $github->userToken($request->user()) !== null,
                'connect_url' => $configured ? route('github-app.install') : null,
                // Back from connecting it: open the import, with GitHub's error if it failed.
                'returned' => $request->session()->get('github_import'),
            ],
        ]);
    }

    /**
     * Create a project from a description (or a template's), or from a repository, and start the agent on it.
     */
    public function store(StoreProjectRequest $request, ModelCatalog $catalog, RepositoryImport $import, TemplateCatalog $templates): RedirectResponse
    {
        $prompt = (string) $request->validated('prompt');
        $template = $request->filled('template') ? $templates->find((string) $request->validated('template')) : null;
        $repository = null;

        if ($request->filled('repository')) {
            try {
                $repository = $import->resolve($request->user(), (string) $request->validated('repository'));
            } catch (GitException $e) {
                throw ValidationException::withMessages(['repository' => $e->getMessage()]);
            }

            $template = null;
            $prompt = trim($prompt) !== '' ? $prompt : __('I imported this app from its repository. Get it running in the preview.');
        }

        $default = $catalog->newProjectAgent($request->user());
        $agent = $this->validatedAgentSelection($request, $request->user(), $catalog);

        if ($agent) {
            $request->user()->rememberModel($agent['agent_provider'], $agent['agent_model']);

            // Only a choice that differs from the default sticks, so an untouched picker keeps following it.
            if ($agent != $default) {
                $request->user()->preferAgent($agent);
            }
        }

        $agent ??= $default ?? [];

        $project = $request->user()->projects()->create([
            'organization_id' => ResolveOrganization::current($request)->id,
            'name' => $repository['name'] ?? $template['label'] ?? Project::nameFromPrompt($prompt),
            'prompt' => $prompt,
            ...$agent,
            ...($repository ? [
                'git_remote_url' => $repository['url'],
                'github_installation_id' => $repository['installation_id'],
                'git_sync_status' => GitSyncStatus::Pulling,
            ] : []),
        ]);

        // Show "Thinking…" right away: the page only polls for updates while the agent is working,
        // and the queued run may take a moment to start.
        $project->update(['status' => ProjectStatus::Working]);

        // A registry template's setup goes to the agent only, beside what the user wrote (PRJ-012).
        $registry = $template ? $templates->registryFor($template['value']) : null;

        $message = $project->messages()->create([
            'role' => MessageRole::User,
            'content' => $prompt,
            ...($registry ? ['meta' => ['template' => $template['value'], 'agent_context' => $registry->agentContext($template)]] : []),
        ]);

        foreach ($request->file('attachments', []) as $file) {
            Attachment::store($message, $file);
        }

        $project->sandbox()->create([
            'provider' => config('sandbox.provider'),
            'status' => SandboxStatus::Creating,
        ]);

        Bus::chain(array_values(array_filter([
            new CreateSandbox($project),
            $repository ? new ImportRepository($project, $message, $repository['branch']) : null,
            $registry ? new ApplyRegistryTemplate($project, $message, $template['value']) : null,
            new RunAgentTask($project, $message),
        ])))->dispatch();

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

        // Marking read clears its tasks' dots too, since they make the project unread (PRJ-008).
        if ($request->has('unread') && ! $request->boolean('unread')) {
            Task::withoutTimestamps(fn () => $project->tasks()->update(['read_at' => now()]));
        }

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
