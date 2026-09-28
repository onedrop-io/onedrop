<?php

namespace App\Jobs;

use App\Enums\SandboxStatus;
use App\Models\Project;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxProvider;
use App\Sandbox\SandboxSpec;
use App\Sandbox\WorkspaceSsh;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CreateSandbox implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public Project $project) {}

    /**
     * Start the project's sandbox with the owner's AI credential injected.
     */
    public function handle(SandboxProvider $provider, WorkspaceSsh $ssh): void
    {
        $sandbox = $this->project->sandbox()->firstOrCreate([], [
            'provider' => config('sandbox.provider'),
            'status' => SandboxStatus::Creating,
        ]);

        $port = config('sandbox.port');
        $connection = $this->project->user->agentConnections()->firstWhere('is_default', true);

        $spec = new SandboxSpec(
            name: "zap-project-{$this->project->id}-".strtolower(str()->random(6)),
            env: [
                'APP_PROJECT_NAME' => $this->project->name,
                ...($connection?->sandboxEnvironment() ?? []),
            ],
            port: $port,
            shellPort: config('sandbox.shell_port'),
            proxyPort: config('sandbox.proxy_port'),
            sshPort: config('sandbox.ssh_port'),
            storageKey: "project-{$this->project->id}",
        );

        try {
            $id = $provider->create($spec);

            $sandbox->update([
                'external_id' => $id,
                'status' => SandboxStatus::Running,
                // Through the host-rewriting proxy, so any framework accepts the request as localhost.
                'preview_url' => $provider->previewUrl($id, config('sandbox.proxy_port')) ?? $provider->previewUrl($id, $port),
                'shell_url' => $provider->previewUrl($id, config('sandbox.shell_port')),
                'ssh_address' => $this->address($provider->previewUrl($id, config('sandbox.ssh_port'))),
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
     * "host:port" from a published port's URL.
     */
    protected function address(?string $url): ?string
    {
        $host = $url ? parse_url($url, PHP_URL_HOST) : null;
        $port = $url ? parse_url($url, PHP_URL_PORT) : null;

        return $host && $port ? "{$host}:{$port}" : null;
    }
}
