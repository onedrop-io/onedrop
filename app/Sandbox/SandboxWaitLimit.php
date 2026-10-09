<?php

namespace App\Sandbox;

use Carbon\CarbonImmutable;

/**
 * How long the current web request may still wait on the sandbox provider, retries included. Laravel Cloud gives up
 * on a request after 20 seconds (a 504) while PHP carries on waiting, so a sandbox that never answers would hold a
 * worker for minutes. Started for web requests only (`LimitSandboxWaits`); queue jobs and commands have no limit.
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
}
