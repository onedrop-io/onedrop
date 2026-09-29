<?php

use App\Sandbox\Providers\DockerSandboxProvider;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxSpec;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    Process::preventStrayProcesses();

    $this->dockerConfig = [
        'image' => 'zap-sandbox:latest',
        'memory' => '2g',
        'cpus' => '2',
        'host' => '127.0.0.1',
    ];
    $this->docker = new DockerSandboxProvider($this->dockerConfig);
});

test('create runs a labelled, resource-limited container with a published port', function () {
    Process::fake(['*' => Process::result("abc123\n")]);

    $id = $this->docker->create(new SandboxSpec('zap-project-1-x', ['ANTHROPIC_API_KEY' => 'sk-secret'], 8000));

    expect($id)->toBe('abc123');

    Process::assertRan(function (PendingProcess $process) {
        $command = $process->command;

        return $command[0] === 'docker' && $command[1] === 'run'
            && in_array('zap.sandbox=1', $command)
            && in_array('127.0.0.1::8000', $command)
            && in_array('2g', $command)
            && in_array('ANTHROPIC_API_KEY', $command)
            && end($command) === 'zap-sandbox:latest'
            && $process->environment['ANTHROPIC_API_KEY'] === 'sk-secret';
    });
})->group('SBX-001');

test('a shell port is published alongside the app port', function () {
    Process::fake(['*' => Process::result('abc123')]);

    $this->docker->create(new SandboxSpec('zap-project-1-x', port: 8000, shellPort: 7681));

    Process::assertRan(fn (PendingProcess $process) => in_array('127.0.0.1::7681', $process->command)
        && in_array('SHELL_PORT=7681', $process->command)
        && in_array('127.0.0.1::8000', $process->command));
})->group('TAB-001');

test('sandboxes join the configured network so a containerized app is reachable by name', function () {
    Process::fake(['*' => Process::result('abc123')]);

    (new DockerSandboxProvider([...$this->dockerConfig, 'network' => 'drop']))->create(new SandboxSpec('zap-project-1-x'));

    Process::assertRan(fn (PendingProcess $process) => in_array('drop', $process->command)
        && $process->command[array_search('drop', $process->command) - 1] === '--network');
})->group('INSTALL-001');

test('behind the gateway, sandbox addresses are the container name and port on the network', function () {
    Process::fake(['*inspect*' => Process::result("/zap-project-1-x\n")]);

    $docker = new DockerSandboxProvider([...$this->dockerConfig, 'network' => 'drop', 'reach' => 'network']);

    expect($docker->previewUrl('abc123', 8081))->toBe('http://zap-project-1-x:8081');
    Process::assertNotRan(fn (PendingProcess $process) => $process->command[1] === 'port');
})->group('INSTALL-002');

test('a sandbox that no longer exists has no network address', function () {
    Process::fake(['*' => Process::result(errorOutput: 'Error: No such object: gone', exitCode: 1)]);

    $docker = new DockerSandboxProvider([...$this->dockerConfig, 'reach' => 'network']);

    expect($docker->previewUrl('gone', 8081))->toBeNull();
})->group('INSTALL-002');

test('sandboxes stay on the default network when none is configured', function () {
    Process::fake(['*' => Process::result('abc123')]);

    $this->docker->create(new SandboxSpec('zap-project-1-x'));

    Process::assertRan(fn (PendingProcess $process) => $process->command[1] === 'run' && ! in_array('--network', $process->command));
})->group('INSTALL-001');

test('the host proxy port is published for the preview', function () {
    Process::fake(['*' => Process::result('abc123')]);

    $this->docker->create(new SandboxSpec('zap-project-1-x', port: 8000, proxyPort: 8081));

    Process::assertRan(fn (PendingProcess $process) => in_array('127.0.0.1::8081', $process->command)
        && in_array('PROXY_PORT=8081', $process->command));
})->group('PUB-001');

test('secrets are passed through the environment, never the command line', function () {
    Process::fake(['*' => Process::result('abc123')]);

    $this->docker->create(new SandboxSpec('zap-project-1-x', ['ANTHROPIC_API_KEY' => 'sk-secret']));

    Process::assertRan(fn (PendingProcess $process) => ! str_contains(implode(' ', $process->command), 'sk-secret'));
})->group('SBX-001');

