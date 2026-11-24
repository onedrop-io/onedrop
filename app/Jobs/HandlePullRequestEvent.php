<?php

namespace App\Jobs;

use App\Models\Project;
use App\Sandbox\GitException;
use App\Sandbox\PullRequestPreviews;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;

/**
 * A pull request webhook for one of the projects connected to its repository (GIT-026): check it out, bring in its
 * new commits, or settle its task once it's merged or closed. Queued, so GitHub gets its answer at once.
 */
#[DeleteWhenMissingModels]
class HandlePullRequestEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * @param  array{number: int, title: string, state: string, head: string, head_sha: string, base: string, fork: bool}  $pull
     */
    public function __construct(public Project $project, public array $pull) {}

    public function handle(PullRequestPreviews $previews): void
    {
        try {
            in_array($this->pull['state'], ['merged', 'closed'], true)
                ? $previews->closed($this->project, $this->pull['number'], $this->pull['state'])
                : $previews->opened($this->project, $this->pull);
        } catch (GitException $e) {
            // The catch-up (when the Pull requests list opens, and hourly) tries again.
            report($e);
        }
    }
}
