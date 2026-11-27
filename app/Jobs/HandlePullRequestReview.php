<?php

namespace App\Jobs;

use App\Models\Project;
use App\Sandbox\GitException;
use App\Sandbox\PullRequestCheckouts;
use App\Sandbox\PullRequestReviews;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;

/**
 * A review was submitted on a pull request (GIT-031): its task hands the trusted comments waiting on someone to its
 * agent when it addresses them by itself. A pull request without a task gets one first when the project addresses
 * review comments by itself, unless it's from a fork, whose code never runs by itself (GIT-026).
 */
#[DeleteWhenMissingModels]
class HandlePullRequestReview implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * @param  array{number: int, title: string, state: string, head: string, head_sha: string, base: string, fork: bool, created_at: ?string}  $pull
     */
    public function __construct(public Project $project, public array $pull) {}

    public function handle(PullRequestCheckouts $checkouts, PullRequestReviews $reviews): void
    {
        $task = $checkouts->taskFor($this->project, $this->pull['number']);
        $created = false;

        if ($task === null) {
            if ($this->project->address_review_comments === 'off' || $this->pull['fork'] || $checkouts->problem($this->project, $this->pull) !== null) {
                return;
            }

            // Its first message, the review comments, makes its copy on the pull request's branch (TASK-003).
            $task = $checkouts->newTask($this->project, $this->pull);
            $created = true;
        }

        try {
            $sent = $reviews->addressAutomatically($task);
        } catch (GitException $e) {
            report($e);
            $sent = false;
        }

        // Nothing for it to do (a review with nothing waiting, or only comments it doesn't trust): no task after all.
        if ($created && ! $sent) {
            $task->delete();
        }
    }
}
