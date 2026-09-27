<?php

namespace App\Jobs;

use App\Enums\SandboxStatus;
use App\Models\Project;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxProvider;
use App\Sandbox\SandboxSpec;
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
    public function handle(SandboxProvider $provider): void
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
                'ZAP_PROJECT_NAME' => $this->project->name,
                ...($connection?->sandboxEnvironment() ?? []),
            ],
            port: $port,
            shellPort: config('sandbox.shell_port'),
            proxyPort: config('sandbox.proxy_port'),
        );

        try {
            $id = $provider->create($spec);

            $sandbox->update([
                'external_id' => $id,
                'status' => SandboxStatus::Running,
                // Through the host-rewriting proxy, so any framework accepts the request as localhost.
                'preview_url' => $provider->previewUrl($id, config('sandbox.proxy_port')) ?? $provider->previewUrl($id, $port),
                'shell_url' => $provider->previewUrl($id, config('sandbox.shell_port')),
                'error' => null,
            ]);
        } catch (SandboxException $e) {
            $sandbox->update([
                'status' => SandboxStatus::Failed,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
