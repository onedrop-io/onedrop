<?php

namespace App\Console\Commands;

use App\Enums\SandboxStatus;
use App\Models\Project;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxProvider;
use App\Sandbox\SandboxUpdater;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('sandbox:update {project? : Project id (default: every project)}')]
#[Description('Bring sandboxes up to date now: copy in changed tool files, or move them to the current image keeping their files. Skips projects the agent is working on.')]
class UpdateSandboxes extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(SandboxUpdater $updater, SandboxProvider $provider): int
    {
        $projects = Project::query()
            ->when($this->argument('project'), fn ($query, $id) => $query->whereKey($id))
            ->whereHas('sandbox', fn ($query) => $query->where('status', SandboxStatus::Running))
            ->get();
        $updated = 0;
        $failed = 0;

        foreach ($projects as $project) {
            if ($project->mainSandboxBusy()) {
                $this->components->warn("Skipped project {$project->id}: the agent is working on it.");

                continue;
            }

            try {
                if ($updater->updateIfOutdated($project, wait: true, options: ['suspend' => true])) {
                    $updated++;
                    $this->components->info("Updated project {$project->id}.");

                    // Nobody is watching a batch update: let the new sandbox stop using compute (memory kept, woken by
                    // the next visit), so a run of updates doesn't keep every new sandbox running at once.
                    $this->suspend($provider, $project);
                }
            } catch (SandboxException $e) {
                $failed++;
                $this->components->error("Project {$project->id}: {$e->getMessage()}");
            }
        }

        $this->components->info($updated === 0 && $failed === 0 ? 'Every sandbox is up to date.' : "Updated {$updated} sandbox(es).");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    protected function suspend(SandboxProvider $provider, Project $project): void
    {
        $sandbox = $project->sandbox()->first();
        // A move to a new sandbox suspends it itself.
        $id = $sandbox?->suspended_at === null ? $sandbox?->external_id : null;

        try {
            if ($id) {
                $provider->suspend($id);
            }
        } catch (SandboxException $e) {
            // It pauses by itself once idle; this only makes it sooner.
            $this->components->warn("Project {$project->id}: couldn't suspend the new sandbox: {$e->getMessage()}");
        }
    }
}
