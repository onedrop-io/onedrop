<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Sandbox\ProjectSnapshots;
use App\Sandbox\SandboxException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('sandbox:snapshot {project? : Project id (default: every project whose sandbox was used in the last day)} {--prune : Only delete old snapshots}')]
#[Description('Take a snapshot of projects\' whole state (SBX-009), then delete snapshots no longer kept.')]
class SnapshotProjects extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ProjectSnapshots $snapshots): int
    {
        $projects = Project::query()
            ->when(
                $this->argument('project'),
                fn ($query, $id) => $query->whereKey($id),
                // Nothing changes in a sandbox nobody used, and snapshotting it would wake it. A computer's Home isn't
                // backed up (CMP-001): what people keep goes in Drive.
                fn ($query) => $query->apps()->whereHas('sandbox', fn ($sandbox) => $sandbox->where('last_active_at', '>', now()->subDay())),
            )
            ->get();
        $failed = 0;

        foreach ($projects as $project) {
            if (! $this->option('prune')) {
                try {
                    $snapshot = $snapshots->take($project, $this->argument('project') ? 'manual' : 'daily');
                    $this->components->info($snapshot
                        ? "Project {$project->id}: snapshot {$snapshot->id} (".number_format($snapshot->size / 1048576, 1).' MB).'
                        : "Project {$project->id}: nothing to snapshot.");
                } catch (SandboxException $e) {
                    $failed++;
                    $this->components->error("Project {$project->id}: {$e->getMessage()}");
                }
            }

            if ($deleted = $snapshots->prune($project)) {
                $this->components->info("Project {$project->id}: deleted {$deleted} old snapshot(s).");
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
