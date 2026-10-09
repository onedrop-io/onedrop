<?php

namespace App\Jobs;

use App\Enums\SandboxStatus;
use App\Models\Project;
use App\Models\SandboxMove;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxUpdater;
use App\Sandbox\SandboxWaitLimit;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Brings a project's sandbox up to date once nobody has used it for a while (SBX-002), and snapshots what was done in
 * it (SBX-009). Queued, delayed, whenever the sandbox is used (Sandbox::markActive()), and put back while it's still in
 * use, so an update never runs on someone's time, and a sandbox nobody uses is never woken for one.
 */
class UpdateSandbox implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** How long a sandbox goes unused before it's updated. */
    public const IDLE_SECONDS = 600;

    /** A new sandbox is made by its own queued move (SandboxMover); this only starts it. */
    public int $timeout = 85;

    /** Put back as often as it takes while the project is in use. */
    public int $tries = 0;

    /** Seconds before another update of the same project may be queued, should this one be lost. */
    public int $uniqueFor = 3600;

    /**
     * Create a new job instance.
     */
    public function __construct(public Project $project) {}

    public function uniqueId(): string
    {
        return (string) $this->project->id;
    }

    public function handle(SandboxUpdater $updater, SandboxWaitLimit $limit): void
    {
        // A sandbox that stopped answering can't hold this job past a Flex queue's 90 seconds.
        $limit->start(MoveProjectSandbox::WAIT_SECONDS);
        $project = $this->project->fresh();
        $sandbox = $project?->sandbox()->first();

        if ($sandbox?->status !== SandboxStatus::Running || $sandbox->external_id === null) {
            return;
        }

        $unusedFor = (int) ($sandbox->last_active_at ?? $sandbox->updated_at)->diffInSeconds(now());

        if ($unusedFor < self::IDLE_SECONDS || $project->mainSandboxBusy()) {
            $this->release(max(self::IDLE_SECONDS - $unusedFor, 60));

            return;
        }

        $lastMove = (int) SandboxMove::query()->where('sandbox_id', $sandbox->id)->max('id');

        try {
            $updater->updateIfOutdated($project, options: ['suspend' => true]);
        } catch (SandboxException $e) {
            report($e);
        }

        // A move to a new sandbox snapshots the old one first, and suspends the new one when it's done.
        if (SandboxMove::query()->where('sandbox_id', $sandbox->id)->where('id', '>', $lastMove)->exists()) {
            return;
        }

        // Work done without the agent (the Shell, the app itself) is kept too; then it stops using compute.
        TakeSnapshot::dispatch($project, 'idle', suspendAfter: true);
    }
}
