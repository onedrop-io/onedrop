<?php

namespace App\Jobs;

use App\Enums\SandboxStatus;
use App\Models\Project;
use App\Models\SandboxMove;
use App\Sandbox\ProjectSnapshots;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxProvider;
use App\Sandbox\SandboxWaitLimit;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;

/**
 * Takes a snapshot of a project's main sandbox (SBX-009): after an agent turn, and once it has gone unused. It only
 * starts it: the sandbox packs and uploads it in the background and says when it's done (FinishSnapshot), which also
 * suspends the sandbox when it went unused.
 */
#[DeleteWhenMissingModels]
class TakeSnapshot implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 85;

    public int $tries = 2;

    /**
     * @param  bool  $suspendAfter  suspend the sandbox once it's taken (it went unused; SBX-007)
     */
    public function __construct(public Project $project, public string $reason, public bool $suspendAfter = false) {}

    public function uniqueId(): string
    {
        return (string) $this->project->id;
    }

    public function handle(ProjectSnapshots $snapshots, SandboxWaitLimit $limit, SandboxProvider $provider): void
    {
        // Provider calls end in time for a Flex queue job, even one run inside a request or another job (a sync queue).
        $limit->during(MoveProjectSandbox::WAIT_SECONDS, fn () => $this->run($snapshots, $provider));
    }

    protected function run(ProjectSnapshots $snapshots, SandboxProvider $provider): void
    {
        $sandbox = $this->project->sandbox()->first();

        // A move takes its own (SBX-005).
        if ($sandbox?->status !== SandboxStatus::Running || $sandbox->external_id === null || SandboxMove::query()->active()->where('sandbox_id', $sandbox->id)->exists()) {
            return;
        }

        try {
            // The one already under way, if any.
            $snapshot = $snapshots->begin($sandbox, $this->reason);
        } catch (SandboxException $e) {
            // The next turn, or the next time it goes unused, tries again.
            report($e);

            return;
        }

        if ($snapshot?->isPending()) {
            if ($this->suspendAfter) {
                $snapshot->update(['suspend_after' => true]);
            }

            return;
        }

        // Nothing changed since the last one (or it was taken on the spot): suspend now.
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
