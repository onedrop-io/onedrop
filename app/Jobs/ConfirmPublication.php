<?php

namespace App\Jobs;

use App\Enums\PublishStatus;
use App\Models\Project;
use App\Sandbox\Publishing\Publishers;
use App\Sandbox\Publishing\PublishException;
use App\Sandbox\Publishing\PublishNeedsLogin;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Polls the publisher until the endpoint is up (a node joining a tailnet takes a few seconds).
 * Waiting is modeled as short retries, not a sleeping job.
 */
class ConfirmPublication implements ShouldQueue
{
    use Queueable;

    public const ATTEMPTS = 20;

    /** How long to wait for someone to approve the node in the browser (at RETRY_SECONDS each). */
    public const LOGIN_ATTEMPTS = 300;

    public const RETRY_SECONDS = 2;

    /**
     * Create a new job instance.
     */
    public function __construct(public Project $project, public int $attempt = 1) {}

    public function handle(Publishers $publishers): void
    {
        $project = $this->project->fresh();

        // Unpublished (or republished) meanwhile: this confirmation is stale.
        if ($project?->publish_status !== PublishStatus::Publishing) {
            return;
        }

        try {
            $url = $publishers->forProject($project)->confirm($project, $project->publish_visibility);
        } catch (PublishNeedsLogin $e) {
            if ($project->publish_login_url !== $e->loginUrl) {
                $project->update(['publish_login_url' => $e->loginUrl]);
            }

            $this->retryOrGiveUp($project, self::LOGIN_ATTEMPTS, 'Nobody approved the project in Tailscale in time. Publish again to get a new sign-in link.');

            return;
        } catch (PublishException $e) {
            $project->update(['publish_status' => PublishStatus::Failed, 'publish_error' => $e->getMessage()]);

            return;
        }

        if ($url !== null) {
            $project->update([
                'publish_status' => PublishStatus::Live,
                'published_url' => $url,
                'published_at' => now(),
                'publish_error' => null,
                'publish_login_url' => null,
            ]);

            return;
        }

        $this->retryOrGiveUp($project, self::ATTEMPTS, 'Tailscale took too long to come up. Try again.');
    }

    /**
     * Check again shortly, or mark the publication failed after too many tries.
     */
    protected function retryOrGiveUp(Project $project, int $maxAttempts, string $reason): void
    {
        if ($this->attempt >= $maxAttempts) {
            $project->update([
                'publish_status' => PublishStatus::Failed,
                'publish_error' => $reason,
                'publish_login_url' => null,
            ]);

            return;
        }

        self::dispatch($project, $this->attempt + 1)->delay(now()->addSeconds(self::RETRY_SECONDS));
    }
}
