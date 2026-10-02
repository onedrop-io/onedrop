<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Sandbox\Agents\AiCredits;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;

/**
 * Take what an organization's AI credits key spent off its credits (CREDIT-001), after a run on them. Waits a
 * little first, since OpenRouter counts a request's cost shortly after it ends; anything it misses is charged
 * before the next run.
 */
#[DeleteWhenMissingModels]
class ChargeAiCredits implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(public Organization $organization)
    {
        $this->delay(now()->addSeconds(30));
    }

    /**
     * One waiting charge per organization is enough: it charges everything spent so far.
     */
    public function uniqueId(): string
    {
        return (string) $this->organization->id;
    }

    /**
     * Execute the job.
     */
    public function handle(AiCredits $credits): void
    {
        $credits->charge($this->organization);
    }

    /**
     * After a run on an organization's credits.
     */
    public static function afterRun(?Organization $organization): void
    {
        if ($organization?->ai_credits_key_hash !== null && app(AiCredits::class)->enabled()) {
            self::dispatch($organization);
        }
    }
}
