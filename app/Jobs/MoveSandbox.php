<?php

namespace App\Jobs;

use App\Models\Project;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxUpdater;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Moves a project's sandbox to the computer it was just pointed at, or back to the install's provider (DESK-010),
 * keeping its files: the update an outdated sandbox gets, now rather than once it sits unused. If it can't, the
 * project stays where it was, and the "This computer" panel says why.
 */
class MoveSandbox implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Copying a large workspace out and back in takes a while. */
    public int $timeout = 900;

    public int $tries = 1;

    /**
     * @param  int|null  $from  the computer it ran on before (null: the install's provider), to go back to on failure
     */
    public function __construct(public Project $project, public ?int $from) {}

    public function uniqueId(): string
    {
        return (string) $this->project->id;
    }

    /**
     * Why the project's last move failed, for the panel; cleared by the next one.
     */
    public static function error(Project $project): ?string
    {
        return Cache::get("sandbox-move-error:{$project->id}");
    }

    public function handle(SandboxUpdater $updater): void
    {
        Cache::forget("sandbox-move-error:{$this->project->id}");

        try {
            $updater->updateIfOutdated($this->project->fresh());
        } catch (SandboxException $e) {
            $this->project->update(['device_id' => $this->from]);
            Cache::put("sandbox-move-error:{$this->project->id}", $e->getMessage(), now()->addDay());
        }
    }
}
