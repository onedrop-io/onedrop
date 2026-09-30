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
     * Let an idle sandbox stop using compute until its next request (memory kept where the provider can).
     * Unlike pause(), the app's processes aren't frozen: they carry on when it wakes.
     *
     * @throws SandboxException
     */
    public function suspend(string $id): void;

    /**
     * Wake a suspended (or stopped) sandbox now, carrying on where it was, and say whether it had to: its addresses
     * may have changed. Providers that wake sandboxes by themselves on the next request do nothing.
     *
     * @throws SandboxException
     */
    public function wake(string $id): bool;

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
     * Whether the sandbox was made from an older image than new sandboxes get (e.g. before new guides or tools).
     *
     * @throws SandboxException
     */
    public function isOutdated(string $id): bool;

    /**
     * Copy a directory's contents out of a sandbox into a local directory.
     *
     * @throws SandboxException
     */
    public function copyOut(string $id, string $path, string $directory): void;

    /**
     * Copy a local directory's contents into a sandbox, owned by the sandbox user.
     *
     * @throws SandboxException
     */
    public function copyIn(string $id, string $directory, string $path): void;

    /**
     * Permanently delete a sandbox. Deleting one that doesn't exist is not an error.
     *
     * @throws SandboxException
     */
    public function destroy(string $id): void;
}
