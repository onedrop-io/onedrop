<?php

namespace App\Sandbox;

/**
 * What to start: one app, one sandbox.
 */
final readonly class SandboxSpec
{
    /**
     * @param  string  $name  unique, provider-safe name
     * @param  array<string, string>  $env  secrets and settings injected at start (never baked into the image)
     * @param  int  $port  the port the app's dev server listens on
     * @param  int|null  $shellPort  the port the web terminal listens on, if any
     * @param  int|null  $proxyPort  the port of the host-rewriting proxy in front of the app, if any
     */
    public function __construct(
        public string $name,
        public array $env = [],
        public int $port = 8000,
        public ?int $shellPort = null,
        public ?int $proxyPort = null,
    ) {}
}
