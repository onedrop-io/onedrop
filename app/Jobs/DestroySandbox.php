<?php

namespace App\Jobs;

use App\Sandbox\Providers\RoutingSandboxProvider;
use App\Sandbox\SandboxProvider;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DestroySandbox implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    /**
     * Create a new job instance.
     *
     * @param  string  $provider  the provider the sandbox was created on (its record is gone by the time this runs)
     */
    public function __construct(public string $externalId, public string $provider) {}

    /**
     * Delete a sandbox whose project is gone, files and all.
     */
    public function handle(SandboxProvider $sandboxes): void
    {
        if ($sandboxes instanceof RoutingSandboxProvider) {
            $sandboxes = $sandboxes->provider($this->provider);
        }

        $sandboxes->destroy($this->externalId);
    }
}
