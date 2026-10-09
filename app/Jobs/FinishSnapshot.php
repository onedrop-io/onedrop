<?php

namespace App\Jobs;

use App\Enums\OldSandboxStatus;
use App\Enums\SandboxMovePhase;
use App\Models\ProjectSnapshot;
use App\Models\SandboxMove;
use App\Sandbox\ProjectSnapshots;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxProvider;
use App\Sandbox\SandboxWaitLimit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;

/**
 * A snapshot's sandbox says it's done packing and uploading it (SBX-009): mark it ready (or failed), and carry on
 * whatever waited on it, a move or a recovery (SBX-005, SBX-013), and suspend a sandbox that went unused. Nothing
 * checks back on the sandbox: if its call never arrives, a safety check at 15 and 30 minutes finds out instead.
 */
#[DeleteWhenMissingModels]
class FinishSnapshot implements ShouldQueue
{
    use Queueable;

    /** The safety checks: the one after 15 minutes, and the last, after 30, when a snapshot has taken too long. */
    public const LAST_CHECK = 2;

    public const CHECK_MINUTES = 15;

    public int $timeout = 85;

    public int $tries = 3;

    public function __construct(public ProjectSnapshot $snapshot, public int $check = self::LAST_CHECK) {}

    public function handle(ProjectSnapshots $snapshots, SandboxWaitLimit $limit, SandboxProvider $provider): void
    {
        // Provider calls end in time for a Flex queue job, even one run inside a request or another job (a sync queue).
        $limit->during(MoveProjectSandbox::WAIT_SECONDS, fn () => $this->run($snapshots, $provider));
    }

    protected function run(ProjectSnapshots $snapshots, SandboxProvider $provider): void
    {
        $snapshot = $this->snapshot->fresh();

        if ($snapshot === null) {
            return;
        }

        if ($snapshot->isPending()) {
            try {
                if (! $snapshots->check($snapshot)) {
                    // Still at it (a safety check came first): look once more later.
                    if ($this->check < self::LAST_CHECK) {
                        self::dispatch($snapshot, $this->check + 1)->delay(now()->addMinutes(self::CHECK_MINUTES));
                    }

                    return;
                }
            } catch (SandboxException $e) {
                // It's marked failed; whatever waited on it finds out below.
                report($e);
            }
        }

        $snapshot->refresh();

        // A move takes it from here, or falls back to earlier files when it failed.
        SandboxMove::query()->active()->where('snapshot_id', $snapshot->id)->where('phase', SandboxMovePhase::Snapshotting)
            ->each(fn (SandboxMove $move) => MoveProjectSandbox::dispatch($move));

        // Files recovered from a sandbox that had stopped answering.
        SandboxMove::query()->where('old_status', OldSandboxStatus::Waiting)->where('work->recovering', $snapshot->id)
            ->each(fn (SandboxMove $move) => RecoverSandbox::dispatch($move));

        if ($snapshot->suspend_after && $snapshot->status === ProjectSnapshot::READY) {
            $this->suspend($provider, $snapshot);
        }
    }

    /**
     * The project went unused: its sandbox stops using compute (memory kept) until the next visit, unless it's
     * been used since.
     */
    protected function suspend(SandboxProvider $provider, ProjectSnapshot $snapshot): void
    {
        $sandbox = $snapshot->project?->sandbox()->first();

        if ($sandbox?->external_id !== $snapshot->external_id || $sandbox->last_active_at?->gt($snapshot->created_at)) {
            return;
        }

        try {
            $provider->suspend((string) $sandbox->external_id);
            // Stopped later like any suspended one (SBX-007).
            $sandbox->forceFill(['suspended_at' => now(), 'stopped_at' => null])->saveQuietly();
        } catch (SandboxException $e) {
            // It pauses by itself once idle; this only makes it sooner.
            report($e);
        }
    }
}
