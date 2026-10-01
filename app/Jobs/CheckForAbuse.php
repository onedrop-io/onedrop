<?php

namespace App\Jobs;

use App\Models\Project;
use App\Sandbox\AbuseCheck;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;

/**
 * Ask Jev whether a shared or publicly published project looks like abuse, and hold it for review if so (PUB-003).
 * Publishing runs its check in PublishProject instead, before the app goes up.
 */
#[DeleteWhenMissingModels]
class CheckForAbuse implements ShouldQueue
{
    use Queueable;

    /** Loading the page in headless Chromium (at most 20 seconds), then Jev. */
    public int $timeout = 60;

    /**
     * @param  'share'|'recheck'  $trigger
     */
    public function __construct(public Project $project, public string $trigger) {}

    public function handle(AbuseCheck $check): void
    {
        $project = $this->project->fresh();

        // Taken offline and unshared since: nothing to check.
        if ($project === null || ! $check->isPublic($project)) {
            return;
        }

        $check->run($project, $this->trigger);
    }
}
