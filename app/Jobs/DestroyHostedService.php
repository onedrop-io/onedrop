<?php

namespace App\Jobs;

use App\Sandbox\Hosting\HostedServices;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DestroyHostedService implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** Seconds between tries: an R2 bucket is only deleted once it's empty, which runs in the background. */
    public int $backoff = 60;

    /**
     * Create a new job instance.
     *
     * @param  array{kind: string, provider: string, owner: string, organization_id: int, name: string, external_id: string|null, details: array<string, mixed>, app: string|null}  $service  what was kept of it (its record is gone by the time this runs)
     */
    public function __construct(public array $service) {}

    /**
     * Delete something made for a hosted project at its provider (HOST-002).
     */
    public function handle(HostedServices $services): void
    {
        $services->destroy($this->service);
    }
}
