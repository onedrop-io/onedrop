<?php

namespace App\Http\Controllers;

use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Enums\PublishStatus;
use App\Enums\SandboxStatus;
use App\Http\Requests\StoreProjectRequest;
use App\Jobs\CreateSandbox;
use App\Jobs\RunAgentTask;
use App\Models\Message;
use App\Models\Project;
use App\Sandbox\Publishing\Publisher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    /**
     * Show the "what are we working on today?" prompt.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('projects/create', [
            'defaultAi' => $request->user()->agentConnections()->firstWhere('is_default', true)?->provider->label(),
        ]);
    }

    /**
     * Create a project from a description and start the agent on it.
     */
    public function store(StoreProjectRequest $request): RedirectResponse
    {
        $prompt = $request->validated('prompt');

        $project = $request->user()->projects()->create([
            'name' => Project::nameFromPrompt($prompt),
            'prompt' => $prompt,
        ]);

        // Show "Thinking…" right away: the page only polls for updates while the agent is working,
        // and the queued run may take a moment to start.
        $project->update(['status' => ProjectStatus::Working]);

        $message = $project->messages()->create([
            'role' => MessageRole::User,
            'content' => $prompt,
        ]);

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
     * Show the chat + preview workspace.
     */
    public function show(Project $project, Publisher $publisher): Response
    {
        Gate::authorize('view', $project);

        return Inertia::render('projects/show', [
            'project' => $project->only('id', 'name', 'status'),
            'publication' => [
                'status' => $project->publish_status,
                'visibility' => $project->publish_visibility,
                'url' => $project->published_url,
                'published_at' => $project->published_at?->toIso8601String(),
                'published_by' => $project->publisher?->name,
                'error' => $project->publish_error,
                'login_url' => $project->publish_status === PublishStatus::Publishing ? $project->publish_login_url : null,
                'unavailable' => $publisher->unavailableReason(),
            ],
            'sandbox' => $project->sandbox?->only('status', 'preview_url', 'shell_url', 'error'),
            'messages' => $project->messages->map(fn (Message $message): array => [
                'id' => $message->id,
                'role' => $message->role,
                'content' => $message->content,
                'created_at' => $message->created_at?->toIso8601String(),
            ]),
        ]);
    }
}
