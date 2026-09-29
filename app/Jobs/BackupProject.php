<?php

namespace App\Jobs;

use App\Models\Project;
use App\Sandbox\ProjectBackups;
use App\Sandbox\SandboxException;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class BackupProject implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /** Copying a large history out of a sandbox takes a while. */
    public int $timeout = 600;

    /**
     * Create a new job instance.
     */
    public function __construct(public Project $project) {}

    /**
     * One waiting backup per project is enough: it copies whatever is latest when it runs.
     */
    public function uniqueId(): string
    {
        return (string) $this->project->id;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping((string) $this->project->id))->releaseAfter(30)->expireAfter($this->timeout)];
    }

    /**
     * Copy the project's git history out of its sandbox after an agent turn.
     */
    public function handle(ProjectBackups $backups): void
    {
        try {
            $backups->backUp($this->project->fresh() ?? $this->project);
        } catch (SandboxException $e) {
            // The next turn tries again.
            report($e);
        }
    }
}
