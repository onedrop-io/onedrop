<?php

namespace App\Sandbox;

use App\Enums\TaskSyncStatus;
use App\Jobs\SyncPullRequestTask;
use App\Jobs\UpdatePullRequestPreview;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Carbon;

/**
 * Every pull request gets a running preview (GIT-026): checked out as a task by itself when it's opened, brought up to
 * date when it gets commits, and settled when it's merged or closed. Told by the GitHub App's webhook, and caught up
 * by comparing the open pull requests with the tasks.
 */
class PullRequestPreviews
{
    public function __construct(
        protected GitHubPulls $pulls,
        protected PullRequestCheckouts $checkouts,
        protected PullRequestTasks $tasks,
    ) {}

    /**
     * Whether the project checks out its pull requests by itself.
     */
    public static function enabled(Project $project): bool
    {
        return $project->preview_pull_requests && Task::getsCopies() && GitHubPulls::available($project);
    }

    /**
     * Whether the project previews this pull request by itself: every one, or only those opened since it chose "New
     * pull requests". One whose opening time isn't known is left alone then.
     *
     * @param  array<string, mixed>  $pull
     */
    public static function wants(Project $project, array $pull): bool
    {
        if ($project->preview_pull_requests_since === null) {
            return true;
        }

        return filled($pull['created_at'] ?? null) && Carbon::parse($pull['created_at'])->gte($project->preview_pull_requests_since);
    }

    /**
     * A pull request was opened, reopened or got commits: check it out, or bring its task's copy up to date.
     *
     * @param  array<string, mixed>  $pull  as GitHubPulls::list() or fromWebhook() give it
     */
    public function opened(Project $project, array $pull): void
    {
        if (! in_array($pull['state'], ['open', 'draft'], true)) {
            return;
        }

        $task = $this->checkouts->taskFor($project, (int) $pull['number']);

        // A fork's code never runs by itself beside the organization's secrets.
        if ($task === null) {
            if (self::enabled($project) && ! $pull['fork'] && self::wants($project, $pull) && $this->checkouts->problem($project, $pull) === null) {
                UpdatePullRequestPreview::for($this->checkouts->checkOut($project, $pull));
            }

            return;
        }

        // Reopened: it's open again, and its copy may have been removed meanwhile.
        if ($task->pull_request_state !== null) {
            $task->update(['pull_request_state' => null]);
        }

        if (! $task->sandbox()->exists()) {
            if (self::enabled($project) && $this->checkouts->problem($project, $pull) === null) {
                $this->checkouts->makeCopy($task);
                UpdatePullRequestPreview::for($task);
            }

            return;
        }

        // New commits on GitHub: bring them in, unless its agent is working or it's pushing or pulling now.
        if (($pull['head_sha'] ?? null) !== null && $pull['head_sha'] !== $task->pull_request_head_sha) {
            SyncPullRequestTask::start($task, TaskSyncStatus::Pulling);
        }
    }

    /**
     * A pull request was merged or closed: its task moves to Done, its preview goes, and its copy a day later.
     */
    public function closed(Project $project, int $number, string $state): void
    {
        $task = $this->checkouts->taskFor($project, $number);

        if ($task !== null) {
            $this->tasks->settle($task, $state);
        }
    }

    /**
     * Compare the open pull requests with the tasks: check out new ones, bring in commits, settle closed ones.
     *
     * @param  list<array<string, mixed>>|null  $open  the open pull requests, when they've just been listed
     *
     * @throws GitException
     */
    public function catchUp(Project $project, ?array $open = null): void
    {
        if (! GitHubPulls::available($project)) {
            return;
        }

        $open ??= $this->pulls->list($project);

        foreach ($open as $pull) {
            $this->opened($project, $pull);
        }

        $this->tasks->refresh($project, $open);
    }
}
