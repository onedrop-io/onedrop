<?php

namespace App\Jobs;

use App\Enums\SandboxStatus;
use App\Models\Project;
use App\Models\Task;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxProvider;
use App\Sandbox\SandboxSpec;
use App\Sandbox\WorkspaceSsh;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\URL;

class CreateSandbox implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public Project $project, public ?Task $task = null) {}

    /**
     * Start the project's sandbox (or, with a task, the task's own copy's; TASK-003) with the owner's AI credential injected.
     */
    public function handle(SandboxProvider $provider, WorkspaceSsh $ssh): void
    {
        $sandbox = ($this->task?->sandbox() ?? $this->project->sandbox())->firstOrCreate([], [
            'provider' => config('sandbox.provider'),
            'status' => SandboxStatus::Creating,
        ]);

        $port = config('sandbox.port');
        $connection = $this->project->user->agentConnections()->firstWhere('is_default', true);

        $spec = new SandboxSpec(
            name: "onedrop-project-{$this->project->id}-".($this->task ? "task-{$this->task->id}-" : '').strtolower(str()->random(6)),
            env: [
                'APP_PROJECT_NAME' => $this->project->name,
                // Where the file watcher reports added, removed or renamed files (FILE-004).
                'APP_FILES_CHANGED_URL' => rtrim(config('sandbox.callback_url'), '/').URL::signedRoute('sandbox-events.files', $sandbox, absolute: false),
                // Where `ask` in the shell gets the project's AI for each question (SBX-012).
                'ONEDROP_AI_URL' => rtrim(config('sandbox.callback_url'), '/').URL::signedRoute('sandbox-ai.show', $sandbox, absolute: false),
                ...($connection?->sandboxEnvironment() ?? []),
            ],
            port: $port,
            shellPort: config('sandbox.shell_port'),
            proxyPort: config('sandbox.proxy_port'),
            sshPort: config('sandbox.ssh_port'),
            // A task's copy keeps its App Storage buckets apart from Main's.
            storageKey: "project-{$this->project->id}".($this->task ? "-task-{$this->task->id}" : ''),
            // One Claude sign-in for all of the owner's sandboxes; only they (and site admins) can open a project (AI-005).
            claudeLoginKey: "user-{$this->project->user_id}",
        );

        try {
            $id = $provider->create($spec);

            $sandbox->update([
                // The provider it was made on, even when this reuses a record from another one.
                'provider' => config('sandbox.provider'),
                'external_id' => $id,
                'status' => SandboxStatus::Running,
                ...self::addresses($provider, $id),
                'error' => null,
            ]);
        } catch (SandboxException $e) {
            $sandbox->update([
                'status' => SandboxStatus::Failed,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        try {
            $ssh->sync($sandbox);
        } catch (SandboxException) {
            // SSH is optional; the Developer → SSH page syncs the keys again when opened.
        }
    }

    /**
     * Where the browser reaches a sandbox's preview and shell, and SSH clients its SSH server.
     *
     * @return array{preview_url: ?string, shell_url: ?string, ssh_address: ?string}
     *
     * @throws SandboxException
     */
    public static function addresses(SandboxProvider $provider, string $id): array
    {
        return [
            // Through the host-rewriting proxy, so any framework accepts the request as localhost.
            'preview_url' => $provider->previewUrl($id, config('sandbox.proxy_port')) ?? $provider->previewUrl($id, config('sandbox.port')),
            'shell_url' => $provider->previewUrl($id, config('sandbox.shell_port')),
            'ssh_address' => self::address($provider->previewUrl($id, config('sandbox.ssh_port'))),
        ];
    }

    /**
     * "host:port" from a published port's URL.
     */
    protected static function address(?string $url): ?string
    {
        $host = $url ? parse_url($url, PHP_URL_HOST) : null;
        $port = $url ? parse_url($url, PHP_URL_PORT) : null;

        return $host && $port ? "{$host}:{$port}" : null;
    }
}
