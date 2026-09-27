<?php

namespace App\Sandbox;

final readonly class ExecResult
{
    public function __construct(
        public int $exitCode,
        public string $output,
        public string $errorOutput = '',
    ) {}

    /**
     * Whether the command exited cleanly.
     */
    public function successful(): bool
    {
        return $this->exitCode === 0;
    }
}
