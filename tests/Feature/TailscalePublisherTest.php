<?php

use App\Enums\PublishVisibility;
use App\Models\Project;
use App\Models\Sandbox;
use App\Sandbox\Publishing\PublishException;
use App\Sandbox\Publishing\PublishNeedsLogin;
use App\Sandbox\Publishing\TailscalePublisher;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    Process::preventStrayProcesses();
    config(['sandbox.provider' => 'docker']);

    $this->tailscale = new TailscalePublisher(['authkey' => 'tskey-auth-secret', 'image' => 'tailscale/tailscale:stable'], 8081);
    $this->project = Project::factory()->create(['name' => 'Timesheets']);
    Sandbox::factory()->for($this->project)->create(['external_id' => 'ctr-1']);
});

test('is available in docker with or without an auth key', function () {
    expect((new TailscalePublisher(['authkey' => null, 'image' => 'x'], 8000))->unavailableReason())->toBeNull()
        ->and($this->tailscale->unavailableReason())->toBeNull();

    config(['sandbox.provider' => 'daytona']);
    expect($this->tailscale->unavailableReason())->toContain('only works with local Docker');
})->group('PUB-001');

test('start runs a tailscale sidecar in the sandbox network, key via env only', function () {
    Process::fake([
        '*inspect*' => Process::result(errorOutput: 'No such object', exitCode: 1),
        '*' => Process::result('sidecar-id'),
    ]);

    $this->tailscale->start($this->project);

    Process::assertRan(function (PendingProcess $process) {
        $command = $process->command;

        return is_array($command) && $command[1] === 'run'
            && in_array('container:ctr-1', $command)
            && in_array("TS_HOSTNAME=timesheets-{$this->project->id}", $command)
            && in_array('TS_USERSPACE=true', $command)
            && in_array("zap-publish-{$this->project->id}-state:/var/lib/tailscale", $command)
            && in_array('TS_AUTHKEY', $command)
            && ! str_contains(implode(' ', $command), 'tskey-auth-secret')
            && $process->environment['TS_AUTHKEY'] === 'tskey-auth-secret';
    });
})->group('PUB-001');

test('start reuses a running sidecar', function () {
    Process::fake(['*inspect*' => Process::result("true\n")]);

    $this->tailscale->start($this->project);

    Process::assertDidntRun(fn (PendingProcess $process) => is_array($process->command) && ($process->command[1] ?? null) === 'run');
})->group('PUB-001');

test('confirm waits until the node is running', function () {
    Process::fake(['*' => Process::result(json_encode(['BackendState' => 'Starting', 'Self' => ['DNSName' => '']]))]);

    expect($this->tailscale->confirm($this->project, PublishVisibility::Public))->toBeNull();
})->group('PUB-001');

test('confirm applies funnel for public and serve for private to the host proxy, returning the url', function (PublishVisibility $visibility, string $command) {
    Process::fake([
        '*status*' => Process::result(json_encode(['BackendState' => 'Running', 'Self' => ['DNSName' => 'timesheets-1.tail1234.ts.net.']])),
        '*' => Process::result(''),
    ]);

    expect($this->tailscale->confirm($this->project, $visibility))->toBe('https://timesheets-1.tail1234.ts.net');

    Process::assertRan(fn (PendingProcess $process) => $process->command === ['docker', 'exec', "zap-publish-{$this->project->id}", 'tailscale', $command, '--bg', '8081']);
})->with([
    'public' => [PublishVisibility::Public, 'funnel'],
    'private' => [PublishVisibility::Private, 'serve'],
])->group('PUB-001');

test('without an auth key the node runs unattended-free and asks for browser approval', function () {
    $tailscale = new TailscalePublisher(['authkey' => null, 'image' => 'tailscale/tailscale:stable'], 8000);
    Process::fake([
        '*inspect*' => Process::result(errorOutput: 'No such object', exitCode: 1),
        '*status*' => Process::result(json_encode(['BackendState' => 'NeedsLogin', 'AuthURL' => 'https://login.tailscale.com/a/abc123'])),
        '*' => Process::result('sidecar-id'),
    ]);

    $tailscale->start($this->project);

    Process::assertRan(fn (PendingProcess $process) => is_array($process->command) && ($process->command[1] ?? null) === 'run'
        && ! in_array('TS_AUTHKEY', $process->command));

    try {
        $tailscale->confirm($this->project, PublishVisibility::Public);
        $this->fail('Expected a login request.');
    } catch (PublishNeedsLogin $e) {
        expect($e->loginUrl)->toBe('https://login.tailscale.com/a/abc123');
    }
})->group('PUB-001');

test('without an auth key the node asks for browser approval', function () {
    $tailscale = new TailscalePublisher(['authkey' => null, 'image' => 'tailscale/tailscale:stable'], 8081);
    Process::fake([
        '*inspect*' => Process::result(errorOutput: 'No such object', exitCode: 1),
        '*status*' => Process::result(json_encode(['BackendState' => 'NeedsLogin', 'AuthURL' => 'https://login.tailscale.com/a/abc123'])),
        '*' => Process::result('sidecar-id'),
    ]);

    $tailscale->start($this->project);

    Process::assertRan(fn (PendingProcess $process) => is_array($process->command) && ($process->command[1] ?? null) === 'run'
        && ! in_array('TS_AUTHKEY', $process->command));

    expect(fn () => $tailscale->confirm($this->project, PublishVisibility::Public))
        ->toThrow(fn (PublishNeedsLogin $e) => expect($e->loginUrl)->toBe('https://login.tailscale.com/a/abc123'));
})->group('PUB-001');

test('a rejected auth key is explained', function () {
    Process::fake(['*' => Process::result(json_encode(['BackendState' => 'NeedsLogin', 'AuthURL' => 'https://login.tailscale.com/a/x']))]);

    expect(fn () => $this->tailscale->confirm($this->project, PublishVisibility::Private))
        ->toThrow(PublishException::class, 'Tailscale rejected the auth key');
})->group('PUB-001');

test('funnel not allowed for the tailnet is explained', function () {
    Process::fake([
        '*status*' => Process::result(json_encode(['BackendState' => 'Running', 'Self' => ['DNSName' => 'a.ts.net.']])),
        '*funnel*' => Process::result(errorOutput: 'Funnel not available; "funnel" node attribute not set.', exitCode: 1),
        '*' => Process::result(''),
    ]);

    expect(fn () => $this->tailscale->confirm($this->project, PublishVisibility::Public))
        ->toThrow(PublishException::class, 'needs Tailscale Funnel enabled');
})->group('PUB-001');

test('stop removes the sidecar', function () {
    Process::fake(['*' => Process::result('')]);

    $this->tailscale->stop($this->project);

    Process::assertRan(fn (PendingProcess $process) => $process->command === ['docker', 'rm', '--force', "zap-publish-{$this->project->id}"]);
})->group('PUB-001');
