<?php

namespace App\Console\Commands;

use App\Enums\SandboxStatus;
use App\Models\Project;
use App\Models\ProjectSnapshot;
use App\Sandbox\ProjectSnapshots;
use App\Sandbox\SandboxUpdater;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('sandbox:restore {project : Project id} {snapshot? : Snapshot id (default: the latest)} {--list : List the project\'s snapshots}')]
#[Description('Give a project a new sandbox with the files, dependencies, home folder and App Storage of one of its snapshots (SBX-009).')]
class RestoreSnapshot extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(SandboxUpdater $updater, ProjectSnapshots $snapshots): int
    {
        $project = Project::findOrFail($this->argument('project'));

        if ($this->option('list')) {
            $this->table(['Id', 'Taken', 'Reason', 'Status', 'Size (MB)'], $project->snapshots()->whereNull('task_id')->latest('id')->get()
                ->map(fn ($snapshot) => [$snapshot->id, $snapshot->created_at?->toDateTimeString(), $snapshot->reason, $snapshot->status, number_format($snapshot->size / 1048576, 1)]));

            return self::SUCCESS;
        }

        $snapshot = $this->argument('snapshot')
            ? $project->snapshots()->whereNull('task_id')->where('status', ProjectSnapshot::READY)->findOrFail($this->argument('snapshot'))
            : $snapshots->latest($project);

        if ($snapshot === null) {
            $this->components->error("Project {$project->id} has no snapshots.");

            return self::FAILURE;
        }

        $sandbox = $updater->recreate($project, keepFiles: true, report: fn (string $message) => $this->components->info($message), from: $snapshot);

        $this->components->info("Restored snapshot {$snapshot->id} from {$snapshot->created_at}. Sandbox {$sandbox->status->value}.");

        return $sandbox->status === SandboxStatus::Running ? self::SUCCESS : self::FAILURE;
    }
}
