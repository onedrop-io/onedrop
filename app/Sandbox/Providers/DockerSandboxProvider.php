<?php

namespace App\Sandbox\Providers;

use App\Sandbox\ExecResult;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxProvider;
use App\Sandbox\SandboxSpec;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

/**
 * Local Docker containers, for development and laptop demos.
 */
class DockerSandboxProvider implements SandboxProvider
{
    /**
     * Label put on every container so they can be found and cleaned up.
     */
    public const LABEL = 'zap.sandbox';

    /**
     * @param  array{image: string, memory: string, cpus: string, host: string}  $config
     */
    public function __construct(protected array $config) {}

    public function create(SandboxSpec $spec): string
    {
        $command = [
            'docker', 'run', '--detach',
            '--name', $spec->name,
            '--label', self::LABEL.'=1',
            '--memory', $this->config['memory'],
            '--cpus', $this->config['cpus'],
            '--publish', "127.0.0.1::{$spec->port}",
            '--env', "PORT={$spec->port}",
        ];

        if ($spec->proxyPort) {
            array_push($command, '--publish', "127.0.0.1::{$spec->proxyPort}", '--env', "PROXY_PORT={$spec->proxyPort}");
        }

        if ($spec->shellPort) {
            array_push($command, '--publish', "127.0.0.1::{$spec->shellPort}", '--env', "SHELL_PORT={$spec->shellPort}");
        }

        // Pass names only; docker reads the values from its own environment so
        // secrets never appear in the process list.
        foreach (array_keys($spec->env) as $name) {
            array_push($command, '--env', $name);
        }

        $command[] = $this->config['image'];

        $result = Process::env($spec->env)->timeout(60)->run($command);

        if ($result->failed()) {
            throw new SandboxException($this->explain($result));
        }

        return trim($result->output());
    }

    public function start(string $id): void
    {
        $this->docker(['start', $id]);
    }

    public function pause(string $id): void
    {
        $this->docker(['stop', $id]);
    }

    public function exec(string $id, array $command, array $env = [], bool $detach = false): ExecResult
    {
        $args = ['docker', 'exec'];

        if ($detach) {
            $args[] = '--detach';
        }

        foreach (array_keys($env) as $name) {
            array_push($args, '--env', $name);
        }

        $result = Process::env($env)->timeout(120)->run([...$args, $id, ...$command]);

        return new ExecResult($result->exitCode() ?? 1, $result->output(), $result->errorOutput());
    }

    public function previewUrl(string $id, int $port): ?string
    {
        $result = Process::timeout(15)->run(['docker', 'port', $id, (string) $port]);

        if ($result->failed()) {
            return null;
        }

        // e.g. "127.0.0.1:55012" (first line; IPv6 bindings may follow)
        $binding = strtok(trim($result->output()), "\n");
        $hostPort = substr($binding, strrpos($binding, ':') + 1);

        return ctype_digit($hostPort) ? "http://{$this->config['host']}:{$hostPort}" : null;
    }

    public function destroy(string $id): void
    {
        $result = Process::timeout(30)->run(['docker', 'rm', '--force', $id]);

        if ($result->failed() && ! str_contains($result->errorOutput(), 'No such container')) {
            throw new SandboxException($this->explain($result));
        }
    }

    /**
     * Run a docker command, throwing on failure.
     *
     * @param  list<string>  $args
     */
    protected function docker(array $args): void
    {
        $result = Process::timeout(60)->run(['docker', ...$args]);

        if ($result->failed()) {
            throw new SandboxException($this->explain($result));
        }
    }

    /**
     * Turn docker's stderr into something a user can act on.
     */
    protected function explain(ProcessResult $result): string
    {
        $error = trim($result->errorOutput());

        return match (true) {
            str_contains($error, 'Cannot connect to the Docker daemon'),
            str_contains($error, 'docker daemon is not running') => 'Docker is not running. Start Docker and try again.',
            str_contains($error, 'Unable to find image'),
            str_contains($error, 'pull access denied') => "The sandbox image [{$this->config['image']}] is missing. Run `php artisan sandbox:build-image`.",
            $error === '' => 'Docker failed with no output.',
            default => 'Docker error: '.strtok($error, "\n"),
        };
    }
}
