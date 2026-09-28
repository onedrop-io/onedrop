<?php

namespace App\Jobs;

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
     */
    public function __construct(public string $externalId) {}

    /**
     * Delete a sandbox whose project is gone, files and all.
     */
    public function handle(SandboxProvider $provider): void
    {
        $provider->destroy($this->externalId);
    }
}
