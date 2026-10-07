<?php

namespace App\Jobs;

use App\Enums\MessageRole;
use App\Enums\SandboxStatus;
use App\Enums\TaskSyncStatus;
use App\Models\Task;
use App\Sandbox\Agents\AgentQueue;
use App\Sandbox\SandboxException;
use App\Sandbox\TaskCopies;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Arr;

/**
 * Make a task's own copy of the app from Main's sandbox before its first run (TASK-003). Chained before
 * RunAgentTask; if the copy can't be made, the chain stops and the task's chat says why. A pull request's task
 * (GIT-014) is switched to the pull request's branch; checked out without a message ($checkOutOnly), the task
 * shows as working while its copy is made, so messages sent meanwhile wait for it, then it goes idle.
 */
class ForkTaskSandbox implements ShouldQueue
{
    use Queueable;

    /** Copying a large workspace out and back in, and the app's Docker images, takes a while. */
    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public Task $task, public bool $checkOutOnly = false) {}

    public function handle(TaskCopies $copies, AgentQueue $queue): void
    {
        $task = $this->task;
        $task->update(['sync_status' => TaskSyncStatus::Forking, 'sync_error' => null]);
        $task->messages()->create(['role' => MessageRole::Activity, 'content' => $task->isPullRequest()
            ? "Checking out #{$task->pull_request_number} ({$task->pull_request_branch}) in a copy of the app for this task"
            : 'Making a copy of the app for this task']);

        try {
            $shared = $copies->fork($task);
        } catch (SandboxException $e) {
            report($e);
            $task->sandbox()->update(['status' => SandboxStatus::Failed, 'error' => $e->getMessage()]);
            $task->update(['sync_status' => null]);
            $task->messages()->create([
                'role' => MessageRole::Assistant,
                'content' => "I couldn't make this task's copy of the app: {$e->getMessage()}",
            ]);
            $queue->finished($task->fresh());
            $this->fail($e);

            return;
        }

        $task->update(['sync_status' => null]);

        if ($task->isPullRequest()) {
            $task->messages()->create(['role' => MessageRole::Activity, 'content' => "Checked out #{$task->pull_request_number} at ".substr((string) $task->pull_request_head_sha, 0, 7)]);
            WatchPullRequestChecks::dispatch($task, (string) $task->pull_request_head_sha);
        }

        if ($shared !== []) {
            $task->messages()->create([
                'role' => MessageRole::Activity,
                'content' => 'This copy still uses '.Arr::join($shared, ', ', ' and ').' from the app\'s settings, shared with Main',
            ]);
        }

        // Nothing was asked yet: go idle, or run what was sent while the copy was made.
        if ($this->checkOutOnly) {
            $queue->finished($task->fresh());
        }
    }

    /**
     * Never leave the task's copy stuck on "creating" or the chat on "Thinking…".
     */
    public function failed(?\Throwable $exception): void
    {
        $this->task->update(['sync_status' => null]);
        $this->task->sandbox()->where('status', SandboxStatus::Creating)->update(['status' => SandboxStatus::Failed, 'error' => $exception?->getMessage()]);

        if ($this->task->fresh()?->isWorking()) {
            app(AgentQueue::class)->finished($this->task->fresh());
        }
    }
}
