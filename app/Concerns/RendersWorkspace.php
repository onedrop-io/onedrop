<?php

namespace App\Concerns;

use App\Enums\AbuseReviewStatus;
use App\Enums\AgentProvider;
use App\Enums\CredentialType;
use App\Enums\DeploymentStatus;
use App\Enums\MessageRole;
use App\Enums\PublishStatus;
use App\Enums\PublishTarget;
use App\Enums\SandboxStatus;
use App\Http\Middleware\UseDesktopToken;
use App\Jobs\CreateSandbox;
use App\Models\Attachment;
use App\Models\Deployment;
use App\Models\Message;
use App\Models\Project;
use App\Models\Task;
use App\Sandbox\Agents\Conversation;
use App\Sandbox\Agents\MessageChecks;
use App\Sandbox\Agents\ModelCatalog;
use App\Sandbox\Agents\PlainActivity;
use App\Sandbox\Gateway;
use App\Sandbox\Hosting\Deployer;
use App\Sandbox\Hosting\HostedServices;
use App\Sandbox\Hosting\MachineSizes;
use App\Sandbox\Publishing\Publishers;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxProvider;
use App\Sandbox\SandboxUpdater;
use App\Sandbox\WorkspaceSsh;
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

        $this->renewSandboxAddresses($project, app(SandboxProvider::class));

        // A task with its own copy of the app shows that copy's preview, shell and files (TASK-003).
        $sandbox = $task && Task::getsCopies() ? $task->sandbox()->first() : $project->sandbox;
        $sandbox?->wake(app(SandboxProvider::class));
        // The desktop app (DESK-001) has no session for that address to sign in with: it gets the sandbox address's own
        // sign-in, good for a minute, which every load of the workspace renews, for a partitioned cookie.
        $open = fn (string $kind, string $path = '/') => $sandbox && UseDesktopToken::from($request)
            ? $gateway->enterUrl($sandbox, $kind, $request->user(), $path, partitioned: true)
            : route('projects.gateway.open', [$project, $kind, ...($sandbox?->task_id ? ['task' => $sandbox->task_id] : []), ...($path !== '/' ? ['path' => $path] : [])]);
        $messages = $newTask ? collect() : $conversation->messages()->with('attachments')->get();
        $queued = $newTask ? collect() : $conversation->queuedMessages()->with('attachments')->get();

        return Inertia::render('projects/show', [
            // A message the user just sent that's waiting for their answer (SECRET-002, REQ-003), shown once. Being a
            // closure, partial reloads that don't ask for it leave it for the page load after the send.
            'held' => fn () => $newTask ? null : MessageChecks::pullPrompt($request->user(), $conversation),
            'project' => [
                ...$project->only('id', 'name', 'status', 'autofix', 'track_requirements'),
                // A message that failed because Claude Code wasn't signed in, waiting to run again (AI-005).
                'waiting_for_sign_in' => $project->sign_in_retry_message_id !== null,
                // The desktop app opens the owner's projects in their editor by this name (DESK-008).
                'editor_alias' => $project->user_id === $request->user()?->id ? WorkspaceSsh::hostAlias($project) : null,
            ],
            'task' => $task ? [
                ...$task->only('id', 'title', 'description', 'stage', 'status', 'sync_status', 'sync_error'),
                'waiting_for_sign_in' => $task->sign_in_retry_message_id !== null,
                'own_copy' => Task::getsCopies(),
                'has_copy' => $sandbox !== null,
                'applied_at' => $task->applied_at?->toIso8601String(),
            ] : null,
            'newTask' => $newTask,
            // With Auto (AGT-011), the provider and its default model, which a message runs on when Jev can't size it.
            'agent' => ($selection = $catalog->selectionFor($project)) ? [...$catalog->describe($selection, $catalog->harnessFor($project)), 'auto' => $project->agent_auto] : null,
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
                // What login_url is for: approving the node ("login"), or turning on Funnel or HTTPS for the tailnet.
                'waiting_for' => $project->publish_status === PublishStatus::Publishing ? ($project->publish_waiting_for ?? 'login') : null,
                'target' => $project->publish_target,
                'audience' => app(Publishers::class)->audience($project),
                'targets' => app(Publishers::class)->options($project),
                // Nowhere to publish at all (each target's own reason is in targets).
                'unavailable' => collect(app(Publishers::class)->options($project))->every(fn (array $option) => $option['unavailable'] !== null)
                    ? app(Publishers::class)->options($project)[0]['unavailable']
                    : null,
                'hosting' => $this->hostingProps($project),
            ],
            'sharing' => $this->sharingProps($project),
            'sandbox' => $sandbox ? [
                ...$sandbox->only('status', 'error'),
                'updating' => $sandbox->task_id === null && SandboxUpdater::isUpdating($project),
                // On servers the browser goes through the gateway (which signs it in to that address), not the sandbox's local ports.
                'preview_url' => $sandbox->preview_url ? ($gateway->enabled() ? $open('preview') : $sandbox->preview_url) : null,
                'shell_url' => $sandbox->shell_url ? ($gateway->enabled() ? $open('shell') : $sandbox->shell_url) : null,
                // Whether shell_url is the gateway's address, which takes the shell's own address as its `path` (FILE-005).
                'shell_via_gateway' => $gateway->enabled(),
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
                // The step in plain words, for Simple mode (PRJ-013).
                'plain' => $message->role === MessageRole::Activity ? PlainActivity::describe($message->content) : null,
                'attachments' => $message->attachments->map(fn (Attachment $attachment): array => $this->attachmentProps($project, $attachment)),
                'created_at' => $message->created_at?->toIso8601String(),
            ]),
        ]);
    }

    /**
     * The project's latest deploy to hosting and what it has there (HOST-001, HOST-002), or null when it never had any.
     *
     * @return array<string, mixed>|null
     */
    protected function hostingProps(Project $project): ?array
    {
        $deployment = $project->deployments()->latest('id')->first();
        $services = app(HostedServices::class)->describe($project);

        if ($deployment === null && $services === []) {
            return null;
        }

        $hosted = $project->publish_target === PublishTarget::Hosting && $project->publish_status !== null;

        $live = $project->deployments()->where('status', DeploymentStatus::Live)->latest('id')->first();

        return [
            'deployment' => $deployment ? [
                ...$deployment->only('number', 'kind', 'status', 'step', 'url', 'log', 'error'),
                'created_at' => $deployment->created_at?->toIso8601String(),
            ] : null,
            // What the sandbox has that the hosted app doesn't yet (HOST-004).
            'changes' => $hosted ? $project->hosting_changes : null,
            // Recent deploys of an app with a server, to put one back (HOST-005); the live one first.
            'history' => $hosted ? $project->deployments()
                ->where('status', DeploymentStatus::Live)->where('kind', 'server')->whereNotNull('image')
                ->latest('id')->limit(5)->get()
                ->map(fn (Deployment $past) => [
                    'id' => $past->id,
                    'number' => $past->number,
                    'commit' => $past->commit ? substr($past->commit, 0, 7) : null,
                    'finished_at' => $past->finished_at?->toIso8601String(),
                    'live' => $past->id === $live?->id,
                ])->values()->all() : [],
            'auto_deploy' => $project->auto_deploy,
            // Move to Postgres (HOST-009): the SQLite file it would move, and whether a move is waiting for the next deploy.
            'sqlite' => $hosted ? app(Deployer::class)->hostedSqlite($project) : null,
            'moving_to_postgres' => $project->hosting_sqlite_import !== null,
            // The machine it runs on, and the sizes on offer (HOST-010).
            'size' => $project->hosting_size ?? MachineSizes::DEFAULT,
            'sizes' => MachineSizes::options(),
            'services' => $services,
            // Its data can be deleted once it isn't published there.
            'can_delete' => ! $hosted && $services !== [],
        ];
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
            // Held for a platform admin's review, or taken down, on the hosted install (PUB-003).
            'review' => match ($project->abuseReview?->status) {
                AbuseReviewStatus::Held => 'held',
                AbuseReviewStatus::TakenDown => 'taken_down',
                default => null,
            },
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
