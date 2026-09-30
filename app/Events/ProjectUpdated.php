<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Something the project page shows changed (its chat, agent, sandbox, publishing, sharing, ...), so open pages reload
 * their live data (LIVE-001). Carries no data itself: the page fetches what it's allowed to see.
 */
class ProjectUpdated implements ShouldBroadcastNow, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable;

    public function __construct(public int $projectId) {}

    /**
     * Tell the project's open pages it changed. In a web request, once, after the response is sent (however many
     * changes it made); elsewhere (queue jobs, commands) straight away, so long jobs show their progress.
     */
    public static function signal(int $projectId): void
    {
        if (app()->runningInConsole()) {
            self::dispatch($projectId);

            return;
        }

        defer(fn () => self::dispatch($projectId), "project-updated-{$projectId}", always: true);
    }

    /**
     * The name pages listen for (without the class's namespace).
     */
    public function broadcastAs(): string
    {
        return 'ProjectUpdated';
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("project.{$this->projectId}")];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [];
    }
}