test('docker errors become messages a user can act on', function (string $stderr, string $message) {
    Process::fake(['*' => Process::result(errorOutput: $stderr, exitCode: 125)]);

    expect(fn () => $this->docker->create(new SandboxSpec('zap-project-1-x')))
        ->toThrow(SandboxException::class, $message);
})->with([
    'daemon down' => ['Cannot connect to the Docker daemon at unix:///var/run/docker.sock.', 'Docker is not running.'],
    'image missing' => ["Unable to find image 'zap-sandbox:latest' locally", 'php artisan sandbox:build-image'],
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

    Process::assertRan(fn (PendingProcess $process) => $process->command === ['docker', 'rm', '--force', 'abc123']);
})->group('SBX-001');

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

    $this->docker->create(new SandboxSpec('zap-project-1-x', port: 8000, sshPort: 2222));

    Process::assertRan(fn (PendingProcess $process) => in_array('127.0.0.1::2222', $process->command)
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

test('files are copied out of and into a container, owned by the sandbox user', function () {
    Process::fake(fn (PendingProcess $process) => in_array('abc123:/data/storage/.', $process->command, true)
        ? Process::result('', 'Error: Could not find the file /data/storage in container abc123', 1)
        : Process::result());

    $this->docker->copyOut('abc123', '/workspace', '/tmp/backup/0');
    $this->docker->copyOut('abc123', '/data/storage', '/tmp/backup/1'); // never created: nothing to copy
    $this->docker->copyIn('def456', '/tmp/backup/0', '/workspace');

    Process::assertRan(fn (PendingProcess $process) => $process->command === ['docker', 'cp', 'abc123:/workspace/.', '/tmp/backup/0']);
    Process::assertRan(fn (PendingProcess $process) => $process->command === ['docker', 'exec', '-u', 'root', 'def456', 'mkdir', '-p', '/workspace']);
    Process::assertRan(fn (PendingProcess $process) => $process->command === ['docker', 'cp', '/tmp/backup/0/.', 'def456:/workspace']);
    Process::assertRan(fn (PendingProcess $process) => $process->command === ['docker', 'exec', '-u', 'root', 'def456', 'chown', '-R', 'sandbox:sandbox', '/workspace']);
})->group('SBX-002');

test('each project\'s App Storage is a host folder mounted into its sandbox', function () {
    $root = storageRoot();
    $docker = new DockerSandboxProvider([...$this->dockerConfig, 'storage_path' => $root]);
    Process::fake(['*' => Process::result("abc123\n")]);

    $docker->create(new SandboxSpec('zap-project-7-x', storageKey: 'project-7'));

    expect(is_dir("{$root}/project-7/storage"))->toBeTrue();
    Process::assertRan(fn (PendingProcess $process) => $process->command[1] === 'run'
        && in_array("type=bind,source={$root}/project-7/storage,target=/data/storage", $process->command));
    Process::assertRan(fn (PendingProcess $process) => $process->command === ['docker', 'exec', '-u', 'root', 'abc123', 'chown', 'sandbox:sandbox', '/data/storage']);
})->group('STORE-001');

test('without a storage path, App Storage stays in the container', function () {
    Process::fake(['*' => Process::result('abc123')]);

    $this->docker->create(new SandboxSpec('zap-project-7-x', storageKey: 'project-7'));

    Process::assertRan(fn (PendingProcess $process) => $process->command[1] === 'run' && ! in_array('--mount', $process->command));
    Process::assertRanTimes(fn (PendingProcess $process) => in_array('chown', $process->command), 0);
})->group('STORE-001');

test('updating copies buckets out of an old container, but not out of one with the host folder mounted', function (string $mounts, bool $copied) {
    Process::fake(fn (PendingProcess $process) => Process::result($process->command[1] === 'inspect' ? $mounts : ''));

    $this->docker->copyOut('abc123', '/data/storage', '/tmp/backup');

    Process::assertRanTimes(fn (PendingProcess $process) => $process->command[1] === 'cp', $copied ? 1 : 0);
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
    'mounted' => ["/data/storage\n", false],
])->group('STORE-001');
