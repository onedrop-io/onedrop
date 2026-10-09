<?php

namespace App\Jobs;

use App\Enums\SandboxMovePhase;
use App\Models\SandboxMove;
use App\Sandbox\SandboxMover;
use App\Sandbox\SandboxWaitLimit;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * Advances a sandbox's move (SBX-005) as far as it can go now, well within a Flex queue job's 90 seconds. Nothing
 * checks back on it: it's queued again when what it waits on says so (the sandbox's background work calls back, the
 * agent's turn ends), or after a refusal that clears by itself. A safety check after 15 and 30 minutes catches a call
 * that never arrives.
 */
class MoveProjectSandbox implements ShouldQueue
{
    use Queueable;

    /** Every call to a provider ends by then (SandboxWaitLimit), so an attempt never nears 90 seconds. */
    public const WAIT_SECONDS = 75;

    /** The safety checks on background work: after 15 minutes, and the last after 30. */
    public const LAST_CHECK = 2;

    public const CHECK_MINUTES = 15;

    public int $timeout = 85;

    /** A crash in the job itself (not a provider refusing, which fails the move) gets two more tries. */
    public int $maxExceptions = 3;

    public function __construct(public SandboxMove $move, public int $check = 0) {}

    /**
     * Retries and put-backs stop once the move would have been given up on anyway.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(SandboxMover::GIVE_UP_MINUTES + 15);
    }

    public function handle(SandboxMover $mover, SandboxWaitLimit $limit): void
    {
        // Provider calls end in time for a Flex queue job, even one run inside a request or another job (a sync queue).
        $limit->during(self::WAIT_SECONDS, fn () => $this->run($mover));
    }

    protected function run(SandboxMover $mover): void
    {
        $lock = Cache::lock("sandbox-move-step:{$this->move->id}", $this->timeout + 5);

        // Another step of the same move is running: this one goes right after it.
        if (! $lock->get()) {
            $this->release(5);

            return;
        }

        try {

            $move = $this->move->fresh();

            if (! $move?->phase->isActive()) {
                return;
            }

            $wait = $mover->step($move);

            if ($wait !== null && $wait > SandboxMover::WAIT) {
                $this->release($wait);
            } elseif ($wait === SandboxMover::WAIT && $this->check > 0 && $this->check < self::LAST_CHECK && $move->phase === SandboxMovePhase::Restoring) {
                // A safety check found the sandbox still at it: one more, then it's given up on.
                self::dispatch($move, $this->check + 1)->delay(now()->addMinutes(self::CHECK_MINUTES));
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * The job itself kept crashing, or ran out of time: the move fails, and the project stays where it was.
     */
    public function failed(?Throwable $exception): void
    {
        $move = $this->move->fresh();

        if ($move?->phase->isActive()) {
            app(SandboxMover::class)->fail($move, $exception ?? new RuntimeException('The move stopped.'));
        }
    }
}
