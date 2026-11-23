<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * More of the reply or thinking an agent is writing (AGT-017), for the chat to show as it's written. `seq` counts the
 * pieces of one block from 1, so a page that missed one reloads the text so far instead of showing a gap.
 */
class AgentStreamed implements ShouldBroadcastNow, ShouldRescue
{
    use Dispatchable;

    /**
     * @param  string  $chat  "main", or "task-<id>" for a task's chat
     * @param  string  $kind  "text" or "thinking"
     */
    public function __construct(
        public int $projectId,
        public string $chat,
        public string $block,
        public string $kind,
        public int $seq,
        public string $delta,
    ) {}

    /**
     * The name pages listen for (without the class's namespace).
     */
    public function broadcastAs(): string
    {
        return 'AgentStreamed';
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("project.{$this->projectId}")];
    }

    /**
     * @return array{chat: string, block: string, kind: string, seq: int, delta: string}
     */
    public function broadcastWith(): array
    {
        return [
            'chat' => $this->chat,
            'block' => $this->block,
            'kind' => $this->kind,
            'seq' => $this->seq,
            'delta' => $this->delta,
        ];
    }
}
