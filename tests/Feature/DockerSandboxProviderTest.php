<?php

use App\Sandbox\Providers\DockerSandboxProvider;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxSpec;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    Process::preventStrayProcesses();

    $this->dockerConfig = [
        'image' => 'onedrop-sandbox:latest',
        'memory' => '2g',
        'cpus' => '2',
        'host' => '127.0.0.1',
    ];
    $this->docker = new DockerSandboxProvider($this->dockerConfig);
});

/**
 * Whether a docker command publishes the container port on a fixed host port of 127.0.0.1.
 *
 * @param  list<string>  $command
 */
function publishes(array $command, int $port): bool
{
    return collect($command)->contains(fn (string $arg) => preg_match('/^127\.0\.0\.1:\d+:'.$port.'$/', $arg) === 1);
}

test('create runs a labelled, resource-limited container with a published port', function () {
    Process::fake(['*' => Process::result("abc123\n")]);

    $id = $this->docker->create(new SandboxSpec('onedrop-project-1-x', ['ANTHROPIC_API_KEY' => 'sk-secret'], 8000));

    expect($id)->toBe('abc123');

    Process::assertRan(function (PendingProcess $process) {
        $command = $process->command;

        return $command[0] === 'docker' && $command[1] === 'run'
            && in_array('onedrop.sandbox=1', $command)
            && publishes($command, 8000)
            && in_array('2g', $command)
            && in_array('ANTHROPIC_API_KEY', $command)
            && end($command) === 'onedrop-sandbox:latest'
            && $process->environment['ANTHROPIC_API_KEY'] === 'sk-secret';
    });
})->group('SBX-001');

test('a shell port is published alongside the app port', function () {
    Process::fake(['*' => Process::result('abc123')]);

    $this->docker->create(new SandboxSpec('onedrop-project-1-x', port: 8000, shellPort: 7681));

    Process::assertRan(fn (PendingProcess $process) => publishes($process->command, 7681)
        && in_array('SHELL_PORT=7681', $process->command)
        && publishes($process->command, 8000));
})->group('TAB-001');

test('sandboxes join the configured network so a containerized app is reachable by name', function () {
    Process::fake(['*' => Process::result('abc123')]);

    (new DockerSandboxProvider([...$this->dockerConfig, 'network' => 'drop']))->create(new SandboxSpec('onedrop-project-1-x'));

    Process::assertRan(fn (PendingProcess $process) => in_array('drop', $process->command)
        && $process->command[array_search('drop', $process->command) - 1] === '--network');
})->group('INSTALL-001');

test('behind the gateway, sandbox addresses are the container name and port on the network', function () {
    Process::fake(['*inspect*' => Process::result("/onedrop-project-1-x\n")]);

    $docker = new DockerSandboxProvider([...$this->dockerConfig, 'network' => 'drop', 'reach' => 'network']);

    expect($docker->previewUrl('abc123', 8081))->toBe('http://onedrop-project-1-x:8081');
    Process::assertNotRan(fn (PendingProcess $process) => $process->command[1] === 'port');
})->group('INSTALL-002');

test('a sandbox that no longer exists has no network address', function () {
    Process::fake(['*' => Process::result(errorOutput: 'Error: No such object: gone', exitCode: 1)]);

    $docker = new DockerSandboxProvider([...$this->dockerConfig, 'reach' => 'network']);

    expect($docker->previewUrl('gone', 8081))->toBeNull();
})->group('INSTALL-002');

test('sandboxes stay on the default network when none is configured', function () {
    Process::fake(['*' => Process::result('abc123')]);

    $this->docker->create(new SandboxSpec('onedrop-project-1-x'));

    Process::assertRan(fn (PendingProcess $process) => $process->command[1] === 'run' && ! in_array('--network', $process->command));
})->group('INSTALL-001');

