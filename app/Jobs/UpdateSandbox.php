<?php

namespace App\Jobs;

use App\Models\Project;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxUpdater;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class UpdateSandbox implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Copying a large workspace out and back in takes a while. */
    public int $timeout = 900;

    /** Seconds before another update of the same project may be queued. */
    public int $uniqueFor = 900;

    /**
     * Create a new job instance.
     */
    public function __construct(public Project $project) {}

    public function uniqueId(): string
    {
        return (string) $this->project->id;
    }

    /**
     * Move the project's sandbox to the current image if it's outdated, unless the agent is mid-run
     * (the next run updates it first; see RunAgentTask).
     */
    public function handle(SandboxUpdater $updater): void
    {
        try {
            if ($this->project->fresh()?->mainSandboxBusy() === false) {
                $updater->updateIfOutdated($this->project);
            }
        } catch (SandboxException $e) {
            report($e);
        } finally {
            SandboxUpdater::doneUpdating($this->project);
        }
    }
}
