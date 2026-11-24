<?php

namespace App\Jobs;

use App\Models\Task;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A day after its pull request was merged or closed, remove its task's copy of the app (GIT-026), unless the pull
 * request was reopened, or the agent is working in it.
 */
class RemovePullRequestCopy implements ShouldQueue
{
    use Queueable;

    /** How long a merged or closed pull request's copy is kept, in hours. */
    public const AFTER_HOURS = 24;

    public int $tries = 1;

    public function __construct(public Task $task) {}

    public function handle(): void
    {
        $task = $this->task->fresh();

        if ($task === null || $task->pull_request_state === null || $task->isWorking()) {
            return;
        }

        $task->destroyCopy();
    }
}