test('the host proxy port is published for the preview', function () {
    Process::fake(['*' => Process::result('abc123')]);

    $this->docker->create(new SandboxSpec('onedrop-project-1-x', port: 8000, proxyPort: 8081));

    Process::assertRan(fn (PendingProcess $process) => publishes($process->command, 8081)
        && in_array('PROXY_PORT=8081', $process->command));
})->group('PUB-001');

test('secrets are passed through the environment, never the command line', function () {
    Process::fake(['*' => Process::result('abc123')]);

    $this->docker->create(new SandboxSpec('onedrop-project-1-x', ['ANTHROPIC_API_KEY' => 'sk-secret']));

    Process::assertRan(fn (PendingProcess $process) => ! str_contains(implode(' ', $process->command), 'sk-secret'));
})->group('SBX-001');

test('docker errors become messages a user can act on', function (string $stderr, string $message) {
    Process::fake(['*' => Process::result(errorOutput: $stderr, exitCode: 125)]);

    expect(fn () => $this->docker->create(new SandboxSpec('onedrop-project-1-x')))
        ->toThrow(SandboxException::class, $message);
})->with([
    'daemon down' => ['Cannot connect to the Docker daemon at unix:///var/run/docker.sock.', 'Docker is not running.'],
    'image missing' => ["Unable to find image 'onedrop-sandbox:latest' locally", 'php artisan sandbox:build-image'],
    'other' => ["something odd\nmore detail", 'Docker error: something odd'],
])->group('SBX-001');

test('the preview url comes from the published port', function () {
    Process::fake(['*' => Process::result("127.0.0.1:55012\n[::1]:55012\n")]);

    expect($this->docker->previewUrl('abc123', 8000))->toBe('http://127.0.0.1:55012');
})->group('SBX-001');

test('no preview url when the port is not published', function () {
    Process::fake(['*' => Process::result(errorOutput: 'no public port', exitCode: 1)]);

    expect($this->docker->previewUrl('abc123', 8000))->toBeNull();
})->group('SBX-001');

test('destroying a missing container is not an error', function () {
    Process::fake(['*' => Process::result(errorOutput: 'Error: No such container: abc123', exitCode: 1)]);

    $this->docker->destroy('abc123');

    Process::assertRan(fn (PendingProcess $process) => $process->command === ['docker', 'rm', '--force', '--volumes', 'abc123']);
})->group('SBX-001', 'SBX-008');

test('exec can run detached with env vars by name', function () {
    Process::fake(['*' => Process::result('done')]);

    $result = $this->docker->exec('abc123', ['claude', '-p', 'hi'], ['CLAUDE_CODE_OAUTH_TOKEN' => 'tok'], detach: true);

    expect($result->successful())->toBeTrue();
    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'docker', 'exec', '--detach', '--env', 'CLAUDE_CODE_OAUTH_TOKEN', 'abc123', 'claude', '-p', 'hi',
    ]);
})->group('SBX-001');

test('an ssh port is published for developer tools', function () {
    Process::fake(['*' => Process::result('abc123')]);

    $this->docker->create(new SandboxSpec('onedrop-project-1-x', port: 8000, sshPort: 2222));

    Process::assertRan(fn (PendingProcess $process) => publishes($process->command, 2222)
        && in_array('SSH_PORT=2222', $process->command));
})->group('DEVTOOLS-001');

test('a container is outdated when it was made from another image than the current one', function (string $used, bool $outdated) {
    Process::fake(fn (PendingProcess $process) => $process->command[1] === 'image'
        ? Process::result("sha256:new\n")
        : Process::result("{$used}\n"));

    expect($this->docker->isOutdated('abc123'))->toBe($outdated);
})->with([
    'older image' => ['sha256:old', true],
    'current image' => ['sha256:new', false],
])->group('SBX-002');

test('a missing image or container is not outdated', function () {
    Process::fake(fn (PendingProcess $process) => $process->command[1] === 'image'
        ? Process::result('', 'No such image', 1)
        : Process::result("sha256:old\n"));

    expect($this->docker->isOutdated('abc123'))->toBeFalse();
})->group('SBX-002');

