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
     * Mark the project busy, bring its sandbox up to date (so the agent has the current guides and tools),
     * and hand the message to the agent.
     */
    public function handle(AgentRunner $agent, SandboxUpdater $updater): void
    {
        $this->project->update(['status' => ProjectStatus::Working]);

        try {
            if ($updater->updateIfOutdated($this->project)) {
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
        $this->project->messages()->create([
            'role' => MessageRole::Assistant,
            'content' => "The agent couldn't start. Please try again.",
        ]);
        app(AgentQueue::class)->finished($this->project);
    }
}
