<?php

namespace App\Jobs;

use App\Enums\SandboxStatus;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\SandboxException;
use App\Sandbox\WorkspaceSsh;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncSshKeys implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public User $user) {}

    /**
     * Put the user's current SSH keys into each of their running sandboxes, so added keys work and deleted ones stop working.
     */
    public function handle(WorkspaceSsh $ssh): void
    {
        Sandbox::query()
            ->whereIn('project_id', $this->user->projects()->select('id'))
            ->where('status', SandboxStatus::Running)
            ->whereNotNull('external_id')
            ->each(function (Sandbox $sandbox) use ($ssh) {
                try {
                    $ssh->sync($sandbox);
                } catch (SandboxException) {
                    // A sandbox that can't be reached gets its keys when it's next opened or recreated.
                }
            });
    }
}
