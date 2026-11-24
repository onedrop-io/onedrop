<?php

namespace App\Jobs;

use App\Models\Task;
use App\Sandbox\GitHubPreviews;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;

/**
 * Bring a pull request's preview on GitHub up to date with its task (GIT-028): its Deployment and comment.
 */
#[DeleteWhenMissingModels]
class UpdatePullRequestPreview implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public Task $task) {}

    /**
     * Queue an update when the task's preview belongs on GitHub.
     */
    public static function for(Task $task): void
    {
        if (app(GitHubPreviews::class)->applies($task)) {
            self::dispatch($task);
        }
    }

    public function handle(GitHubPreviews $previews): void
    {
        if (($task = $this->task->fresh()) !== null) {
            $previews->update($task);
        }
    }
}
