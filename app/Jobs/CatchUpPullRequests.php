<?php

namespace App\Jobs;

use App\Models\Project;
use App\Sandbox\GitException;
use App\Sandbox\PullRequestPreviews;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Compare a project's open pull requests with its tasks and catch up on what no webhook said (GIT-026).
 */
class CatchUpPullRequests implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public Project $project) {}

    public function handle(PullRequestPreviews $previews): void
    {
        try {
            $previews->catchUp($this->project);
        } catch (GitException) {
            // GitHub's errors are shown where pull requests are listed; the next catch-up tries again.
        }
    }
}
