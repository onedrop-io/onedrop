<?php

namespace App\Sandbox\Publishing;

use App\Enums\DeploymentStatus;
use App\Enums\PublishVisibility;
use App\Models\Project;
use App\Sandbox\Hosting\Deployer;
use App\Sandbox\Hosting\HostedServices;
use App\Sandbox\Hosting\HostingException;

/**
 * Publishes a project by deploying it off its sandbox (HOST-001): Deployer does the work in queued steps, and
 * confirm() reports the latest deployment. Unpublishing stops the app but keeps its data (HOST-002).
 */
class HostingPublisher implements Publisher
{
    /** A deploy builds an image, so it gets far longer than a tailnet node to come up (at ConfirmPublication::RETRY_SECONDS each). */
    public const CONFIRM_ATTEMPTS = 1200;

    public function __construct(protected Deployer $deployer, protected HostedServices $services) {}

    public function unavailableReason(): ?string
    {
        return null;
    }

    public function start(Project $project): void
    {
        $this->deployer->start($project, $project->publisher);
    }

    public function confirm(Project $project, PublishVisibility $visibility): ?string
    {
        $deployment = $this->deployer->latest($project);

        return match ($deployment?->status) {
            DeploymentStatus::Live => $deployment->url,
            DeploymentStatus::Failed => throw new PublishException($deployment->error ?? 'The deploy failed.'),
            default => null,
        };
    }

    public function confirmAttempts(): int
    {
        return self::CONFIRM_ATTEMPTS;
    }

    /**
     * Stop the app (its machine, its site, or its Vercel project), keeping its data and its Fly app for the next deploy.
     */
    public function stop(Project $project): void
    {
        try {
            $this->services->takeDown($project);
        } catch (HostingException $e) {
            throw new PublishException($e->getMessage(), previous: $e);
        }
    }
}
