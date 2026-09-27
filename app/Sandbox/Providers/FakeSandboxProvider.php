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

    /** @var list<array{id: string, command: list<string>, detach: bool}> */
    public array $executed = [];

    /**
     * Optional stub for exec results: fn (list<string> $command): ExecResult.
     *
     * @var (callable(list<string>): ExecResult)|null
     */
    public $execUsing = null;

    public function create(SandboxSpec $spec): string
    {
        $id = 'fake-'.Str::random(8);
        $this->created[$id] = $spec;

        return $id;
    }

    public function start(string $id): void {}

    public function pause(string $id): void {}

    public function exec(string $id, array $command, array $env = [], bool $detach = false): ExecResult
    {
        $this->executed[] = ['id' => $id, 'command' => $command, 'detach' => $detach];

        return $this->execUsing ? ($this->execUsing)($command) : new ExecResult(0, '');
    }

    public function previewUrl(string $id, int $port): ?string
    {
        return null;
    }

    public function destroy(string $id): void
    {
        unset($this->created[$id]);
    }
}