test('files are copied out of and into a container through tar, owned by the sandbox user', function () {
    Process::fake(fn (PendingProcess $process) => in_array('abc123:/data/storage/.', $process->command, true)
        ? Process::result('', 'Error: Could not find the file /data/storage in container abc123', 1)
        : Process::result());

    $this->docker->copyOut('abc123', '/workspace', '/tmp/backup/0');
    $this->docker->copyOut('abc123', '/data/storage', '/tmp/backup/1'); // never created: nothing to copy
    $this->docker->copyIn('def456', '/tmp/backup/0', '/workspace');

    Process::assertRan(fn (PendingProcess $process) => $process->command === ['bash', '-c', DockerSandboxProvider::COPY_OUT, 'copy-out', 'abc123:/workspace/.', '/tmp/backup/0']);
    Process::assertRan(fn (PendingProcess $process) => $process->command === ['docker', 'exec', '-u', 'root', 'def456', 'mkdir', '-p', '/workspace']);
    Process::assertRan(fn (PendingProcess $process) => $process->command === ['bash', '-c', DockerSandboxProvider::COPY_IN, 'copy-in', '/tmp/backup/0', 'def456', '/workspace']);
    Process::assertRan(fn (PendingProcess $process) => $process->command === ['docker', 'exec', '-u', 'root', 'def456', 'chown', '-R', 'sandbox:sandbox', '/workspace']);
})->group('SBX-002');

test('each project\'s App Storage is a host folder mounted into its sandbox', function () {
    $root = storageRoot();
    $docker = new DockerSandboxProvider([...$this->dockerConfig, 'storage_path' => $root]);
    Process::fake(['*' => Process::result("abc123\n")]);

    $docker->create(new SandboxSpec('onedrop-project-7-x', storageKey: 'project-7'));

    expect(is_dir("{$root}/project-7/storage"))->toBeTrue();
    Process::assertRan(fn (PendingProcess $process) => $process->command[1] === 'run'
        && in_array("type=bind,source={$root}/project-7/storage,target=/data/storage", $process->command));
    Process::assertRan(fn (PendingProcess $process) => $process->command === ['docker', 'exec', '-u', 'root', 'abc123', 'chown', 'sandbox:sandbox', '/data/storage']);
})->group('STORE-001');

test('without a storage path, App Storage stays in the container', function () {
    Process::fake(['*' => Process::result('abc123')]);

    $this->docker->create(new SandboxSpec('onedrop-project-7-x', storageKey: 'project-7'));

    Process::assertRan(fn (PendingProcess $process) => $process->command[1] === 'run' && ! in_array('--mount', $process->command));
    Process::assertRanTimes(fn (PendingProcess $process) => in_array('chown', $process->command), 0);
})->group('STORE-001');

test('updating copies buckets out of an old container, but not out of one with the host folder mounted', function (string $mounts, bool $copied) {
    Process::fake(fn (PendingProcess $process) => Process::result($process->command[1] === 'inspect' ? $mounts : ''));

    $this->docker->copyOut('abc123', '/data/storage', '/tmp/backup');

    Process::assertRanTimes(fn (PendingProcess $process) => ($process->command[3] ?? null) === 'copy-out', $copied ? 1 : 0);
})->with([
    'no mount (made before host folders)' => ["\n", true],
    'mounted' => ["/data/storage\n", false],
])->group('STORE-001');

test('with a storage path, a container on the current image without the storage mount is outdated', function (string $mounts, bool $outdated) {
    $docker = new DockerSandboxProvider([...$this->dockerConfig, 'storage_path' => '/srv/sandboxes']);
    Process::fake(fn (PendingProcess $process) => Process::result(match (true) {
        $process->command[1] === 'image' => "sha256:new\n",
        str_contains(implode(' ', $process->command), '.Mounts') => $mounts,
        default => "sha256:new\n",
    }));

    expect($docker->isOutdated('abc123'))->toBe($outdated);
})->with([
    'no mount' => ["\n", true],
    'no Claude sign-in mount (made before AI-005)' => ["/data/storage\n", true],
    'mounted' => ["/data/storage\n/data/claude\n", false],
])->group('STORE-001', 'AI-005');

