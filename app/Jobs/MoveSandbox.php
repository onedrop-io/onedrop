<?php

namespace App\Jobs;

use App\Enums\SandboxMovePhase;
use App\Models\Project;
use App\Models\SandboxMove;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxUpdater;
use App\Sandbox\SandboxWaitLimit;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Moves a project's sandbox to the computer it was just pointed at, or back to the install's provider (DESK-010),
 * keeping its files: the move an outdated sandbox gets (SandboxMover), now rather than once it sits unused. If it
 * can't, the project stays where it was, and the "This computer" panel says why.
 */
class MoveSandbox implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 85;

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
        $move = SandboxMove::query()->where('project_id', $project->id)->where('reason', 'computer')->latest('id')->first();

        return Cache::get("sandbox-move-error:{$project->id}") ?? ($move?->phase === SandboxMovePhase::Failed ? $move->error : null);
    }

    public function handle(SandboxUpdater $updater, ?SandboxWaitLimit $limit = null): void
    {
        // Provider calls end in time for a Flex queue job, even one run inside a request or another job (a sync queue).
        ($limit ?? new SandboxWaitLimit)->during(MoveProjectSandbox::WAIT_SECONDS, fn () => $this->move($updater));
    }

    protected function move(SandboxUpdater $updater): void
    {
        Cache::forget("sandbox-move-error:{$this->project->id}");

        try {
            // The move itself is queued; if it fails, it puts the project back on $from.
            $updater->updateIfOutdated($this->project->fresh(), reason: 'computer', options: ['revert_device_id' => $this->from]);
        } catch (SandboxException $e) {
            $this->project->update(['device_id' => $this->from]);
            Cache::put("sandbox-move-error:{$this->project->id}", $e->getMessage(), now()->addDay());
        }
    }
}
