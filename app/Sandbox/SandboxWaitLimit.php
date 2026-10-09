<?php

namespace App\Sandbox;

use Carbon\CarbonImmutable;
use Closure;

/**
 * How long the current web request, or a move's queue job, may still wait on the sandbox provider, retries included.
 * Laravel Cloud gives up on a request after 20 seconds (a 504) and on a Flex queue job after 90, while PHP would carry
 * on waiting, so a sandbox that never answers would hold a worker for minutes. Started for web requests by
 * `LimitSandboxWaits` and by jobs that move sandboxes (SBX-005); other jobs and commands have no limit.
 */
class SandboxWaitLimit
{
    protected ?CarbonImmutable $until = null;

    public function start(int $seconds): void
    {
        $this->until = CarbonImmutable::now()->addSeconds($seconds);
    }

    /**
     * Seconds left, or null when there's no limit.
     */
    public function secondsLeft(): ?float
    {
        return $this->until === null ? null : max(0, CarbonImmutable::now()->diffInMilliseconds($this->until, false) / 1000);
    }

    /**
     * Run $callback with $seconds to wait, whatever the limit was, then put it back: a queue job's own time, even when
     * it runs inside a web request or another job (a sync queue).
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function during(int $seconds, Closure $callback): mixed
    {
        $before = $this->until;
        $this->until = CarbonImmutable::now()->addSeconds($seconds);

        try {
            return $callback();
        } finally {
            $this->until = $before;
        }
    }

    /**
     * Run $callback with at most $seconds to wait (less if the limit is nearer), then put the limit back.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function within(int $seconds, Closure $callback): mixed
    {
        $before = $this->until;
        $narrower = CarbonImmutable::now()->addSeconds($seconds);
        $this->until = $before !== null && $before->lt($narrower) ? $before : $narrower;

        try {
            return $callback();
        } finally {
            $this->until = $before;
        }
    }
}
