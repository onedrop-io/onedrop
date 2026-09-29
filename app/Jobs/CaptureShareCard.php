<?php

namespace App\Jobs;

use App\Enums\ShareCardStatus;
use App\Models\ProjectShare;
use App\Sandbox\SandboxException;
use App\Sandbox\ShareCards;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;

/**
 * Take a screenshot of the app and render its share card (SHARE-001).
 */
#[DeleteWhenMissingModels]
class CaptureShareCard implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Two headless Chromium runs of at most 90 seconds each, plus copying the images out. */
    public int $timeout = 240;

    public int $uniqueFor = 240;

    public function __construct(public ProjectShare $share) {}

    public function uniqueId(): string
    {
        return (string) $this->share->id;
    }

    public function handle(ShareCards $cards): void
    {
        try {
            $cards->capture($this->share);
        } catch (SandboxException $e) {
            $this->share->update(['card_status' => ShareCardStatus::Failed, 'card_error' => $e->getMessage()]);
        }
    }

    /**
     * A run that timed out or crashed shouldn't leave the Share panel rendering forever.
     */
    public function failed(): void
    {
        $this->share->update(['card_status' => ShareCardStatus::Failed, 'card_error' => __("Couldn't make the share card.")]);
    }
}
