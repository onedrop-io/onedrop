<?php

namespace App\Jobs;

use App\Models\Project;
use App\Sandbox\Hosting\HostingChanges;
use App\Sandbox\ProjectBackups;
use App\Sandbox\ProjectSnapshots;
use App\Sandbox\SandboxException;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Copy a project's git history out of its sandbox (SBX-006) and snapshot it (SBX-009). A project deleted while this waits has nothing left to back up.
 */
#[DeleteWhenMissingModels]
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
     * Copy the project's git history out of its sandbox after an agent turn, note what its hosted app is missing
     * (HOST-004), and take a snapshot of its whole state (SBX-009).
     */
    public function handle(ProjectBackups $backups, ProjectSnapshots $snapshots, HostingChanges $changes): void
    {
        $project = $this->project->fresh() ?? $this->project;

        try {
            $backups->backUp($project);
        } catch (SandboxException $e) {
            // The next turn tries again.
            report($e);
        }

        try {
            // What the hosted app is missing now (HOST-004).
            $changes->refresh($project);
        } catch (SandboxException $e) {
            report($e);
        }

        try {
            $snapshots->take($project, 'turn');
        } catch (SandboxException $e) {
            report($e);
        }
    }
}