test('each user\'s Claude sign-in is one host folder mounted into all their sandboxes', function () {
    $root = storageRoot();
    $docker = new DockerSandboxProvider([...$this->dockerConfig, 'storage_path' => $root]);
    Process::fake(['*' => Process::result("abc123\n")]);

    $docker->create(new SandboxSpec('onedrop-project-7-x', storageKey: 'project-7', claudeLoginKey: 'user-3'));
    $docker->create(new SandboxSpec('onedrop-project-8-x', storageKey: 'project-8', claudeLoginKey: 'user-3'));

    expect(is_dir("{$root}/user-3/claude"))->toBeTrue();
    Process::assertRanTimes(fn (PendingProcess $process) => $process->command[1] === 'run'
        && in_array("type=bind,source={$root}/user-3/claude,target=/data/claude", $process->command)
        && in_array('CLAUDE_CONFIG_DIR=/data/claude', $process->command), 2);
    Process::assertRan(fn (PendingProcess $process) => $process->command === ['docker', 'exec', '-u', 'root', 'abc123', 'chown', 'sandbox:sandbox', '/data/claude']);
})->group('AI-005');

test('each container port gets its own host port, kept when the container restarts', function () {
    Process::fake(['*' => Process::result('abc123')]);

    $this->docker->create(new SandboxSpec('onedrop-project-1-x', port: 8000, proxyPort: 8081, shellPort: 7681));

    Process::assertRan(function (PendingProcess $process) {
        $hostPorts = collect($process->command)
            ->filter(fn (string $arg) => preg_match('/^127\.0\.0\.1:(\d+):\d+$/', $arg) === 1)
            ->map(fn (string $arg) => explode(':', $arg)[1]);

        return $hostPorts->count() === 3 && $hostPorts->unique()->count() === 3;
    });
})->group('SBX-007');

test('behind the gateway, docker picks the host ports', function () {
    Process::fake(['*' => Process::result('abc123')]);

    (new DockerSandboxProvider([...$this->dockerConfig, 'reach' => 'network']))->create(new SandboxSpec('onedrop-project-1-x', port: 8000));

    Process::assertRan(fn (PendingProcess $process) => in_array('127.0.0.1::8000', $process->command));
})->group('SBX-007');

test('containers run a real init, and stopping one gives it a few seconds before killing it', function () {
    Process::fake(['*' => Process::result('abc123')]);

    $this->docker->create(new SandboxSpec('onedrop-project-1-x'));
    $this->docker->pause('abc123');

    Process::assertRan(fn (PendingProcess $process) => $process->command[1] === 'run' && in_array('--init', $process->command));
    Process::assertRan(fn (PendingProcess $process) => $process->command === ['docker', 'stop', '--time', '5', 'abc123']);
})->group('SBX-007');

test('suspending freezes the container', function () {
    Process::fake(['*' => Process::result('abc123')]);

    $this->docker->suspend('abc123');

    Process::assertRan(fn (PendingProcess $process) => $process->command === ['docker', 'pause', 'abc123']);
})->group('SBX-007');

test('suspending a paused or stopped container is not an error', function (string $stderr) {
    Process::fake(['*' => Process::result(errorOutput: $stderr, exitCode: 1)]);

    $this->docker->suspend('abc123');
})->with([
    'already paused' => ['Error response from daemon: container abc123 is already paused'],
    'stopped' => ['Error response from daemon: Container abc123 is not running'],
])->throwsNoExceptions()->group('SBX-007');

