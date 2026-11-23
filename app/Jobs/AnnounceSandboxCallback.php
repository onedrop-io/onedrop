<?php

namespace App\Jobs;

use App\Sandbox\SandboxCallback;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class AnnounceSandboxCallback implements ShouldQueue
{
    use Queueable;

    /**
     * A new dev tunnel started (SBX-015): give every awake cloud sandbox its address, so running agents keep reporting back.
     */
    public function handle(SandboxCallback $callback): void
    {
        $callback->announceToAll();
    }
}
