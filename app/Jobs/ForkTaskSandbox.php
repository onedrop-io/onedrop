<?php

namespace App\Jobs;

use App\Enums\MessageRole;
use App\Enums\SandboxStatus;
use App\Models\Task;
use App\Sandbox\Agents\AgentQueue;
use App\Sandbox\SandboxException;
use App\Sandbox\TaskCopies;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Arr;

/**
 * Make a task's own copy of the app from Main's sandbox before its first run (TASK-003). Chained before
 * RunAgentTask; if the copy can't be made, the chain stops and the task's chat says why.
 */
class ForkTaskSandbox implements ShouldQueue
{
    use Queueable;

    /** Copying a large workspace out and back in, and the app's Docker images, takes a while. */
    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public Task $task) {}

    public function handle(TaskCopies $copies, AgentQueue $queue): void
    {
        $this->task->messages()->create(['role' => MessageRole::Activity, 'content' => 'Making a copy of the app for this task']);

        try {
            $shared = $copies->fork($this->task);
        } catch (SandboxException $e) {
            report($e);
            $this->task->sandbox()->update(['status' => SandboxStatus::Failed, 'error' => $e->getMessage()]);
            $this->task->messages()->create([
                'role' => MessageRole::Assistant,
                'content' => "I couldn't make this task's copy of the app: {$e->getMessage()}",
            ]);
            $queue->finished($this->task->fresh());
            $this->fail($e);

            return;
        }

        if ($shared !== []) {
            $this->task->messages()->create([
                'role' => MessageRole::Activity,
                'content' => 'This copy still uses '.Arr::join($shared, ', ', ' and ').' from the app\'s settings, shared with Main',
            ]);
        }
    }

    /**
     * Never leave the task's copy stuck on "creating" or the chat on "Thinking…".
     */
    public function failed(?\Throwable $exception): void
    {
        $this->task->sandbox()->where('status', SandboxStatus::Creating)->update(['status' => SandboxStatus::Failed, 'error' => $exception?->getMessage()]);

        if ($this->task->fresh()?->isWorking()) {
            app(AgentQueue::class)->finished($this->task->fresh());
        }
    }
}
