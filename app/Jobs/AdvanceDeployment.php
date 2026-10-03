<?php

namespace App\Jobs;

use App\Models\Deployment;
use App\Sandbox\Hosting\Deployer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs a deployment's current step and queues the next (HOST-001). Waiting on a build or a machine is modeled as short
 * delayed checks, not a sleeping job.
 */
class AdvanceDeployment implements ShouldQueue
{
    use Queueable;

    /** Packing runs the app's own build in its sandbox, which can take a while. */
    public int $timeout = 1800;

    public int $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(public Deployment $deployment) {}

    public function handle(Deployer $deployer): void
    {
        $delay = $deployer->advance($this->deployment->fresh() ?? $this->deployment);

        if ($delay !== null) {
            self::dispatch($this->deployment)->delay(now()->addSeconds($delay));
        }
    }

    /**
     * Something crashed mid-step (e.g. it timed out): fail visibly rather than stay "Deploying…" forever.
     */
    public function failed(?Throwable $exception): void
    {
        $deployment = $this->deployment->fresh();

        if ($deployment !== null && $deployment->finished_at === null) {
            app(Deployer::class)->fail($deployment, 'The deploy stopped unexpectedly. Try again.');
        }
    }
}
