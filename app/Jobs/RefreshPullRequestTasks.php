<?php

namespace App\Jobs;

use App\Models\Project;
use App\Sandbox\GitException;
use App\Sandbox\GitHubPulls;
use App\Sandbox\PullRequestTasks;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Bring a project's pull request tasks up to date with GitHub (GIT-023, GIT-024), a moment after its board or a task's
 * page opens, rather than with a GitHub call while the page renders.
 */
class RefreshPullRequestTasks implements ShouldQueue
{
    use Queueable;

    /** Seconds between looks for one project. */
    public const EVERY = 60;

    public int $tries = 1;

    public function __construct(public Project $project) {}

    /**
     * Queue a look unless there's nothing to look for, or one was queued in the last minute.
     */
    public static function soon(Project $project): void
    {
        if (! GitHubPulls::available($project) || ! PullRequestTasks::watched($project)->exists()) {
            return;
        }

        if (Cache::add("pull-request-tasks-refresh:{$project->id}", true, self::EVERY)) {
            self::dispatch($project);
        }
    }

    public function handle(PullRequestTasks $tasks): void
    {
        try {
            $tasks->refresh($this->project);
        } catch (GitException) {
            // GitHub's errors are shown where pull requests are listed; the next look tries again.
        }
    }
}
