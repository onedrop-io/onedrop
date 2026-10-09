<?php

namespace App\Jobs;

use App\Sandbox\SandboxTemplates;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Starts building a provider's sandbox image (SBX-014): its size changed in Settings → Sandboxes, it was just set up,
 * or a new sandbox image was published. Only starts it; the provider builds it by itself.
 */
class BuildSandboxTemplate implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public int $uniqueFor = 60;

    public function __construct(public string $provider) {}

    public function uniqueId(): string
    {
        return $this->provider;
    }

    public function handle(SandboxTemplates $templates): void
    {
        if ($templates->builds($this->provider)) {
            $templates->build($this->provider);
        }
    }
}
