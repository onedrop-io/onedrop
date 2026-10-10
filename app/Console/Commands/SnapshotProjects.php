<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\Sandbox;
use App\Sandbox\ProjectSnapshots;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxProvider;
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
    public function handle(ProjectSnapshots $snapshots, SandboxProvider $provider): int
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
            if (! $this->option('prune') && ! $this->snapshot($snapshots, $provider, $project)) {
                $failed++;
            }

            if ($deleted = $snapshots->prune($project)) {
                $this->components->info("Project {$project->id}: deleted {$deleted} old snapshot(s).");
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Snapshot the project, unless the daily run finds it unused since its latest snapshot. False when it failed.
     */
    protected function snapshot(ProjectSnapshots $snapshots, SandboxProvider $provider, Project $project): bool
    {
        $sandbox = $project->sandbox()->first();
        $latest = $snapshots->latest($project);

        // Unused since its latest snapshot (taken once it went unused): nothing new to keep, and taking one would wake it.
        if (! $this->argument('project') && $latest && $sandbox?->last_active_at?->lte($latest->created_at)) {
            $this->components->info("Project {$project->id}: unused since snapshot {$latest->id}.");

            return true;
        }

        try {
            $snapshot = $snapshots->take($project, $this->argument('project') ? 'manual' : 'daily');
            $this->components->info($snapshot
                ? "Project {$project->id}: snapshot {$snapshot->id} (".number_format($snapshot->size / 1048576, 1).' MB).'
                : "Project {$project->id}: nothing to snapshot.");
        } catch (SandboxException $e) {
            $this->components->error("Project {$project->id}: {$e->getMessage()}");

            return false;
        } finally {
            $this->suspendAgain($provider, $sandbox);
        }

        return true;
    }

    /**
     * Taking a snapshot wakes a suspended sandbox: put it back to sleep, unless someone woke it meanwhile (SBX-007).
     */
    protected function suspendAgain(SandboxProvider $provider, ?Sandbox $sandbox): void
    {
        if ($sandbox?->suspended_at === null || $sandbox->external_id === null || $sandbox->fresh()?->suspended_at === null) {
            return;
        }

        try {
            $provider->suspend($sandbox->external_id);
            // Stopped later like any suspended one.
            $sandbox->forceFill(['suspended_at' => now(), 'stopped_at' => null])->saveQuietly();
        } catch (SandboxException $e) {
            // It pauses by itself once idle (E2B), or at the next idle check (Docker); this only makes it sooner.
            report($e);
        }
    }
}
