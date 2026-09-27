<?php

namespace App\Sandbox;

/**
 * A sandbox backend (local Docker, E2B, Daytona, ...). Every method is a short
 * API call; nothing here waits on a long-running process (README "the one rule").
 */
interface SandboxProvider
{
    /**
     * Create and start a sandbox, returning the provider's id for it.
     *
     * @throws SandboxException
     */
    public function create(SandboxSpec $spec): string;

    /**
     * Start (resume) a paused sandbox.
     *
     * @throws SandboxException
     */
    public function start(string $id): void;

    /**
     * Pause a sandbox, keeping its disk.
     *
     * @throws SandboxException
     */
    public function pause(string $id): void;

    /**
     * Run a short command. Use $detach for anything long-running (agents, servers).
     *
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     *
     * @throws SandboxException
     */
    public function exec(string $id, array $command, array $env = [], bool $detach = false): ExecResult;

    /**
     * The URL where the app on the given port can be reached, if running.
     *
     * @throws SandboxException
     */
    public function previewUrl(string $id, int $port): ?string;

    /**
     * Permanently delete a sandbox. Deleting one that doesn't exist is not an error.
     *
     * @throws SandboxException
     */
    public function destroy(string $id): void;
}
