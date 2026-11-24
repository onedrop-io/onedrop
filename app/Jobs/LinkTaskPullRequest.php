<?php

namespace App\Jobs;

use App\Models\Task;
use App\Sandbox\GitException;
use App\Sandbox\PullRequestTasks;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;

/**
 * Look for the pull request opened from a task's pushed branch (GIT-024): once a minute for half an hour after the
 * push, as a delayed job like the checks' watch (GIT-015). Found, the task becomes the pull request's task.
 */
#[DeleteWhenMissingModels]
class LinkTaskPullRequest implements ShouldQueue
{
    use Queueable;

    /** Seconds between looks. */
    public const INTERVAL = 60;

    /** Looks before giving up; opening the Pull requests list or the task's page still finds it later. */
    public const MAX_POLLS = 30;

    public int $tries = 1;

    public function __construct(public Task $task, public string $branch, public int $polls = 0) {}

    public function handle(PullRequestTasks $tasks): void
    {
        $task = $this->task->fresh();

        // Deleted, linked already, or pushed again to a branch of its own.
        if ($task === null || ! $task->isOpeningPullRequest() || $task->pull_request_branch !== $this->branch) {
            return;
        }

        try {
            if ($tasks->find($task)) {
                return;
            }
        } catch (GitException $e) {
            report($e);
        }

        if ($this->polls + 1 < self::MAX_POLLS) {
            self::dispatch($task, $this->branch, $this->polls + 1)->delay(now()->addSeconds(self::INTERVAL));
        }
    }
}
