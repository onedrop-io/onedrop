<?php

namespace App\Jobs;

use App\Enums\SandboxStatus;
use App\Models\Sandbox;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxProvider;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;

/**
 * Sign Claude Code out for a user who stopped using their Claude subscription (or deleted their
 * account): `claude auth logout` in their running sandboxes, then empty their shared login folder
 * (DockerSandboxProvider::CLAUDE_MOUNT). The folder itself stays: running sandboxes have it mounted, and
 * deleting it would leave them a dead mount that no later sign-in can write to. The platform never reads
 * the login; it only removes it.
 */
class SignOutOfClaude implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public int $userId) {}

    /**
     * Execute the job.
     */
    public function handle(SandboxProvider $provider): void
    {
        $sandboxes = Sandbox::query()
            ->whereHas('project', fn ($projects) => $projects->where('user_id', $this->userId))
            ->where('status', SandboxStatus::Running)
            ->whereNotNull('external_id')
            ->get();

        foreach ($sandboxes as $sandbox) {
            try {
                $provider->exec($sandbox->external_id, ['claude', 'auth', 'logout']);
            } catch (SandboxException) {
                // Unreachable: nothing to sign out of there right now, and the folder goes below.
            }
        }

        $root = config('sandbox.providers.docker.storage_path');

        if (filled($root)) {
            File::cleanDirectory(rtrim($root, '/')."/user-{$this->userId}/claude");
        }
    }
}
