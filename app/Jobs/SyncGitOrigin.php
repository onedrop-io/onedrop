<?php

namespace App\Jobs;

use App\Models\Project;
use App\Models\Sandbox;
use App\Sandbox\GitOrigin;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Brings `origin` up to date (GIT-021) in a project's running sandboxes after its remote changed, or in one sandbox
 * that just woke up.
 */
class SyncGitOrigin implements ShouldQueue
{
    use Queueable;

    public function __construct(public Project|Sandbox $for) {}

    public function handle(GitOrigin $origin): void
    {
        $this->for instanceof Project
            ? $origin->syncProject($this->for)
            : $origin->syncQuietly($this->for);
    }
}
