<?php

namespace App\Jobs;

use App\Enums\SandboxStatus;
use App\Models\Project;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxProvider;
use App\Sandbox\SandboxUpdater;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Brings a project's sandbox up to date once nobody has used it for a while (SBX-002). Queued, delayed, whenever the
 * sandbox is used (Sandbox::markActive()), and put back while it's still in use, so an update never runs on someone's
 * time, and a sandbox nobody uses is never woken for one.
 */
class UpdateSandbox implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** How long a sandbox goes unused before it's updated. */
    public const IDLE_SECONDS = 600;

    /** Copying a large workspace out and back in takes a while. */
    public int $timeout = 900;

    /** Put back as often as it takes while the project is in use. */
    public int $tries = 0;

    /** Seconds before another update of the same project may be queued, should this one be lost. */
    public int $uniqueFor = 3600;

    /**
     * Create a new job instance.
     */
    public function __construct(public Project $project) {}

    public function uniqueId(): string
    {
        return (string) $this->project->id;
    }

    public function handle(SandboxUpdater $updater, SandboxProvider $provider): void
    {
        $project = $this->project->fresh();
        $sandbox = $project?->sandbox()->first();

        if ($sandbox?->status !== SandboxStatus::Running || $sandbox->external_id === null) {
            return;
        }

        $unusedFor = (int) ($sandbox->last_active_at ?? $sandbox->updated_at)->diffInSeconds(now());

        if ($unusedFor < self::IDLE_SECONDS || $project->mainSandboxBusy()) {
            $this->release(max(self::IDLE_SECONDS - $unusedFor, 60));

            return;
        }

        try {
            if ($updater->updateIfOutdated($project)) {
                $this->suspend($provider, $project);
            }
        } catch (SandboxException $e) {
            report($e);
        }
    }

    /**
     * Nobody is using it: let the (possibly new) sandbox stop using compute again, memory kept, woken by the next visit.
     */
    protected function suspend(SandboxProvider $provider, Project $project): void
    {
        $sandbox = $project->sandbox()->first();

        try {
            if ($sandbox?->external_id) {
                $provider->suspend($sandbox->external_id);
                // Stopped later like any suspended one (SBX-007), even if it had been stopped before the update woke it.
                $sandbox->forceFill(['suspended_at' => now(), 'stopped_at' => null])->saveQuietly();
            }
        } catch (SandboxException $e) {
            // It pauses by itself once idle (Docker: SBX-007); this only makes it sooner.
            report($e);
        }
    }
}