test('waking unpauses a suspended container, starts a stopped one, and leaves a running one alone', function (string $state, ?string $action) {
    Process::fake(['*inspect*' => Process::result("{$state}\n"), '*' => Process::result('abc123')]);

    expect($this->docker->wake('abc123'))->toBe($action !== null);

    $action
        ? Process::assertRan(fn (PendingProcess $process) => $process->command === ['docker', $action, 'abc123'])
        : Process::assertRanTimes(fn (PendingProcess $process) => true, 1);
})->with([
    'suspended' => ['paused', 'unpause'],
    'stopped' => ['exited', 'start'],
    'running' => ['running', null],
])->group('SBX-007');

test('waking a container that no longer exists says so', function () {
    Process::fake(['*' => Process::result(errorOutput: 'Error: No such object: abc123', exitCode: 1)]);

    $this->docker->wake('abc123');
})->throws(SandboxException::class)->group('SBX-007');

test('waking a container whose host folder was deleted makes the folder again and restarts it', function () {
    $root = storageRoot();
    $docker = new DockerSandboxProvider([...$this->dockerConfig, 'storage_path' => $root]);
    mkdir("{$root}/project-7/storage", 0755, true);
    Process::fake(fn (PendingProcess $process) => Process::result(match (true) {
        str_contains(implode(' ', $process->command), '.State.Status') => "running\n",
        str_contains(implode(' ', $process->command), '.Mounts') => "/data/storage {$root}/project-7/storage\n/data/claude {$root}/user-1/claude\n/elsewhere /tmp/not-ours\n",
        str_contains(implode(' ', $process->command), 'stat -c') => "/data/claude\n",
        default => '',
    }));

    expect($docker->wake('abc123'))->toBeTrue()
        ->and(is_dir("{$root}/user-1/claude"))->toBeTrue();
    // Only our own folders are checked, never anything else the container mounts.
    Process::assertRan(fn (PendingProcess $process) => array_slice($process->command, -2) === ['/data/storage', '/data/claude']);
    Process::assertRan(fn (PendingProcess $process) => $process->command === ['docker', 'restart', '--time', '5', 'abc123']);
    Process::assertRan(fn (PendingProcess $process) => $process->command === ['docker', 'exec', '-u', 'root', 'abc123', 'chown', 'sandbox:sandbox', '/data/claude']);
    Process::assertRanTimes(fn (PendingProcess $process) => in_array('chown', $process->command) && in_array('/data/storage', $process->command), 0);
})->group('SBX-007', 'AI-005');

test('waking a container whose mounts all work doesn\'t restart it', function () {
    $root = storageRoot();
    $docker = new DockerSandboxProvider([...$this->dockerConfig, 'storage_path' => $root]);
    mkdir("{$root}/user-1/claude", 0755, true);
    Process::fake(fn (PendingProcess $process) => Process::result(match (true) {
        str_contains(implode(' ', $process->command), '.State.Status') => "running\n",
        str_contains(implode(' ', $process->command), '.Mounts') => "/data/claude {$root}/user-1/claude\n",
        default => '',
    }));

    expect($docker->wake('abc123'))->toBeFalse();
    Process::assertRanTimes(fn (PendingProcess $process) => $process->command[1] === 'restart', 0);
})->group('SBX-007');

test('a command in a suspended container wakes it first', function () {
    Process::fake([
        '*exec*' => Process::sequence()
            ->push(Process::result(errorOutput: 'Error response from daemon: Container abc123 is paused, unpause the container before exec', exitCode: 1))
            ->push(Process::result('hello')),
        '*inspect*' => Process::result('paused'),
        '*unpause*' => Process::result('abc123'),
    ]);

    $result = $this->docker->exec('abc123', ['echo', 'hello']);

    expect($result->successful())->toBeTrue()->and(trim($result->output))->toBe('hello');
    Process::assertRan(fn (PendingProcess $process) => $process->command === ['docker', 'unpause', 'abc123']);
    Process::assertRanTimes(fn (PendingProcess $process) => $process->command[1] === 'exec', 2);
})->group('SBX-007');

