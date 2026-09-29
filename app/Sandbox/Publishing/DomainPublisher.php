<?php

namespace App\Sandbox\Publishing;

use App\Enums\PublishVisibility;
use App\Models\Project;
use App\Sandbox\Gateway;

/**
 * Publishes a project at its own address on the server's domain, https://<name>-<id>.<domain>, through the gateway
 * that already serves previews: Caddy asks SandboxGatewayController, which lets anyone through to a public app and
 * people signed in to OneDrop through to a private one. Nothing to start or stop: the project's publish status is
 * what the gateway checks.
 */
class DomainPublisher implements Publisher
{
    public function __construct(protected Gateway $gateway) {}

    public function unavailableReason(): ?string
    {
        return match (true) {
            ! $this->gateway->enabled() => 'Publishing to your own domain needs a server install with a domain.',
            $this->gateway->viaWorker() => "Publishing to your own domain isn't available with the Cloudflare preview gateway yet.",
            default => null,
        };
    }

    public function start(Project $project): void {}

    public function confirm(Project $project, PublishVisibility $visibility): ?string
    {
        return 'https://'.$this->gateway->publishedHost($project);
    }

    public function stop(Project $project): void {}
}
