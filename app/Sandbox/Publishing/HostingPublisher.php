<?php

namespace App\Sandbox\Publishing;

use App\Enums\DeploymentStatus;
use App\Enums\HostedServiceKind;
use App\Enums\PublishVisibility;
use App\Models\Project;
use App\Sandbox\Hosting\CloudflareApi;
use App\Sandbox\Hosting\Deployer;
use App\Sandbox\Hosting\FlyApi;
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
     * Stop the app (its machine, or its site), keeping its data and its Fly app for the next deploy.
     */
    public function stop(Project $project): void
    {
        try {
            $app = $project->hostedServices()->where('kind', HostedServiceKind::App)->first();

            if ($app && isset($app->details['machine'])) {
                (new FlyApi($this->services->accountFor($app)))->deleteMachine($app->name, $app->details['machine']);
                $app->update(['details' => array_diff_key($app->details, ['machine' => true])]);
            }

            $site = $project->hostedServices()->where('kind', HostedServiceKind::Site)->first();

            if ($site) {
                (new CloudflareApi($this->services->accountFor($site)))->deleteSite($site->name);
                $site->delete();
            }
        } catch (HostingException $e) {
            throw new PublishException($e->getMessage(), previous: $e);
        }
    }
}
