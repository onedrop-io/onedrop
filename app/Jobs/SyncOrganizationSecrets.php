<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\OrganizationSecrets;
use App\Sandbox\SandboxException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Brings the organization's secrets (SECRET-003) up to date in its running sandboxes after they changed, in a
 * person's projects' running sandboxes after their GitHub connection changed (GIT-016), or in one sandbox that just
 * woke up.
 */
class SyncOrganizationSecrets implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public Organization|User $for, public ?Sandbox $sandbox = null) {}

    public function handle(OrganizationSecrets $secrets): void
    {
        if ($this->sandbox === null) {
            $secrets->syncAwake($this->for);

            return;
        }

        try {
            $secrets->sync($this->sandbox);
        } catch (SandboxException $e) {
            report($e);
        }
    }
}
