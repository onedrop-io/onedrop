<?php

namespace App\Jobs;

use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Models\Message;
use App\Models\Project;
use App\Sandbox\Agents\AgentQueue;
use App\Sandbox\Agents\AgentRunner;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxUpdater;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RunAgentTask implements ShouldQueue
{
    use Queueable;

    /** Room to update an outdated sandbox before the run starts. */
    public int $timeout = 900;

    /**
     * Create a new job instance.
     */
    public function __construct(public Project $project, public Message $message) {}

    /**
     * Mark the message's conversation (the main chat or its task) busy, copy in the sandbox's changed tool files (so the
     * agent has the current guides and tools) unless another of the project's agents is running in it, and hand the message to the agent.
     */
    public function handle(AgentRunner $agent, SandboxUpdater $updater): void
    {
        $conversation = $this->message->conversation();
        $conversation->update(['status' => ProjectStatus::Working]);

        try {
            // A task's own copy is new; only the main sandbox is brought up to date here, and only with its tool files
            // (seconds): a new sandbox waits until nobody's using it (SBX-002).
            if ($conversation->agentSandbox()?->task_id === null && ! $this->project->mainSandboxBusy(except: $conversation) && $updater->updateIfOutdated($this->project, rebuild: false)) {
                $this->project->unsetRelation('sandbox');
            }
        } catch (SandboxException $e) {
            // Run in the sandbox as it is; the agent reports it if it can't.
            report($e);
        }

        $agent->start($this->project, $this->message);
    }

    /**
     * Never leave the chat stuck on "Thinking…" if the job itself crashes.
     */
    public function failed(?Throwable $exception): void
    {
        $conversation = $this->message->conversation();

        $conversation->messages()->create([
            'role' => MessageRole::Assistant,
            'content' => "The agent couldn't start. Please try again.",
        ]);
        app(AgentQueue::class)->finished($conversation);
    }
}
