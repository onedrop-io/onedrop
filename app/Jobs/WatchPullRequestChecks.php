<?php

namespace App\Jobs;

use App\Enums\MessageRole;
use App\Models\Task;
use App\Sandbox\GitException;
use App\Sandbox\GitHubPulls;
use App\Sandbox\PullRequestFixes;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Follow the checks on a pull request's task's newest commit until they finish (GIT-015): once a minute for up to an
 * hour, as a delayed job rather than a webhook or a scheduled task. The chat says how they went, and with automatic
 * fixing on, failures go to the task's agent, up to PullRequestFixes::MAX_ATTEMPTS times in a row.
 */
class WatchPullRequestChecks implements ShouldQueue
{
    use Queueable;

    /** Seconds between looks. */
    public const INTERVAL = 60;

    /** Looks before giving up on checks that never finish. */
    public const MAX_POLLS = 60;

    /** Looks for checks that haven't appeared yet (a commit's workflows take a moment to start, and some repos have none). */
    public const MAX_EMPTY_POLLS = 5;

    public int $tries = 1;

    public function __construct(public Task $task, public string $sha, public int $polls = 0) {}

    public function handle(GitHubPulls $pulls, PullRequestFixes $fixes): void
    {
        $task = $this->task->fresh();

        // Deleted, or a newer push has its own watch.
        if ($task === null || $task->pull_request_head_sha !== $this->sha) {
            return;
        }

        try {
            $checks = $pulls->checks($task->project, $this->sha, fresh: true);
        } catch (GitException $e) {
            report($e);
            $this->again();

            return;
        }

        $state = GitHubPulls::summarize($checks);

        if ($state === null || $state === 'pending') {
            $task->update(['pull_request_checks' => $state === null && $this->polls + 1 >= self::MAX_EMPTY_POLLS ? null : 'pending']);
            $this->again($state === null ? self::MAX_EMPTY_POLLS : self::MAX_POLLS);

            return;
        }

        $short = substr($this->sha, 0, 7);

        if ($state === 'success') {
            $task->update(['pull_request_checks' => 'success', 'pull_request_fix_attempts' => 0]);
            $task->messages()->create(['role' => MessageRole::Activity, 'content' => "Checks passed on {$short}"]);

            return;
        }

        $failed = GitHubPulls::failed($checks);
        $task->update(['pull_request_checks' => 'failure']);
        $task->messages()->create(['role' => MessageRole::Activity, 'content' => trans_choice(':count check failed on :sha|:count checks failed on :sha', count($failed), ['count' => count($failed), 'sha' => $short])]);

        if (! $task->pull_request_autofix) {
            return;
        }

        if ($task->pull_request_fix_attempts >= PullRequestFixes::MAX_ATTEMPTS) {
            $task->messages()->create(['role' => MessageRole::Activity, 'content' => 'Stopped fixing the checks automatically after '.PullRequestFixes::MAX_ATTEMPTS.' tries that didn\'t make them pass']);

            return;
        }

        $task->update(['pull_request_fix_attempts' => $task->pull_request_fix_attempts + 1]);

        try {
            $fixes->send($task, $checks, $this->sha);
        } catch (GitException $e) {
            report($e);
        }
    }

    protected function again(int $limit = self::MAX_POLLS): void
    {
        if ($this->polls + 1 < $limit) {
            self::dispatch($this->task, $this->sha, $this->polls + 1)->delay(now()->addSeconds(self::INTERVAL));
        }
    }
}
