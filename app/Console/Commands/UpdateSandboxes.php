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
                // A sandbox stopped outside the app (Docker restarted) is still marked running: start it to read its tools.
                $woken = $project->sandbox->external_id;
                $woke = $woken !== null && $provider->wake($woken);

                $changed = $updater->updateIfOutdated($project, wait: true, options: ['suspend' => true]);

                if ($changed) {
                    $updated++;
                    $this->components->info("Updated project {$project->id}.");
                }

                // Nobody is watching a batch update: let the sandbox stop using compute (memory kept, woken by the next
                // visit), so a run of updates doesn't leave every sandbox it touched running at once.
                if ($woke || $changed) {
                    $this->suspend($provider, $project, $woke ? $woken : null);
                }
            } catch (SandboxException $e) {
                $failed++;
                $this->components->error("Project {$project->id}: {$e->getMessage()}");
            }
        }

        $this->components->info($updated === 0 && $failed === 0 ? 'Every sandbox is up to date.' : "Updated {$updated} sandbox(es).");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Suspend the project's sandbox and say so, so the idle check stops it later (SBX-007). One this command started
     * may still be marked suspended or stopped from before: left so, it would run with nothing ever stopping it.
     */
    protected function suspend(SandboxProvider $provider, Project $project, ?string $woken): void
    {
        $sandbox = $project->sandbox()->first();

        // A move to a new sandbox suspends it itself.
        if ($sandbox?->external_id === null || ($sandbox->suspended_at !== null && $sandbox->external_id !== $woken)) {
            return;
        }

        try {
            $provider->suspend($sandbox->external_id);
            $sandbox->forceFill(['suspended_at' => now(), 'stopped_at' => null])->saveQuietly();
        } catch (SandboxException $e) {
            // It pauses by itself once idle; this only makes it sooner.
            $this->components->warn("Project {$project->id}: couldn't suspend the new sandbox: {$e->getMessage()}");
        }
    }
}
