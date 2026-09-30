<?php

namespace App\Events;

use App\Models\Sandbox;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A sandbox's file watcher saw files added, removed or renamed (FILE-004), so an open Files panel showing that
 * sandbox (Main's, or a task's copy) reloads its tree right away (LIVE-001).
 */
class ProjectFilesChanged implements ShouldBroadcastNow, ShouldRescue
{
    use Dispatchable;

    public function __construct(public Sandbox $sandbox) {}

    /**
     * The name pages listen for (without the class's namespace).
     */
    public function broadcastAs(): string
    {
        return 'ProjectFilesChanged';
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("project.{$this->sandbox->project_id}")];
    }

    /**
     * @return array{task: int|null, version: int}
     */
    public function broadcastWith(): array
    {
        return ['task' => $this->sandbox->task_id, 'version' => $this->sandbox->files_version];
    }
}
