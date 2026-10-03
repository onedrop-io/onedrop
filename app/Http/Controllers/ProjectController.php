<?php

namespace App\Http\Controllers;

use App\Actions\DeleteProject;
use App\Actions\StartProject;
use App\Actions\UpdateProject;
use App\Concerns\RendersWorkspace;
use App\Concerns\ValidatesAgentSelection;
use App\Enums\AppTemplate;
use App\Http\Middleware\ResolveOrganization;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Jobs\RegenerateProjectName;
use App\Models\Project;
use App\Sandbox\Agents\ModelCatalog;
use App\Sandbox\Agents\ProjectNamer;
use App\Sandbox\GitHubApp;
use App\Sandbox\Templates\TemplateCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            // What they typed or picked on the home page (HOME-004).
            'start' => $request->session()->pull('start'),
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
    public function store(StoreProjectRequest $request, ModelCatalog $catalog, StartProject $startProject): RedirectResponse
    {
        $project = $startProject->handle(
            $request->user(),
            ResolveOrganization::current($request),
            (string) $request->validated('prompt'),
            $request->filled('template') ? (string) $request->validated('template') : null,
            $this->validatedAgentSelection($request, $request->user(), $catalog),
            array_values($request->file('attachments', [])),
            $request->filled('repository') ? (string) $request->validated('repository') : null,
        );

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
    public function update(UpdateProjectRequest $request, Project $project, UpdateProject $updateProject): RedirectResponse
    {
        Gate::authorize('update', $project);

        $updateProject->handle($project, $request->validated());

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
