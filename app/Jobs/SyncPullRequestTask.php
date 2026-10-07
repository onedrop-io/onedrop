<?php

namespace App\Jobs;

use App\Enums\MessageRole;
use App\Enums\SandboxStatus;
use App\Enums\TaskSyncStatus;
use App\Models\Task;
use App\Sandbox\Agents\AgentQueue;
use App\Sandbox\GitException;
use App\Sandbox\SandboxException;
use App\Sandbox\TaskCopies;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Push a pull request's task to its branch on GitHub, or bring in commits pushed there since (GIT-014). After a
 * push that sent something new, the checks are watched (GIT-015).
 */
class SyncPullRequestTask implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public Task $task, public TaskSyncStatus $direction) {}

    /**
     * Why the task can't push or pull now, or null when it can.
     */
    public static function problem(Task $task, TaskSyncStatus $direction): ?string
    {
        $copy = $task->sandbox()->first();

        return match (true) {
            ! $task->isPullRequest() => __('This task isn\'t a pull request.'),
            $task->sync_status !== null => __('Wait for the current push or pull to finish.'),
            $copy?->status !== SandboxStatus::Running || $copy->external_id === null => __("This task doesn't have its own copy of the app running."),
            $direction === TaskSyncStatus::Pushing && $task->pull_request_fork => __('This pull request comes from a fork, so OneDrop can\'t push to it.'),
            $direction === TaskSyncStatus::Pulling && $task->isWorking() => __("Wait for the task's agent to finish, or stop it."),
            default => null,
        };
    }

    /**
     * Start pushing or pulling, unless something's in the way; returns why not, or null when started.
     */
    public static function start(Task $task, TaskSyncStatus $direction): ?string
    {
        if (($problem = self::problem($task, $direction)) !== null) {
            return $problem;
        }

        $task->update(['sync_status' => $direction, 'sync_error' => null]);
        self::dispatch($task, $direction);

        return null;
    }

    public function handle(TaskCopies $copies, AgentQueue $queue): void
    {
        $task = $this->task;
        $number = $task->pull_request_number;

        try {
            if ($this->direction === TaskSyncStatus::Pulling) {
                ['sha' => $sha, 'merged' => $merged, 'conflicts' => $conflicts] = $copies->pullPullRequest($task);
                $task->update(['sync_status' => null, 'sync_error' => null, 'pull_request_head_sha' => $sha]);

                if (! $merged) {
                    $task->messages()->create(['role' => MessageRole::Activity, 'content' => "Already up to date with #{$number}"]);

                    return;
                }

                WatchPullRequestChecks::dispatch($task, $sha);
                $task->messages()->create(['role' => MessageRole::Activity, 'content' => $conflicts === [] ? "Brought in the latest from #{$number}" : "Brought in the latest from #{$number}, with conflicts"]);
                $queue->send($task, SyncTask::handOff("#{$number}", $conflicts, fromMain: false, what: "Commits pushed to pull request #{$number} since this copy last had it were just merged in here."));

                return;
            }

            $sha = $copies->pushPullRequest($task);
        } catch (GitException|SandboxException $e) {
            $task->update(['sync_status' => null, 'sync_error' => $e->getMessage()]);
            $task->messages()->create(['role' => MessageRole::Activity, 'content' => ($this->direction === TaskSyncStatus::Pulling ? "Couldn't pull #{$number}: " : "Couldn't push to #{$number}: ").$e->getMessage()]);

            return;
        }

        $pushed = $sha !== $task->pull_request_head_sha;
        $task->update(['sync_status' => null, 'sync_error' => null, 'pull_request_head_sha' => $sha, ...($pushed ? ['pull_request_checks' => 'pending'] : [])]);

        if ($pushed) {
            $task->messages()->create(['role' => MessageRole::Activity, 'content' => "Pushed to #{$number} (".substr($sha, 0, 7).')']);
            WatchPullRequestChecks::dispatch($task, $sha)->delay(now()->addSeconds(WatchPullRequestChecks::INTERVAL));
        }
    }

    /**
     * Never leave the task showing "Pushing…" if the job itself crashes.
     */
    public function failed(?Throwable $exception): void
    {
        $this->task->update(['sync_status' => null, 'sync_error' => __('Something went wrong. Try again.')]);
    }
}
