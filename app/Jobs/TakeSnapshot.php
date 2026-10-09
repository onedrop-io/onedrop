<?php

namespace App\Jobs;

use App\Enums\SandboxStatus;
use App\Models\Project;
use App\Models\SandboxMove;
use App\Sandbox\ProjectSnapshots;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxProvider;
use App\Sandbox\SandboxWaitLimit;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Sleep;

/**
 * Takes a snapshot of a project's main sandbox (SBX-009): after an agent turn, and once it has gone unused. The sandbox
 * packs and uploads it in the background; each attempt checks on it for up to STEP_SECONDS, then puts itself back,
 * so no attempt nears a Flex queue job's 90 seconds however large the project.
 */
#[DeleteWhenMissingModels]
class TakeSnapshot implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const STEP_SECONDS = 50;

    public int $timeout = 85;

    public int $maxExceptions = 1;

    /**
     * @param  bool  $suspendAfter  suspend the sandbox once it's taken (it went unused; SBX-007)
     */
    public function __construct(public Project $project, public string $reason, public bool $suspendAfter = false) {}

    public function uniqueId(): string
    {
        return (string) $this->project->id;
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(45);
    }

    public function handle(ProjectSnapshots $snapshots, SandboxWaitLimit $limit, SandboxProvider $provider): void
    {
        $limit->start(MoveProjectSandbox::WAIT_SECONDS);
        $until = now()->addSeconds(self::STEP_SECONDS);
        $sandbox = $this->project->sandbox()->first();

        // A move takes its own (SBX-005).
        if ($sandbox?->status !== SandboxStatus::Running || $sandbox->external_id === null || SandboxMove::query()->active()->where('sandbox_id', $sandbox->id)->exists()) {
            return;
        }

        try {
            // The one already under way, if any.
            $snapshot = $snapshots->begin($sandbox, $this->reason);

            while ($snapshot?->isPending() && ! $snapshots->check($snapshot)) {
                if (now()->addSeconds(MoveProjectSandbox::CHECK_SECONDS)->gt($until)) {
                    $this->release(MoveProjectSandbox::CHECK_SECONDS);

                    return;
                }

                Sleep::for(MoveProjectSandbox::CHECK_SECONDS)->seconds();
            }
        } catch (SandboxException $e) {
            // The next turn, or the next time it goes unused, tries again.
            report($e);
        }

        if ($this->suspendAfter) {
            try {
                $provider->suspend($sandbox->external_id);
                // Stopped later like any suspended one (SBX-007).
                $sandbox->forceFill(['suspended_at' => now(), 'stopped_at' => null])->saveQuietly();
            } catch (SandboxException $e) {
                // It pauses by itself once idle; this only makes it sooner.
                report($e);
            }
        }
    }
}
