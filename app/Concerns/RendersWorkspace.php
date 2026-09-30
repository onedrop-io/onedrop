<?php

namespace App\Concerns;

use App\Enums\AgentProvider;
use App\Enums\CredentialType;
use App\Enums\PublishStatus;
use App\Enums\SandboxStatus;
use App\Jobs\CreateSandbox;
use App\Jobs\UpdateSandbox;
use App\Models\Attachment;
use App\Models\Message;
use App\Models\Project;
use App\Models\Task;
use App\Sandbox\Agents\Conversation;
use App\Sandbox\Agents\ModelCatalog;
use App\Sandbox\Gateway;
use App\Sandbox\Publishing\Publishers;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxProvider;
use App\Sandbox\SandboxUpdater;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The chat + preview workspace, on one of the project's conversations: its main chat, a task's, or a new task's (TASK-001).
 */
trait RendersWorkspace
{
    /**
     * @param  bool  $newTask  Show an empty chat whose first message starts a new task.
     */
    protected function renderWorkspace(Request $request, Project $project, Conversation $conversation, bool $newTask = false): Response
    {
        $catalog = app(ModelCatalog::class);
        $gateway = app(Gateway::class);

        $task = $conversation instanceof Task ? $conversation : null;

        // Reloads from a tab the owner isn't looking at leave replies unread, so a finished agent still shows as waiting (PRJ-008).
        if ($project->user_id === $request->user()->id && ! $newTask && ! $request->hasHeader('X-Onedrop-Unseen')) {
            $read = $task ?? $project;
            $read::withoutTimestamps(fn () => $read->update(['read_at' => now()]));
        }

        $this->updateOutdatedSandbox($project, app(SandboxUpdater::class));
        $this->renewSandboxAddresses($project, app(SandboxProvider::class));

        // A task with its own copy of the app shows that copy's preview, shell and files (TASK-003).
        $sandbox = $task && Task::getsCopies() ? $task->sandbox()->first() : $project->sandbox;
        $sandbox?->wake(app(SandboxProvider::class));
        $open = fn (string $kind, string $path = '/') => route('projects.gateway.open', [$project, $kind, ...($sandbox?->task_id ? ['task' => $sandbox->task_id] : []), ...($path !== '/' ? ['path' => $path] : [])]);
        $messages = $newTask ? collect() : $conversation->messages()->with('attachments')->get();
        $queued = $newTask ? collect() : $conversation->queuedMessages()->with('attachments')->get();

        return Inertia::render('projects/show', [
            'project' => $project->only('id', 'name', 'status', 'autofix'),
            'task' => $task ? [
                ...$task->only('id', 'title', 'description', 'stage', 'status', 'sync_status', 'sync_error'),
                'own_copy' => Task::getsCopies(),
                'has_copy' => $sandbox !== null,
                'applied_at' => $task->applied_at?->toIso8601String(),
            ] : null,
            'newTask' => $newTask,
            'agent' => ($selection = $catalog->selectionFor($project)) ? $catalog->describe($selection, $catalog->harnessFor($project)) : null,
            // Claude Code runs on the owner's own Claude sign-in in the sandbox (AI-005).
            'claudeSubscription' => $project->user->agentConnections()
                ->where('provider', AgentProvider::Claude)
                ->where('credential_type', CredentialType::ClaudeLogin)
                ->exists(),
            'publication' => [
                'status' => $project->publish_status,
                'visibility' => $project->publish_visibility,
                'url' => $project->published_url,
                'published_at' => $project->published_at?->toIso8601String(),
                'published_by' => $project->publisher?->name,
                'error' => $project->publish_error,
                'login_url' => $project->publish_status === PublishStatus::Publishing ? $project->publish_login_url : null,
                'target' => $project->publish_target,
                'audience' => app(Publishers::class)->audience($project),
                'targets' => app(Publishers::class)->options(),
                // Nowhere to publish at all (each target's own reason is in targets).
                'unavailable' => collect(app(Publishers::class)->options())->every(fn (array $option) => $option['unavailable'] !== null)
                    ? app(Publishers::class)->options()[0]['unavailable']
                    : null,
            ],
            'sharing' => $this->sharingProps($project),
            'sandbox' => $sandbox ? [
                ...$sandbox->only('status', 'error'),
                'updating' => $sandbox->task_id === null && SandboxUpdater::isUpdating($project),
                // On servers the browser goes through the gateway (which signs it in to that address), not the sandbox's local ports.
                'preview_url' => $sandbox->preview_url ? ($gateway->enabled() ? $open('preview') : $sandbox->preview_url) : null,
                'shell_url' => $sandbox->shell_url ? ($gateway->enabled() ? $open('shell') : $sandbox->shell_url) : null,
                // The Shell tab opened on Claude Code's own sign-in (see docker/sandbox/shell-entry; AI-005).
                'claude_login_url' => $sandbox->shell_url ? ($gateway->enabled() ? $open('shell', '/?arg=claude-login') : self::withShellArgument($sandbox->shell_url, 'claude-login')) : null,
            ] : null,
            'queued' => $queued->map(fn (Message $message): array => [
                ...$message->only('id', 'content'),
                'attachments' => $message->attachments->map(fn (Attachment $attachment): array => $this->attachmentProps($project, $attachment)),
            ]),
            'messages' => $messages->map(fn (Message $message): array => [
                'id' => $message->id,
                'role' => $message->role,
                'content' => $message->content,
                'attachments' => $message->attachments->map(fn (Attachment $attachment): array => $this->attachmentProps($project, $attachment)),
                'created_at' => $message->created_at?->toIso8601String(),
            ]),
        ]);
    }

    /**
     * The Share panel: the share page when the project is shared, or what sharing would start from (SHARE-001).
     *
     * @return array<string, mixed>
     */
    protected function sharingProps(Project $project): array
    {
        $share = $project->share;

        return [
            'shared' => $share !== null,
            'prompt' => $share->prompt ?? (string) $project->prompt,
            'page_path' => $share->page_path ?? '/',
            'url' => $share?->url(),
            'card_url' => $share?->cardUrl(),
            'card_status' => $share?->card_status,
            'card_error' => $share?->card_error,
            'views' => $share->views ?? 0,
            'remixes' => $share->remixes ?? 0,
        ];
    }

    /**
     * A shell address that starts on a docker/sandbox/shell-entry action (ttyd's `?arg=`), keeping any
     * query it already has, e.g. Runtime's `?runtime_preview_token=`.
     */
    protected static function withShellArgument(string $url, string $argument): string
    {
        $fragment = parse_url($url, PHP_URL_FRAGMENT);
        $url = is_string($fragment) ? substr($url, 0, -strlen($fragment) - 1) : $url;
        $query = parse_url($url, PHP_URL_QUERY);
        $base = is_string($query) ? substr($url, 0, -strlen($query) - 1) : $url;
        $path = parse_url($base, PHP_URL_PATH);

        return ($path === null ? $base.'/' : $base).'?'.ltrim((is_string($query) ? $query.'&' : '').http_build_query(['arg' => $argument]), '&');
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
     * current guides and tools. Checked at most once a minute per project; never while an agent works.
     */
    protected function updateOutdatedSandbox(Project $project, SandboxUpdater $updater): void
    {
        if ($project->mainSandboxBusy() || ! Cache::add("sandbox-outdated-check:{$project->id}", true, 60)) {
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

        if ($sandbox === null || ! in_array($sandbox->provider, ['blaxel', 'runtime'], true) || $sandbox->status !== SandboxStatus::Running || ! $sandbox->external_id
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
