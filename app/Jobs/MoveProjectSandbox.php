<?php

namespace App\Jobs;

use App\Models\SandboxMove;
use App\Sandbox\SandboxMover;
use App\Sandbox\SandboxWaitLimit;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

/**
 * Advances a sandbox's move (SBX-005) for up to STEP_SECONDS, then puts itself back to carry on, so no attempt comes
 * near Laravel Cloud's 90 seconds for a Flex queue job however long the move takes.
 */
class MoveProjectSandbox implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** How long one attempt works on the move before putting itself back. */
    public const STEP_SECONDS = 50;

    /** How often background work in a sandbox is checked on. */
    public const CHECK_SECONDS = 5;

    /** Every call to a provider ends by then (SandboxWaitLimit), so an attempt never nears 90 seconds. */
    public const WAIT_SECONDS = 75;

    public int $timeout = 85;

    /** A crash in the job itself (not a provider refusing, which fails the move) gets two more tries. */
    public int $maxExceptions = 3;

    public function __construct(public SandboxMove $move) {}

    public function uniqueId(): string
    {
        return (string) $this->move->id;
    }

    /**
     * Checking back makes many attempts; give up once the move would have been given up on anyway.
     */
    public function retryUntil(): DateTimeInterface
    {
        return $this->move->created_at->addMinutes(SandboxMover::GIVE_UP_MINUTES + 15);
    }

    public function handle(SandboxMover $mover, SandboxWaitLimit $limit): void
    {
        $limit->start(self::WAIT_SECONDS);
        $until = now()->addSeconds(self::STEP_SECONDS);
        $move = $this->move->fresh();

        while ($move?->phase->isActive() && ! $mover->step($move)) {
            if (now()->addSeconds(self::CHECK_SECONDS)->gt($until)) {
                $this->release(self::CHECK_SECONDS);

                return;
            }

            Sleep::for(self::CHECK_SECONDS)->seconds();
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
