<?php

namespace App\Jobs;

use App\Models\ProjectDomain;
use App\Sandbox\Domains\ProjectDomains;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Checks a waiting custom domain until it's active (DOM-001): every 30 seconds for the first 10 minutes, then every
 * 5 minutes for two days. A job that reschedules itself rather than a scheduled command, since Laravel Cloud wakes the
 * app for every scheduled run.
 */
class CheckProjectDomain implements ShouldQueue
{
    use Queueable;

    public const FAST_SECONDS = 30;

    public const FAST_MINUTES = 10;

    public const SLOW_SECONDS = 300;

    public const GIVE_UP_HOURS = 48;

    /**
     * @param  int  $since  the domain's checking_since this run of checks belongs to; a newer one (Check now) replaces it
     */
    public function __construct(public ProjectDomain $domain, public int $since) {}

    public function handle(ProjectDomains $domains): void
    {
        $domain = $this->domain->fresh();

        if (! $domain || $domain->isActive() || $domain->checking_since?->getTimestamp() !== $this->since) {
            return;
        }

        $domains->check($domain);

        if ($domain->isActive() || $domain->via === null) {
            return;
        }

        $elapsed = $domain->checking_since->diffInSeconds(now());

        // The sync queue ignores delays, so it would check again at once, forever.
        if ($elapsed >= self::GIVE_UP_HOURS * 3600 || config('queue.default') === 'sync') {
            return;
        }

        self::dispatch($domain, $this->since)->delay(now()->addSeconds($elapsed < self::FAST_MINUTES * 60 ? self::FAST_SECONDS : self::SLOW_SECONDS));
    }
}