test('starting a stopped container starts it, and a suspended one is woken, since docker won\'t start a paused one', function (string $state, string $action) {
    Process::fake(['*inspect*' => Process::result($state), '*' => Process::result('abc123')]);

    $this->docker->start('abc123');

    Process::assertRan(fn (PendingProcess $process) => $process->command === ['docker', $action, 'abc123']);
})->with([
    'stopped' => ['exited', 'start'],
    'suspended' => ['paused', 'unpause'],
])->group('SBX-007');

test('with Docker inside sandboxes, each sandbox gets its own volume for Docker\'s data, and runs privileged only when chosen', function (string $mode, bool $privileged) {
    Process::fake(['*' => Process::result('abc123')]);

    (new DockerSandboxProvider([...$this->dockerConfig, 'nested_docker' => $mode, 'runtime' => $privileged ? null : 'sysbox-runc']))->create(new SandboxSpec('onedrop-project-1-x'));

    Process::assertRan(fn (PendingProcess $process) => $process->command[1] === 'run'
        && in_array('type=volume,target=/var/lib/docker', $process->command, true)
        && in_array('ONEDROP_DOCKER=1', $process->command, true)
        && in_array('--privileged', $process->command, true) === $privileged);
})->with([
    'privileged (local)' => ['privileged', true],
    'a safe runtime (servers)' => ['runtime', false],
])->group('SBX-008');

test('without Docker inside sandboxes, sandboxes get no Docker and are never privileged', function () {
    Process::fake(['*' => Process::result('abc123')]);

    $this->docker->create(new SandboxSpec('onedrop-project-1-x'));

    Process::assertRan(fn (PendingProcess $process) => $process->command[1] === 'run'
        && ! in_array('--privileged', $process->command, true)
        && ! in_array('ONEDROP_DOCKER=1', $process->command, true)
        && ! in_array('type=volume,target=/var/lib/docker', $process->command, true));
})->group('SBX-008');

test('privileged Docker inside sandboxes is refused outside a local install', function () {
    Process::fake();
    app()->detectEnvironment(fn () => 'production');

    try {
        expect(fn () => (new DockerSandboxProvider([...$this->dockerConfig, 'nested_docker' => 'privileged']))->create(new SandboxSpec('onedrop-project-1-x')))
            ->toThrow(SandboxException::class, 'only run privileged on a local install');
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }

    Process::assertNothingRan();
})->group('SBX-008');

test('turning Docker inside sandboxes on or off, or changing how it is isolated, makes existing sandboxes outdated', function (string $mode, string $mounts, bool $outdated, string $privileged = 'true') {
    Process::fake([
        '*image*inspect*' => Process::result('sha256:current'),
        '*Mounts*' => Process::result($mounts),
        '*Privileged*' => Process::result($privileged),
        '*' => Process::result('sha256:current'),
    ]);

    expect((new DockerSandboxProvider([...$this->dockerConfig, 'nested_docker' => $mode]))->isOutdated('abc123'))->toBe($outdated);
})->with([
    'on, sandbox without it' => ['privileged', "/data/storage\n", true],
    'on, sandbox with it' => ['privileged', "/var/lib/docker\n", false],
    'off, sandbox with it' => ['off', "/var/lib/docker\n", true],
    'off, sandbox without it' => ['off', '', false],
    'privileged, sandbox made with a runtime instead' => ['privileged', "/var/lib/docker\n", true, 'false'],
    'runtime, sandbox made privileged' => ['runtime', "/var/lib/docker\n", true, 'true'],
    'runtime, sandbox made with it' => ['runtime', "/var/lib/docker\n", false, 'false'],
])->group('SBX-008');

test('Docker inside sandboxes on a runtime needs that runtime set', function () {
    Process::fake();

    expect(fn () => (new DockerSandboxProvider([...$this->dockerConfig, 'nested_docker' => 'runtime']))->create(new SandboxSpec('onedrop-project-1-x')))
        ->toThrow(SandboxException::class, 'no container runtime is set');

    Process::assertNothingRan();
})->group('SBX-008');
