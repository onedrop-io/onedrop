<?php

namespace App\Jobs;

use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Models\Project;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class AppendAgentEvent implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Project $project,
        public MessageRole $role,
        public string $content,
        public bool $finished = false,
    ) {}

    /**
     * Store one agent event in the project's chat.
     */
    public function handle(): void
    {
        $this->project->messages()->create([
            'role' => $this->role,
            'content' => $this->content,
        ]);

        if ($this->finished) {
            $this->project->update(['status' => ProjectStatus::Idle]);
        }
    }
}
