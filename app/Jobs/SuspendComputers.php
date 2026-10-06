<?php

namespace App\Jobs;

use App\Enums\ProjectKind;
use App\Enums\SandboxStatus;
use App\Models\Organization;
use App\Models\Sandbox;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxProvider;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Put an organization's running computers to sleep once its owners turned computers off (CMP-003). They're kept, so
 * turning computers on again gives everyone theirs back.
 */
class SuspendComputers implements ShouldQueue
{
    use Queueable;

    public function __construct(public Organization $organization) {}

    public function handle(SandboxProvider $provider): void
    {
        Sandbox::query()
            ->whereHas('project', fn ($query) => $query->where('organization_id', $this->organization->id)->where('kind', ProjectKind::Computer))
            ->where('status', SandboxStatus::Running)
            ->whereNotNull('external_id')
            ->whereNull('suspended_at')
            ->each(function (Sandbox $sandbox) use ($provider) {
                try {
                    $provider->suspend($sandbox->external_id);
                    $sandbox->forceFill(['suspended_at' => now()])->saveQuietly();
                } catch (SandboxException $e) {
                    report($e);
                }
            });
    }
}
