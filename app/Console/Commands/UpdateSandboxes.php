<?php

namespace App\Console\Commands;

use App\Enums\ProjectStatus;
use App\Enums\SandboxStatus;
use App\Models\Project;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxUpdater;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('sandbox:update {project? : Project id (default: every project)}')]
#[Description('Move outdated sandboxes to the current image, keeping their files. Skips projects the agent is working on.')]
class UpdateSandboxes extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(SandboxUpdater $updater): int
    {
        $projects = Project::query()
            ->when($this->argument('project'), fn ($query, $id) => $query->whereKey($id))
            ->whereHas('sandbox', fn ($query) => $query->where('status', SandboxStatus::Running))
            ->get();
        $updated = 0;
        $failed = 0;

        foreach ($projects as $project) {
            if ($project->status === ProjectStatus::Working) {
                $this->components->warn("Skipped project {$project->id}: the agent is working on it.");

                continue;
            }

            try {
                if ($updater->updateIfOutdated($project)) {
                    $updated++;
                    $this->components->info("Updated project {$project->id}.");
                }
            } catch (SandboxException $e) {
                $failed++;
                $this->components->error("Project {$project->id}: {$e->getMessage()}");
            }
        }

        $this->components->info($updated === 0 && $failed === 0 ? 'Every sandbox is up to date.' : "Updated {$updated} sandbox(es).");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
