<?php

namespace App\Jobs;

use App\Enums\MessageRole;
use App\Sandbox\Agents\AgentQueue;
use App\Sandbox\Agents\Conversation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class AppendAgentEvent implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Conversation $conversation,
        public MessageRole $role,
        public string $content,
        public bool $finished = false,
    ) {}

    /**
     * Store one agent event in its conversation's chat.
     */
    public function handle(): void
    {
        $this->conversation->messages()->create([
            'role' => $this->role,
            'content' => $this->content,
        ]);

        if ($this->finished) {
            app(AgentQueue::class)->finished($this->conversation, succeeded: true);
        }
    }
}
