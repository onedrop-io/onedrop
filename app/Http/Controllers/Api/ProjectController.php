<?php

namespace App\Http\Controllers\Api;

use App\Actions\DeleteProject;
use App\Actions\StartProject;
use App\Actions\UpdateProject;
use App\Concerns\RendersWorkspace;
use App\Concerns\SummarizesProjects;
use App\Concerns\ValidatesAgentSelection;
use App\Enums\AppTemplate;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveOrganization;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Models\Project;
use App\Models\Sandbox;
use App\Sandbox\Agents\ModelCatalog;
use App\Sandbox\Gateway;
use App\Sandbox\GitHubApp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;

/**
 * Projects for the desktop app (DESK-002, DESK-003): the same data and actions as the web app's sidebar and workspace.
 */
class ProjectController extends Controller
{
    use RendersWorkspace, SummarizesProjects, ValidatesAgentSelection;

    /**
     * The user's projects in the organization they're working in, listed as the sidebar shows them: pinned, recent
     * and archived, in the order they chose.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->sidebarProjects($request->user(), ResolveOrganization::current($request)));
    }

    /**
     * What a new project starts from, as on the web's new-project page: the agent it would use, the templates, and
     * importing a repository (PRJ-009). Connecting GitHub happens in the browser, on the web's own address.
     */
    public function create(Request $request, ModelCatalog $catalog, GitHubApp $github): JsonResponse
    {
        $agent = $catalog->newProjectAgent($request->user());

        return response()->json([
            'defaultAi' => $request->user()->agentConnections()->firstWhere('is_default', true)?->provider->label(),
            'agent' => $agent ? $catalog->describe(['provider' => $agent['agent_provider'], 'model' => $agent['agent_model'], 'variant' => $agent['agent_variant']], $agent['agent_harness']) : null,
            'templates' => AppTemplate::options(),
            'github' => [
                'configured' => $configured = $github->configured(),
                'signed_in' => $configured && $request->user()->githubInstallations()->exists() && $github->userToken($request->user()) !== null,
                'connect_url' => $configured ? route('github-app.install') : null,
                'returned' => null,
            ],
        ]);
    }

    /**
     * Create a project from a description (or a template's) and start the agent on it.
     */
    public function store(StoreProjectRequest $request, ModelCatalog $catalog, StartProject $startProject): JsonResponse
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

        return response()->json(['id' => $project->id], 201);
    }

    /**
     * The workspace on the project's main chat. Like opening it on the web, this marks it read (unless the app sends
     * `X-Onedrop-Unseen` because nobody's looking) and keeps its sandbox current and awake.
     */
    public function show(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        // The web shows a held message (SECRET-002) once; the app leaves it for the web rather than use it up.
        return response()->json(Arr::except($this->workspaceProps($request, $project, $project), 'held'));
    }

    /**
     * Rename, pin, mark unread, or archive the project.
     */
    public function update(UpdateProjectRequest $request, Project $project, UpdateProject $updateProject): Response
    {
        Gate::authorize('update', $project);

        $updateProject->handle($project, $request->validated());

        return response()->noContent();
    }

    /**
     * Delete the project: take its app offline, then remove its chat, attachments, and sandbox.
     */
    public function destroy(Project $project, DeleteProject $deleteProject): Response
    {
        Gate::authorize('delete', $project);

        $deleteProject->handle($project);

        return response()->noContent();
    }

    /**
     * The app has no session for the gateway's sign-in to ride on, so it gets the sandbox's own sign-in address,
     * good for Gateway::TOKEN_SECONDS. The app fetches the workspace again for a fresh one when it reloads the frame.
     */
    protected function gatewayAddress(Request $request, Project $project, Sandbox $sandbox, string $kind, string $path = '/'): string
    {
        return app(Gateway::class)->enterUrl($sandbox, $kind, $request->user(), $path);
    }

    protected function attachmentRoute(): string
    {
        return 'api.projects.attachments.show';
    }

    protected function projectIconUrl(Project $project): ?string
    {
        return $project->icon_path && $project->icon_hash
            ? route('api.projects.icon.show', ['project' => $project, 'v' => substr($project->icon_hash, 0, 12)])
            : null;
    }
}
