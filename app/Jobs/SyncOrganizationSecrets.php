<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Models\Sandbox;
use App\Sandbox\OrganizationSecrets;
use App\Sandbox\SandboxException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Brings the organization's secrets (SECRET-003) up to date in its running sandboxes after they changed, or in one
 * sandbox that just woke up.
 */
class SyncOrganizationSecrets implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public Organization $organization, public ?Sandbox $sandbox = null) {}

    public function handle(OrganizationSecrets $secrets): void
    {
        if ($this->sandbox === null) {
            $secrets->syncAwake($this->organization);

            return;
        }

        try {
            $secrets->sync($this->sandbox);
        } catch (SandboxException $e) {
            report($e);
        }
    }
}
