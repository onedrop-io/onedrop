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

    /** Where App Storage lives inside the sandbox; backed by a host folder when storage_path is set. */
    public const STORAGE_MOUNT = '/data/storage';

    /**
     * @param  array{image: string, memory: string, cpus: string, host: string, runtime?: ?string, network?: ?string, storage_path?: ?string}  $config
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
            // Linux Docker doesn't define host.docker.internal; sandboxes use it to report agent events.
            '--add-host', 'host.docker.internal:host-gateway',
        ];

        if (filled($this->config['runtime'] ?? null)) {
            array_push($command, '--runtime', $this->config['runtime']);
        }

        if (filled($this->config['network'] ?? null)) {
            array_push($command, '--network', $this->config['network']);
        }

        if ($spec->proxyPort) {
            array_push($command, '--publish', "127.0.0.1::{$spec->proxyPort}", '--env', "PROXY_PORT={$spec->proxyPort}");
        }

        if ($spec->shellPort) {
            array_push($command, '--publish', "127.0.0.1::{$spec->shellPort}", '--env', "SHELL_PORT={$spec->shellPort}");
        }

        if ($spec->sshPort) {
            array_push($command, '--publish', "127.0.0.1::{$spec->sshPort}", '--env', "SSH_PORT={$spec->sshPort}");
        }

        $storage = $this->storageFolder($spec);

        if ($storage !== null) {
            array_push($command, '--mount', "type=bind,source={$storage},target=".self::STORAGE_MOUNT);
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

        $id = trim($result->output());

        // A new host folder is owned by the platform's user; let the sandbox user write to it. Best effort:
        // if this fails the sandbox still runs, and App Storage reports that it can't write.
        if ($storage !== null) {
            Process::timeout(30)->run(['docker', 'exec', '-u', 'root', $id, 'chown', 'sandbox:sandbox', self::STORAGE_MOUNT]);
        }

        return $id;
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
        $binding = explode("\n", trim($result->output()))[0];
        $hostPort = substr($binding, strrpos($binding, ':') + 1);

        return ctype_digit($hostPort) ? "http://{$this->config['host']}:{$hostPort}" : null;
    }

    public function isOutdated(string $id): bool
    {
        $current = Process::timeout(15)->run(['docker', 'image', 'inspect', '--format', '{{.Id}}', $this->config['image']]);
        $used = Process::timeout(15)->run(['docker', 'inspect', '--format', '{{.Image}}', $id]);

        // No image or no container: nothing to update to (or from).
        if ($current->failed() || $used->failed()) {
            return false;
        }

        // Made before App Storage moved to a host folder: recreate it so its buckets move there.
        return trim($current->output()) !== trim($used->output())
            || (filled($this->config['storage_path'] ?? null) && ! $this->mounts($id, self::STORAGE_MOUNT));
    }

    public function copyOut(string $id, string $path, string $directory): void
    {
        // A host folder mounted at this path outlives the container: nothing to carry over.
        if ($this->mounts($id, $path)) {
            return;
        }

        $result = Process::forever()->run(['docker', 'cp', "{$id}:{$path}/.", $directory]);

        // A path the sandbox never created (e.g. no App Storage yet) has nothing to copy.
        if ($result->failed() && ! str_contains($result->errorOutput(), 'Could not find the file')) {
            throw new SandboxException($this->explain($result));
        }
    }

    public function copyIn(string $id, string $directory, string $path): void
    {
        $this->docker(['exec', '-u', 'root', $id, 'mkdir', '-p', $path]);
        $result = Process::forever()->run(['docker', 'cp', "{$directory}/.", "{$id}:{$path}"]);

        if ($result->failed()) {
            throw new SandboxException($this->explain($result));
        }

        // mkdir -p runs as root, so hand back the path and any parents it created under the sandbox user's folders.
        $owned = array_values(array_filter(['/workspace', '/data/storage', '/home/sandbox'], fn (string $root) => str_starts_with($path, $root)));
        $this->docker(['exec', '-u', 'root', $id, 'chown', '-R', 'sandbox:sandbox', ...($owned ?: [$path])]);
    }

    public function destroy(string $id): void
    {
        $result = Process::timeout(30)->run(['docker', 'rm', '--force', $id]);

        if ($result->failed() && ! str_contains($result->errorOutput(), 'No such container')) {
            throw new SandboxException($this->explain($result));
        }
    }

    /**
     * The host folder for a sandbox's App Storage, created if needed; null when buckets stay in the container.
     *
     * @throws SandboxException
     */
    protected function storageFolder(SandboxSpec $spec): ?string
    {
        $root = $this->config['storage_path'] ?? null;

        if (blank($root) || blank($spec->storageKey)) {
            return null;
        }

        $folder = rtrim($root, '/').'/'.$spec->storageKey.'/storage';

        if (! is_dir($folder) && ! @mkdir($folder, 0755, true)) {
            throw new SandboxException("Couldn't create the App Storage folder {$folder}. Check that the app can write to it.");
        }

        return $folder;
    }

    /**
     * Whether a host folder is mounted at a path in the container.
     */
    protected function mounts(string $id, string $path): bool
    {
        $result = Process::timeout(15)->run(['docker', 'inspect', '--format', '{{range .Mounts}}{{println .Destination}}{{end}}', $id]);

        return $result->successful() && in_array($path, explode("\n", trim($result->output())), true);
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
