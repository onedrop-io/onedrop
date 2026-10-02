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
    public const LABEL = 'onedrop.sandbox';

    /** Where App Storage lives inside the sandbox; backed by a host folder when storage_path is set. */
    public const STORAGE_MOUNT = '/data/storage';

    /**
     * Claude Code's config folder (CLAUDE_CONFIG_DIR), backed by one host folder per user when storage_path
     * is set, so signing in to Claude once works in all of their sandboxes (AI-005). Only Claude Code
     * reads or writes it; the platform never does.
     */
    public const CLAUDE_MOUNT = '/data/claude';

    /** Where the sandbox's own Docker keeps its data (SBX-008): a volume of its own, deleted with the container. */
    public const DOCKER_MOUNT = '/var/lib/docker';

    /** Copies a container path ($1, which may be stopped) into a host folder ($2). */
    public const COPY_OUT = 'set -o pipefail; docker cp "$1" - | tar -x -C "$2"';

    /** Copies a host folder ($1) into a running container ($2) at a path ($3). COPYFILE_DISABLE keeps macOS's ._ files out. */
    public const COPY_IN = 'set -o pipefail; tar -c -C "$1" . | docker exec -i -u root "$2" tar -x -C "$3"';

    /** Seconds a stopping container gets to exit by itself before it's killed. */
    public const STOP_SECONDS = 5;

    /**
     * @param  array{image: string, memory: string, cpus: string, host: string, runtime?: ?string, nested_docker?: ?string, network?: ?string, reach?: ?string, storage_path?: ?string}  $config
     */
    public function __construct(protected array $config) {}

    public function create(SandboxSpec $spec): string
    {
        $command = [
            'docker', 'run', '--detach',
            '--name', $spec->name,
            '--label', self::LABEL.'=1',
            // A real init as PID 1 passes `docker stop` on, so the container stops at once instead of being killed.
            '--init',
            '--memory', $this->config['memory'],
            '--cpus', $this->config['cpus'],
            '--publish', $this->hostBinding($spec->port),
            '--env', "PORT={$spec->port}",
            // Linux Docker doesn't define host.docker.internal; sandboxes use it to report agent events.
            '--add-host', 'host.docker.internal:host-gateway',
        ];

        if (filled($this->config['runtime'] ?? null)) {
            array_push($command, '--runtime', $this->config['runtime']);
        }

        if ($this->runsDocker()) {
            array_push($command, ...$this->dockerArgs());
        }

        if (filled($this->config['network'] ?? null)) {
            array_push($command, '--network', $this->config['network']);
        }

        if ($spec->proxyPort) {
            array_push($command, '--publish', $this->hostBinding($spec->proxyPort), '--env', "PROXY_PORT={$spec->proxyPort}");
        }

        if ($spec->shellPort) {
            array_push($command, '--publish', $this->hostBinding($spec->shellPort), '--env', "SHELL_PORT={$spec->shellPort}");
        }

        if ($spec->sshPort) {
            array_push($command, '--publish', $this->hostBinding($spec->sshPort), '--env', "SSH_PORT={$spec->sshPort}");
        }

        $storage = $this->hostFolder($spec->storageKey, 'storage', 'App Storage');
        $claude = $this->hostFolder($spec->claudeLoginKey, 'claude', 'Claude login');

        if ($storage !== null) {
            array_push($command, '--mount', "type=bind,source={$storage},target=".self::STORAGE_MOUNT);
        }

        if ($claude !== null) {
            array_push($command, '--mount', "type=bind,source={$claude},target=".self::CLAUDE_MOUNT, '--env', 'CLAUDE_CONFIG_DIR='.self::CLAUDE_MOUNT);
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
        // if this fails the sandbox still runs, and App Storage (or Claude's sign-in) reports that it can't write.
        foreach (array_filter([$storage ? self::STORAGE_MOUNT : null, $claude ? self::CLAUDE_MOUNT : null]) as $mount) {
            Process::timeout(30)->run(['docker', 'exec', '-u', 'root', $id, 'chown', 'sandbox:sandbox', $mount]);
        }

        return $id;
    }

    public function start(string $id): void
    {
        $this->wake($id);
    }

    /**
     * Stop the container, keeping its files. It gets STOP_SECONDS to exit by itself; those made before --init ignore
     * the request (bash as PID 1) and are killed then.
     */
    public function pause(string $id): void
    {
        $this->docker(['stop', '--time', (string) self::STOP_SECONDS, $id]);
    }

    /**
     * Freeze the container's processes (docker pause): memory is kept and no CPU is used until wake() (SBX-007).
     */
    public function suspend(string $id): void
    {
        $result = Process::timeout(60)->run(['docker', 'pause', $id]);

        // Already paused, or stopped: nothing is running to freeze.
        if ($result->failed() && ! str_contains($result->errorOutput(), 'already paused') && ! str_contains($result->errorOutput(), 'is not running')) {
            throw new SandboxException($this->explain($result));
        }
    }

    /**
     * Let a suspended container's processes carry on where they were, or start one that was stopped (by pause(), or
     * outside the app: Docker Desktop, a restart). Docker won't start a paused container, nor exec in either.
     * A container whose host folders were deleted under it is mended too (repairMounts()).
     */
    public function wake(string $id): bool
    {
        $woke = $this->wakeContainer($id);

        return $this->repairMounts($id) || $woke;
    }

    /**
     * Unpause or start the container, saying whether it had to.
     */
    protected function wakeContainer(string $id): bool
    {
        $state = Process::timeout(15)->run(['docker', 'inspect', '--format', '{{.State.Status}}', $id]);

        if ($state->failed()) {
            throw new SandboxException($this->explain($state));
        }

        $action = match (trim($state->output())) {
            'paused' => 'unpause',
            'exited', 'created' => 'start',
            default => null,
        };

        if ($action !== null) {
            $this->docker([$action, $id]);
        }

        return $action !== null;
    }

    public function exec(string $id, array $command, array $env = [], bool $detach = false): ExecResult
    {
        $result = $this->run($id, $command, $env, $detach);

        // Docker won't exec in a paused container: wake it first, as managed providers do on the next request. One
        // stopped outside the app stays stopped until its project is opened (Sandbox::wake()).
        if ($result->failed() && str_contains($result->errorOutput(), 'is paused')) {
            $this->wake($id);
            $result = $this->run($id, $command, $env, $detach);
        }

        return new ExecResult($result->exitCode() ?? 1, $result->output(), $result->errorOutput());
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     */
    protected function run(string $id, array $command, array $env, bool $detach): ProcessResult
    {
        $args = ['docker', 'exec'];

        if ($detach) {
            $args[] = '--detach';
        }

        foreach (array_keys($env) as $name) {
            array_push($args, '--env', $name);
        }

        return Process::env($env)->timeout(120)->run([...$args, $id, ...$command]);
    }

    public function previewUrl(string $id, int $port): ?string
    {
        // The app runs in a container on the sandbox's network, behind the gateway: reach the sandbox by name.
        if (($this->config['reach'] ?? null) === 'network') {
            $name = $this->containerName($id);

            return $name === null ? null : "http://{$name}:{$port}";
        }

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

        // Made before App Storage (or Claude's sign-in) moved to a host folder: recreate it to move them there.
        // Docker inside sandboxes turned on or off (SBX-008): recreate it with (or without) its own Docker.
        return trim($current->output()) !== trim($used->output())
            || $this->mounts($id, self::DOCKER_MOUNT) !== $this->runsDocker()
            || ($this->runsDocker() && $this->isPrivileged($id) !== (($this->config['nested_docker'] ?? null) === 'privileged'))
            || (filled($this->config['storage_path'] ?? null) && (! $this->mounts($id, self::STORAGE_MOUNT) || ! $this->mounts($id, self::CLAUDE_MOUNT)));
    }

    public function copyOut(string $id, string $path, string $directory): void
    {
        // A host folder mounted at this path outlives the container: nothing to carry over.
        if ($this->mounts($id, $path)) {
            return;
        }

        // Docker refuses to unpack a relative symlink that climbs out of the folder it's copying into (`corepack enable`
        // leaves ~/.local/bin/pnpm -> ../../../../usr/lib/...), so take the archive and unpack it with tar instead.
        $result = Process::forever()->run(['bash', '-c', self::COPY_OUT, 'copy-out', "{$id}:{$path}/.", $directory]);

        // A path the sandbox never created (e.g. no App Storage yet) has nothing to copy.
        if ($result->failed() && ! str_contains($result->errorOutput(), 'Could not find the file')) {
            throw new SandboxException($this->explain($result));
        }
    }

    public function copyIn(string $id, string $directory, string $path): void
    {
        $this->docker(['exec', '-u', 'root', $id, 'mkdir', '-p', $path]);
        // Unpacked by the container's own tar, for the same symlinks Docker refuses in copyOut.
        $result = Process::env(['COPYFILE_DISABLE' => '1'])->forever()->run(['bash', '-c', self::COPY_IN, 'copy-in', $directory, $id, $path]);

        if ($result->failed()) {
            throw new SandboxException($this->explain($result));
        }

        // mkdir -p runs as root, so hand back the path and any parents it created under the sandbox user's folders.
        $owned = array_values(array_filter(['/workspace', '/data/storage', '/home/sandbox'], fn (string $root) => str_starts_with($path, $root)));
        $this->docker(['exec', '-u', 'root', $id, 'chown', '-R', 'sandbox:sandbox', ...($owned ?: [$path])]);
    }

    public function destroy(string $id): void
    {
        // --volumes deletes the sandbox's own Docker data with it (SBX-008).
        $result = Process::timeout(30)->run(['docker', 'rm', '--force', '--volumes', $id]);

        if ($result->failed() && ! str_contains($result->errorOutput(), 'No such container')) {
            throw new SandboxException($this->explain($result));
        }
    }

    /**
     * Whether sandboxes get Docker inside them, for projects that run their own Docker Compose (SBX-008).
     */
    protected function runsDocker(): bool
    {
        return in_array($this->config['nested_docker'] ?? 'off', ['privileged', 'runtime'], true);
    }

    /**
     * What `docker run` needs for Docker inside the sandbox: its own volume for Docker's data (overlay on overlay
     * won't unpack images), the signal for start.sh to start it, and --privileged when that's the chosen isolation,
     * which only a local install may use.
     *
     * @return list<string>
     *
     * @throws SandboxException
     */
    protected function dockerArgs(): array
    {
        $args = ['--mount', 'type=volume,target='.self::DOCKER_MOUNT, '--env', 'ONEDROP_DOCKER=1'];

        if (($this->config['nested_docker'] ?? null) !== 'privileged') {
            if (blank($this->config['runtime'] ?? null)) {
                throw new SandboxException('Docker inside sandboxes is set to "runtime", but no container runtime is set. In Settings → Sandboxes → Docker, set one that makes Docker in a container safe (such as sysbox-runc), or choose "privileged" on a local install.');
            }

            return $args;
        }

        if (! app()->environment(['local', 'testing'])) {
            throw new SandboxException('Docker inside sandboxes can only run privileged on a local install. On a server, set a container runtime that makes Docker in a container safe (such as sysbox-runc) and choose "runtime" in Settings → Sandboxes.');
        }

        return ['--privileged', ...$args];
    }

    /**
     * Where Docker publishes a container port: a free host port picked now, so the container keeps it across restarts
     * and its saved addresses stay right; Docker would pick a new one on every start. Behind the gateway ("network"
     * reach) the app may not share the host's ports, so Docker picks, as nothing reaches them by port anyway.
     */
    protected function hostBinding(int $port): string
    {
        if (($this->config['reach'] ?? null) === 'network') {
            return "127.0.0.1::{$port}";
        }

        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $address = $socket ? stream_socket_get_name($socket, false) : false;

        if ($socket) {
            fclose($socket);
        }

        return $address ? '127.0.0.1:'.substr($address, strrpos($address, ':') + 1).":{$port}" : "127.0.0.1::{$port}";
    }

    /**
     * A host folder kept outside the container (<storage_path>/<key>/<name>), created if needed; null when
     * there's no storage_path or key, and the files stay in the container.
     *
     * @throws SandboxException
     */
    protected function hostFolder(?string $key, string $name, string $label): ?string
    {
        $root = $this->config['storage_path'] ?? null;

        if (blank($root) || blank($key)) {
            return null;
        }

        $folder = rtrim($root, '/')."/{$key}/{$name}";

        if (! is_dir($folder) && ! @mkdir($folder, 0755, true)) {
            throw new SandboxException("Couldn't create the {$label} folder {$folder}. Check that the app can write to it.");
        }

        return $folder;
    }

    /**
     * Mend a container whose host folders of ours (under storage_path) were deleted while it had them mounted: make
     * the folders again, and restart the container if any mount is still dead in there. Until then whatever is
     * written to it (a Claude sign-in, App Storage) goes nowhere. On Linux a deleted folder stays mounted with no
     * links (so it's dead even once something makes the folder again); on Docker Desktop it can't be read until the
     * folder is back. Returns whether it restarted. Best effort: the next wake tries again.
     *
     * @throws SandboxException
     */
    protected function repairMounts(string $id): bool
    {
        $root = $this->config['storage_path'] ?? null;

        if (blank($root)) {
            return false;
        }

        $result = Process::timeout(15)->run(['docker', 'inspect', '--format', '{{range .Mounts}}{{if eq .Type "bind"}}{{println .Destination .Source}}{{end}}{{end}}', $id]);

        if ($result->failed()) {
            return false;
        }

        $prefix = rtrim($root, '/').'/';
        $mounts = [];

        foreach (array_filter(explode("\n", trim($result->output()))) as $line) {
            [$mount, $source] = array_pad(explode(' ', $line, 2), 2, '');

            if (str_starts_with($source, $prefix)) {
                $mounts[] = $mount;

                if (! is_dir($source)) {
                    @mkdir($source, 0755, true);
                }
            }
        }

        if ($mounts === []) {
            return false;
        }

        $check = $this->run($id, ['sh', '-c', 'for d; do [ "$(stat -c %h "$d" 2>/dev/null)" -ge 1 ] 2>/dev/null || echo "$d"; done', 'sh', ...$mounts], [], false);
        $dead = array_values(array_intersect($mounts, explode("\n", trim($check->output()))));

        if ($check->failed() || $dead === []) {
            return false;
        }

        $this->docker(['restart', '--time', (string) self::STOP_SECONDS, $id]);

        // A new host folder is owned by the platform's user; let the sandbox user write to it (as create() does).
        foreach ($dead as $mount) {
            Process::timeout(30)->run(['docker', 'exec', '-u', 'root', $id, 'chown', 'sandbox:sandbox', $mount]);
        }

        return true;
    }

    /**
     * The container's name, which Docker's DNS resolves on its networks.
     */
    protected function containerName(string $id): ?string
    {
        $result = Process::timeout(15)->run(['docker', 'inspect', '--format', '{{.Name}}', $id]);
        $name = ltrim(trim($result->output()), '/');

        return $result->successful() && $name !== '' ? $name : null;
    }

    /**
     * Whether the container runs with --privileged.
     */
    protected function isPrivileged(string $id): bool
    {
        $result = Process::timeout(15)->run(['docker', 'inspect', '--format', '{{.HostConfig.Privileged}}', $id]);

        return $result->successful() && trim($result->output()) === 'true';
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
