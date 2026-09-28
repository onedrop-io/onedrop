<?php

namespace App\Http\Controllers;

use App\Concerns\ValidatesAgentSelection;
use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Enums\PublishStatus;
use App\Enums\SandboxStatus;
use App\Http\Requests\StoreProjectRequest;
use App\Jobs\CreateSandbox;
use App\Jobs\RunAgentTask;
use App\Jobs\UpdateSandbox;
use App\Models\Attachment;
use App\Models\Message;
use App\Models\Project;
use App\Sandbox\Agents\ModelCatalog;
use App\Sandbox\Gateway;
use App\Sandbox\Publishing\Publisher;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxProvider;
use App\Sandbox\SandboxUpdater;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    use ValidatesAgentSelection;

    /**
     * Show the "what are we working on today?" prompt.
     */
    public function create(Request $request, ModelCatalog $catalog): Response
    {
        $selection = $catalog->defaultSelection($request->user());

        return Inertia::render('projects/create', [
            'defaultAi' => $request->user()->agentConnections()->firstWhere('is_default', true)?->provider->label(),
            'agent' => $selection ? $catalog->describe($selection) : null,
        ]);
    }

    /**
     * Create a project from a description and start the agent on it.
     */
    public function store(StoreProjectRequest $request, ModelCatalog $catalog): RedirectResponse
    {
        $prompt = (string) $request->validated('prompt');
        $agent = $this->validatedAgentSelection($request, $request->user(), $catalog) ?? [];

        if ($agent) {
            $request->user()->rememberModel($agent['agent_provider'], $agent['agent_model']);
        }

        $project = $request->user()->projects()->create([
            'name' => Project::nameFromPrompt($prompt),
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
     * Show the chat + preview workspace.
     */
    public function show(Project $project, Publisher $publisher, Gateway $gateway, ModelCatalog $catalog, SandboxUpdater $updater, SandboxProvider $provider): Response
    {
        Gate::authorize('view', $project);

        $this->updateOutdatedSandbox($project, $updater);
        $this->renewSandboxAddresses($project, $provider);
        $project->load(['messages.attachments', 'queuedMessages.attachments']);

        return Inertia::render('projects/show', [
            'project' => $project->only('id', 'name', 'status'),
            'agent' => ($selection = $catalog->selectionFor($project)) ? $catalog->describe($selection) : null,
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
            'sandbox' => $project->sandbox ? [
                ...$project->sandbox->only('status', 'error'),
                'updating' => SandboxUpdater::isUpdating($project),
                // On servers the browser goes through the gateway (which signs it in to that address), not the sandbox's local ports.
                'preview_url' => $project->sandbox->preview_url ? ($gateway->enabled() ? route('projects.gateway.open', [$project, 'preview']) : $project->sandbox->preview_url) : null,
                'shell_url' => $project->sandbox->shell_url ? ($gateway->enabled() ? route('projects.gateway.open', [$project, 'shell']) : $project->sandbox->shell_url) : null,
            ] : null,
            'queued' => $project->queuedMessages->map(fn (Message $message): array => [
                ...$message->only('id', 'content'),
                'attachments' => $message->attachments->map(fn (Attachment $attachment): array => $this->attachmentProps($project, $attachment)),
            ]),
            'messages' => $project->messages->map(fn (Message $message): array => [
                'id' => $message->id,
                'role' => $message->role,
                'content' => $message->content,
                'attachments' => $message->attachments->map(fn (Attachment $attachment): array => $this->attachmentProps($project, $attachment)),
                'created_at' => $message->created_at?->toIso8601String(),
            ]),
        ]);
    }

    /**
     * @return array{id: int, name: string, mime_type: string, size: int, image: bool, url: string}
     */
    protected function attachmentProps(Project $project, Attachment $attachment): array
    {
        return [
            ...$attachment->only('id', 'name', 'mime_type', 'size'),
            'image' => $attachment->isVisibleImage(),
            'url' => route('projects.attachments.show', [$project, $attachment]),
        ];
    }

    /**
     * Queue an update when the sandbox was made from an older image, so opening a project brings it the
     * current guides and tools. Checked at most once a minute per project; never while the agent works.
     */
    protected function updateOutdatedSandbox(Project $project, SandboxUpdater $updater): void
    {
        if ($project->status === ProjectStatus::Working || ! Cache::add("sandbox-outdated-check:{$project->id}", true, 60)) {
            return;
        }

        try {
            if ($updater->isOutdated($project)) {
                SandboxUpdater::markUpdating($project);
                UpdateSandbox::dispatch($project);
            }
        } catch (SandboxException) {
            // The workspace shows the sandbox as it is.
        }
    }

    /**
     * Private preview links on Blaxel and Runtime carry tokens that last 7 days: fetch fresh ones at most once a day.
     */
    protected function renewSandboxAddresses(Project $project, SandboxProvider $provider): void
    {
        $sandbox = $project->sandbox;

        if (! in_array($sandbox?->provider, ['blaxel', 'runtime'], true) || $sandbox?->status !== SandboxStatus::Running || ! $sandbox->external_id
            || ! Cache::add("sandbox-addresses:{$sandbox->id}", true, now()->addDay())) {
            return;
        }

        try {
            $sandbox->update(CreateSandbox::addresses($provider, $sandbox->external_id));
        } catch (SandboxException) {
            // Try again on the next visit.
            Cache::forget("sandbox-addresses:{$sandbox->id}");
        }
    }
}
