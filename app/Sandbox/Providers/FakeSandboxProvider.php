<?php

namespace App\Sandbox\Providers;

use App\Sandbox\ExecResult;
use App\Sandbox\SandboxProvider;
use App\Sandbox\SandboxSpec;
use Illuminate\Support\Str;

/**
 * In-memory provider for tests: every sandbox "runs" instantly.
 */
class FakeSandboxProvider implements SandboxProvider
{
    /** @var array<string, SandboxSpec> */
    public array $created = [];

    /** @var list<array{id: string, command: list<string>, env: array<string, string>, detach: bool}> */
    public array $executed = [];

    /**
     * Optional stub for exec results: fn (list<string> $command, array<string, string> $env): ExecResult.
     *
     * @var (callable(list<string>, array<string, string>): ExecResult)|null
     */
    public $execUsing = null;

    /** @var list<string> ids isOutdated() reports as outdated */
    public array $outdated = [];

    /** @var list<array{string, string, string, string}> [direction, id, path, directory] */
    public array $copied = [];

    public function create(SandboxSpec $spec): string
    {
        $id = 'fake-'.Str::random(8);
        $this->created[$id] = $spec;

        return $id;
    }

    /** @var list<string> ids paused, in order */
    public array $paused = [];

    /** @var list<string> ids started again, in order */
    public array $started = [];

    public function start(string $id): void
    {
        $this->started[] = $id;
    }

    public function pause(string $id): void
    {
        $this->paused[] = $id;
    }

    /** @var list<string> ids suspended, in order */
    public array $suspended = [];

    public function suspend(string $id): void
    {
        $this->suspended[] = $id;
    }

    public function exec(string $id, array $command, array $env = [], bool $detach = false): ExecResult
    {
        $this->executed[] = ['id' => $id, 'command' => $command, 'env' => $env, 'detach' => $detach];

        return $this->execUsing ? ($this->execUsing)($command, $env) : new ExecResult(0, '');
    }

    public function previewUrl(string $id, int $port): ?string
    {
        return null;
    }

    public function isOutdated(string $id): bool
    {
        return in_array($id, $this->outdated, true);
    }

    public function copyOut(string $id, string $path, string $directory): void
    {
        $this->copied[] = ['out', $id, $path, $directory];
    }

    public function copyIn(string $id, string $directory, string $path): void
    {
        $this->copied[] = ['in', $id, $path, $directory];
    }

    public function destroy(string $id): void
    {
        unset($this->created[$id]);
    }
}
