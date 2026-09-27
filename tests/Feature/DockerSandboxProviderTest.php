<?php

use App\Sandbox\Providers\DockerSandboxProvider;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxSpec;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    Process::preventStrayProcesses();

    $this->docker = new DockerSandboxProvider([
        'image' => 'zap-sandbox:latest',
        'memory' => '2g',
        'cpus' => '2',
        'host' => '127.0.0.1',
    ]);
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
