<?php

namespace App\Sandbox\Publishing;

use App\Enums\PublishVisibility;
use App\Models\Project;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Publishes a Docker sandbox through its own Tailscale node: a sidecar container
 * sharing the sandbox's network, so the project gets https://<hostname>.<tailnet>.ts.net.
 * Private uses `tailscale serve` (tailnet only); public uses `tailscale funnel`.
 *
 * With TAILSCALE_AUTHKEY the node joins unattended. Without it, Tailscale gives a
 * sign-in link to approve the node in the browser; the node's identity is kept in a
 * per-project volume so approval is needed only once.
 */
class TailscalePublisher implements Publisher
{
    /**
     * @param  array{authkey: ?string, image: string}  $config
     * @param  int  $targetPort  the sandbox port to publish: the host-rewriting proxy in front of the app
     */
    public function __construct(protected array $config, protected int $targetPort) {}

    public function unavailableReason(): ?string
    {
        return config('sandbox.provider') !== 'docker'
            ? 'Tailscale publishing only works with local Docker sandboxes.'
            : null;
    }

    public function start(Project $project): void
    {
        $sandbox = $project->sandbox;
        $name = $this->containerName($project);

        if ($this->isRunning($name)) {
            return;
        }

        Process::run(['docker', 'rm', '--force', $name]);

        $command = [
            'docker', 'run', '--detach',
            '--name', $name,
            '--label', 'onedrop.sandbox.publisher=1',
            '--network', "container:{$sandbox->external_id}",
            '--volume', "{$name}-state:/var/lib/tailscale",
            '--env', "TS_HOSTNAME={$project->publishHostname()}",
            '--env', 'TS_USERSPACE=true',
            '--env', 'TS_STATE_DIR=/var/lib/tailscale',
        ];

        if ($this->usesAuthKey()) {
            array_push($command, '--env', 'TS_AUTHKEY');
        }

        $command[] = $this->config['image'];

        $result = Process::env(array_filter(['TS_AUTHKEY' => $this->config['authkey']]))->timeout(60)->run($command);

        if ($result->failed()) {
            throw new PublishException($this->explain($result));
        }
    }

    public function confirm(Project $project, PublishVisibility $visibility): ?string
    {
        $name = $this->containerName($project);
        $status = Process::timeout(15)->run(['docker', 'exec', $name, 'tailscale', 'status', '--json']);

        if ($status->failed()) {
            return null;
        }

        $state = json_decode($status->output(), true) ?: [];

        if (($state['BackendState'] ?? null) === 'NeedsLogin' && ! empty($state['AuthURL'] ?? null)) {
            throw $this->usesAuthKey()
                ? new PublishException('Tailscale rejected the auth key. Create a new reusable auth key and update TAILSCALE_AUTHKEY.')
                : new PublishNeedsLogin($state['AuthURL']);
        }

        $dnsName = rtrim((string) ($state['Self']['DNSName'] ?? ''), '.');

        if (($state['BackendState'] ?? null) !== 'Running' || $dnsName === '') {
            return null;
        }

        Process::timeout(15)->run(['docker', 'exec', $name, 'tailscale', 'serve', 'reset']);

        $command = $visibility === PublishVisibility::Public ? 'funnel' : 'serve';
        $result = Process::timeout(30)->run(['docker', 'exec', $name, 'tailscale', $command, '--bg', (string) $this->targetPort]);

        if ($result->failed()) {
            throw new PublishException($this->explain($result));
        }

        return "https://{$dnsName}";
    }

    public function stop(Project $project): void
    {
        Process::timeout(30)->run(['docker', 'rm', '--force', $this->containerName($project)]);
    }

    /**
     * Whether nodes join with an auth key (unattended) rather than a browser sign-in.
     */
    public function usesAuthKey(): bool
    {
        return filled($this->config['authkey']);
    }

    /**
     * The sidecar container's name for a project.
     */
    public function containerName(Project $project): string
    {
        return "onedrop-publish-{$project->id}";
    }

    protected function isRunning(string $name): bool
    {
        $result = Process::timeout(15)->run(['docker', 'inspect', '--format', '{{.State.Running}}', $name]);

        return $result->successful() && trim($result->output()) === 'true';
    }

    protected function explain(ProcessResult $result): string
    {
        $error = trim($result->errorOutput()."\n".$result->output());

        return match (true) {
            Str::contains($error, ['Funnel not available', 'funnel is not enabled', 'nodeAttrs'], ignoreCase: true) => 'Public publishing needs Tailscale Funnel enabled for this tailnet (add the "funnel" node attribute for the tag in your access policy). Private publishing still works.',
            Str::contains($error, ['HTTPS', 'certificate'], ignoreCase: true) => 'Turn on MagicDNS and HTTPS certificates in the Tailscale admin console, then republish.',
            Str::contains($error, ['Cannot connect to the Docker daemon']) => 'Docker is not running. Start Docker and try again.',
            Str::contains($error, ['No such container']) => "The project's sandbox isn't running.",
            $error === '' => 'Publishing failed with no output.',
            default => 'Publishing failed: '.Str::limit(strtok($error, "\n"), 200),
        };
    }
}
