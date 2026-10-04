<?php

namespace App\Jobs;

use App\Enums\PublishStatus;
use App\Models\Project;
use App\Sandbox\Domains\ProjectDomains;
use App\Sandbox\Publishing\HostingPublisher;
use App\Sandbox\Publishing\Publishers;
use App\Sandbox\Publishing\PublishException;
use App\Sandbox\Publishing\PublishNeedsFeature;
use App\Sandbox\Publishing\PublishNeedsLogin;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Polls the publisher until the endpoint is up (a node joining a tailnet takes a few seconds).
 * Waiting is modeled as short retries, not a sleeping job.
 */
class ConfirmPublication implements ShouldQueue
{
    use Queueable;

    public const ATTEMPTS = 20;

    /** How long to wait for someone to approve the node, or turn on Funnel or HTTPS, in the browser (at RETRY_SECONDS each). */
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

        $publisher = $publishers->forProject($project);

        try {
            $url = $publisher->confirm($project, $project->publish_visibility);
        } catch (PublishNeedsLogin $e) {
            $this->waitFor($project, 'login', $e->loginUrl);
            $this->retryOrGiveUp($project, self::LOGIN_ATTEMPTS, 'Nobody approved the project in Tailscale in time. Publish again to get a new sign-in link.');

            return;
        } catch (PublishNeedsFeature $e) {
            $this->waitFor($project, $e->feature, $e->url);
            $this->retryOrGiveUp($project, self::LOGIN_ATTEMPTS, $e->feature === PublishNeedsFeature::Funnel
                ? 'Tailscale Funnel still isn\'t on for this tailnet. Turn it on and publish again, or publish privately.'
                : 'HTTPS certificates still aren\'t on for this tailnet. Turn them on in the Tailscale admin console and publish again.');

            return;
        } catch (PublishException $e) {
            $project->update(['publish_status' => PublishStatus::Failed, 'publish_error' => $e->getMessage()]);

            return;
        }

        if ($url !== null) {
            $project->update([
                'publish_status' => PublishStatus::Live,
                'published_url' => $url,
                'published_default_url' => $url,
                'published_at' => now(),
                'publish_error' => null,
                'publish_login_url' => null,
                'publish_waiting_for' => null,
            ]);

            // Its custom domains follow it here, and an active primary one becomes its URL (DOM-002, DOM-004).
            app(ProjectDomains::class)->sync($project);

            return;
        }

        $this->retryOrGiveUp($project, $publisher->confirmAttempts(), $publisher instanceof HostingPublisher
            ? 'The deploy took too long. Try again.'
            : 'Tailscale took too long to come up. Try again.');
    }

    /**
     * Something crashed mid-check (e.g. a command timed out, or the database was locked): check again while attempts
     * are left, since the endpoint (a deploy, a tailnet node) carries on without this job. After the last one, fail
     * visibly rather than stay "Publishing…" forever.
     */
    public function failed(?Throwable $exception): void
    {
        $project = $this->project->fresh();

        if ($project?->publish_status !== PublishStatus::Publishing) {
            return;
        }

        if ($this->attempt < app(Publishers::class)->forProject($project)->confirmAttempts()) {
            self::dispatch($project, $this->attempt + 1)->delay(now()->addSeconds(self::RETRY_SECONDS));

            return;
        }

        $project->update([
            'publish_status' => PublishStatus::Failed,
            'publish_error' => 'Publishing stopped unexpectedly. Try again.',
            'publish_login_url' => null,
            'publish_waiting_for' => null,
        ]);
    }

    /**
     * Show what publishing is waiting on (someone approving the node, or turning on a tailnet feature) and its link.
     */
    protected function waitFor(Project $project, string $what, string $url): void
    {
        if ($project->publish_login_url !== $url || $project->publish_waiting_for !== $what) {
            $project->update(['publish_login_url' => $url, 'publish_waiting_for' => $what]);
        }
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
                'publish_waiting_for' => null,
            ]);

            return;
        }

        self::dispatch($project, $this->attempt + 1)->delay(now()->addSeconds(self::RETRY_SECONDS));
    }
}
