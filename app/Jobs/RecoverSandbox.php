<?php

namespace App\Jobs;

use App\Enums\OldSandboxStatus;
use App\Models\SandboxMove;
use App\Sandbox\SandboxMover;
use App\Sandbox\SandboxWaitLimit;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Checks on a sandbox a project moved off while it didn't answer (SBX-013), every CHECK_MINUTES for DAYS, until it
 * answers: its newer files are then saved as a recovered snapshot, and it's deleted.
 */
class RecoverSandbox implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const CHECK_MINUTES = 15;

    public const DAYS = 7;

    public int $timeout = 85;

    public int $maxExceptions = 3;

    public function __construct(public SandboxMove $move) {}

    public function uniqueId(): string
    {
        return (string) $this->move->id;
    }

    public function retryUntil(): DateTimeInterface
    {
        return ($this->move->finished_at ?? now())->addDays(self::DAYS);
    }

    public function handle(SandboxMover $mover, SandboxWaitLimit $limit): void
    {
        $limit->start(MoveProjectSandbox::WAIT_SECONDS);

        if (($wait = $mover->recover($this->move->fresh())) !== null) {
            $this->release($wait);
        }
    }

    /**
     * It never answered again (or checking kept failing): it's left to its provider.
     */
    public function failed(): void
    {
        $move = $this->move->fresh();

        if ($move?->old_status === OldSandboxStatus::Waiting) {
            $move->update(['old_status' => OldSandboxStatus::Gone]);
        }
    }
}
