<?php

namespace App\Jobs;

use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Models\Message;
use App\Models\Project;
use App\Sandbox\Agents\AgentRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RunAgentTask implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public Project $project, public Message $message) {}

    /**
     * Mark the project busy and hand the message to the agent.
     */
    public function handle(AgentRunner $agent): void
    {
        $this->project->update(['status' => ProjectStatus::Working]);

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
        $this->project->update(['status' => ProjectStatus::Idle]);
    }
}
