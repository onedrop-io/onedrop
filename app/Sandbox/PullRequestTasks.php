<?php

namespace App\Sandbox;

use App\Enums\MessageRole;
use App\Enums\TaskStage;
use App\Jobs\RemovePullRequestCopy;
use App\Jobs\UpdatePullRequestPreview;
use App\Jobs\WatchPullRequestChecks;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Keeps a project's pull request tasks in step with GitHub: a task opening a pull request becomes its task once GitHub
 * has it (GIT-024), and a pull request's task moves to Done, once, when it's merged or closed (GIT-023).
 */
class PullRequestTasks
{
    public function __construct(protected GitHubPulls $pulls) {}

    /**
     * Tasks GitHub may have news for: pull requests' tasks not yet merged or closed, and tasks opening one.
     *
     * @return HasMany<Task, Project>
     */
    public static function watched(Project $project): HasMany
    {
        return $project->tasks()->where(fn (Builder $query) => $query
            ->where(fn (Builder $pulls) => $pulls->whereNotNull('pull_request_number')->whereNull('pull_request_state'))
            ->orWhere(fn (Builder $opening) => $opening->whereNull('pull_request_number')->whereNotNull('pull_request_branch')));
    }

    /**
     * Look at GitHub for every watched task. $open is the open pull requests when they've just been listed.
     *
     * @param  list<array<string, mixed>>|null  $open
     *
     * @throws GitException
     */
    public function refresh(Project $project, ?array $open = null): void
    {
        $tasks = self::watched($project)->get();

        if ($tasks->isEmpty() || ! GitHubPulls::available($project)) {
            return;
        }

        $open = collect($open ?? $this->pulls->list($project));
        $openNumbers = $open->pluck('number')->all();
        $openByBranch = $open->reject(fn (array $pull) => $pull['fork'])->keyBy('head');

        foreach ($tasks as $task) {
            if ($task->isOpeningPullRequest()) {
                $pull = $openByBranch->get((string) $task->pull_request_branch);

                if ($pull !== null) {
                    $this->link($task, (int) $pull['number'], (string) $pull['base']);
                }

                continue;
            }

            // Only the 50 most recently changed open ones are listed: one missing from them is looked up.
            if (! in_array($task->pull_request_number, $openNumbers, true)) {
                $this->settle($task, (string) $this->pulls->find($project, (int) $task->pull_request_number)['state']);
            }
        }
    }

    /**
     * Ask GitHub for a pull request from the task's branch, linking it when there is one. Returns whether it was found.
     *
     * @throws GitException
     */
    public function find(Task $task): bool
    {
        if (! $task->isOpeningPullRequest()) {
            return true;
        }

        $pull = $this->pulls->fromBranch($task->project, (string) $task->pull_request_branch);

        if ($pull === null) {
            return false;
        }

        $this->link($task, $pull['number'], $pull['base']);
        $this->settle($task->refresh(), $pull['state']);

        return true;
    }

    /**
     * The task opened pull request $number: it's that pull request's task from now on (GIT-014), in Review.
     */
    public function link(Task $task, int $number, string $base): void
    {
        $toReview = ! $task->isWorking() && $task->stage !== TaskStage::Review;

        $task->update([
            'pull_request_number' => $number,
            'pull_request_base' => $base,
            'pull_request_fork' => false,
            'pull_request_draft' => null,
            'pull_request_address_reviews' => $task->project->address_review_comments !== 'off',
            'pull_request_checks' => $task->pull_request_head_sha !== null ? 'pending' : null,
            ...($toReview ? ['stage' => TaskStage::Review, 'position' => $task->project->nextTaskPosition(TaskStage::Review)] : []),
        ]);
        $task->messages()->create(['role' => MessageRole::Activity, 'content' => "Opened #{$number}"]);

        if ($task->pull_request_head_sha !== null) {
            WatchPullRequestChecks::dispatch($task, $task->pull_request_head_sha);
        }

        // Its copy is running already: its preview goes on the pull request (GIT-028).
        UpdatePullRequestPreview::for($task);
    }

    /**
     * The pull request was merged or closed: remember it and move its task to Done, once. Open ones are left alone.
     */
    public function settle(Task $task, string $state): void
    {
        if (! in_array($state, ['merged', 'closed'], true) || $task->pull_request_state !== null) {
            return;
        }

        $task->update([
            'pull_request_state' => $state,
            ...($task->stage !== TaskStage::Done ? ['stage' => TaskStage::Done, 'position' => $task->project->nextTaskPosition(TaskStage::Done)] : []),
        ]);
        $task->messages()->create(['role' => MessageRole::Activity, 'content' => "#{$task->pull_request_number} was {$state} on GitHub"]);

        // Its preview goes inactive on GitHub, and its copy a day later unless it's reopened (GIT-026, GIT-028).
        UpdatePullRequestPreview::for($task);
        RemovePullRequestCopy::dispatch($task)->delay(now()->addHours(RemovePullRequestCopy::AFTER_HOURS));
    }
}
