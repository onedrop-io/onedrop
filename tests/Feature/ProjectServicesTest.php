<?php

use App\Enums\SandboxStatus;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\ExecResult;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;

beforeEach(function () {
    $this->provider = new FakeSandboxProvider;
    app()->instance(SandboxProvider::class, $this->provider);

    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->project = Project::factory()->for($this->user)->create();
    Sandbox::factory()->for($this->project)->create(['external_id' => 'ctr-1']);

    $container = fn (string $service, string $state, string $status, string $ports = '') => json_encode([
        'Names' => "workspace-{$service}-1",
        'Image' => "{$service}:latest",
        'State' => $state,
        'Status' => $status,
        'Ports' => $ports,
        'Labels' => "com.docker.compose.project=workspace,com.docker.compose.service={$service},com.docker.compose.version=5.5.1",
    ]);

    $this->services = [
        'dev' => "export COMPOSE_FILE='compose.yaml'\nexec /opt/onedrop/compose up --preview 8080",
        'running' => true,
        'docker' => true,
        'containers' => [
            json_decode($container('worker', 'restarting', 'Restarting (127) 5 seconds ago'), true),
            json_decode($container('app', 'running', 'Up 12 minutes', '0.0.0.0:8080->80/tcp, [::]:8080->80/tcp'), true),
        ],
    ];
    $this->ports = [
        ['address' => '0.0.0.0', 'port' => 8080, 'pid' => null, 'process' => null],
        ['address' => '0.0.0.0', 'port' => 7681, 'pid' => 21, 'process' => 'ttyd'],
    ];

    $this->provider->execUsing = function (array $command) {
        $script = $command[2] ?? '';

        return match (true) {
            $command[0] === 'php' && str_contains($script, 'onedrop-server.pid') => new ExecResult(0, json_encode($this->services)),
            $command[0] === 'php' && str_contains($script, '/proc/net/tcp') => new ExecResult(0, json_encode($this->ports)),
            $command[0] === 'bash' && in_array('logs', $command, true) => new ExecResult(0, "worker: li3: No such file or directory\n"),
            default => new ExecResult(0, ''),
        };
    };
});

test('shows what the preview runs, the sandbox\'s containers with their state and ports, and the open ports', function () {
    $this->actingAs($this->user)
        ->getJson(route('projects.services.index', $this->project))
        ->assertOk()
        ->assertJsonPath('preview.command', "export COMPOSE_FILE='compose.yaml'\nexec /opt/onedrop/compose up --preview 8080")
        ->assertJsonPath('preview.running', true)
        ->assertJsonPath('docker', 'up')
        ->assertJsonPath('containers.0', [
            'name' => 'workspace-app-1',
            'project' => 'workspace',
            'service' => 'app',
            'image' => 'app:latest',
            'state' => 'running',
            'status' => 'Up 12 minutes',
            'exit_code' => null,
            'ports' => [['published' => 8080, 'target' => 80]],
        ])
        ->assertJsonPath('containers.1.service', 'worker')
        ->assertJsonPath('containers.1.state', 'restarting')
        ->assertJsonPath('containers.1.exit_code', 127)
        ->assertJsonPath('ports.0.port', 7681)
        ->assertJsonPath('ports.0.role', 'shell')
        ->assertJsonPath('ports.1.port', 8080)
        ->assertJsonPath('ports.1.service', 'app');
})->group('SVC-001');

test('a sandbox without Docker, or whose Docker is down, says so', function (bool $socket, ?array $containers, string $docker) {
    $this->services = [...$this->services, 'docker' => $socket, 'containers' => $containers];

    $this->actingAs($this->user)
        ->getJson(route('projects.services.index', $this->project))
        ->assertOk()
        ->assertJsonPath('docker', $docker)
        ->assertJsonPath('containers', []);
})->with([
    'no Docker' => [false, null, 'off'],
    'Docker not answering' => [true, null, 'down'],
])->group('SVC-001');

test('reads a container\'s latest logs', function () {
    $this->actingAs($this->user)
        ->getJson(route('projects.services.logs', [$this->project, 'workspace-worker-1']))
        ->assertOk()
        ->assertJsonPath('logs', "worker: li3: No such file or directory\n");

    expect(collect($this->provider->executed)->pluck('command')->last())
        ->toBe(['bash', '-c', 'docker logs --tail "$1" "$2" 2>&1', 'logs', '200', 'workspace-worker-1']);
})->group('SVC-001');

test('reads more log lines for the expanded view, up to a limit', function () {
    $this->actingAs($this->user)
        ->getJson(route('projects.services.logs', [$this->project, 'workspace-worker-1', 'lines' => 2000]))
        ->assertOk();

    expect(collect($this->provider->executed)->pluck('command')->last())
        ->toBe(['bash', '-c', 'docker logs --tail "$1" "$2" 2>&1', 'logs', '2000', 'workspace-worker-1']);

    $this->actingAs($this->user)
        ->getJson(route('projects.services.logs', [$this->project, 'workspace-worker-1', 'lines' => 2001]))
        ->assertJsonValidationErrors('lines');
})->group('SVC-001');

test('stops and starts a container in the background', function (string $action) {
    $this->actingAs($this->user)
        ->putJson(route('projects.services.update', [$this->project, 'workspace-app-1']), ['action' => $action])
        ->assertOk();

    $last = collect($this->provider->executed)->last();
    expect($last['command'])->toBe(['docker', $action, 'workspace-app-1'])
        ->and($last['detach'])->toBeTrue();
})->with(['stop', 'start'])->group('SVC-001');

test('restarts a container in the background, recreating a compose service whose config changed', function () {
    $this->actingAs($this->user)
        ->putJson(route('projects.services.update', [$this->project, 'workspace-app-1']), ['action' => 'restart'])
        ->assertOk();

    $last = collect($this->provider->executed)->last();
    expect($last['command'][0])->toBe('bash')
        ->and($last['command'][2])->toContain('config --hash "$service"')
        ->toContain('up --detach --no-deps "$service"')
        ->toContain('exec docker restart "$name"')
        ->and(array_slice($last['command'], 3))->toBe(['restart', 'workspace-app-1'])
        ->and($last['detach'])->toBeTrue();
})->group('SVC-001');

test('only the sandbox\'s own containers and known actions are accepted', function () {
    $this->actingAs($this->user)
        ->putJson(route('projects.services.update', [$this->project, '--help']), ['action' => 'restart'])
        ->assertStatus(502)
        ->assertJsonPath('message', 'There is no container called --help in this sandbox.');

    $this->actingAs($this->user)
        ->getJson(route('projects.services.logs', [$this->project, 'onedrop-other']))
        ->assertStatus(502);

    $this->actingAs($this->user)
        ->putJson(route('projects.services.update', [$this->project, 'workspace-app-1']), ['action' => 'rm'])
        ->assertJsonValidationErrors('action');

    expect(collect($this->provider->executed)->pluck('command')->filter(fn (array $command) => $command[0] === 'docker'))->toBeEmpty();
})->group('SVC-001');

test('restarts the preview server', function () {
    $this->actingAs($this->user)
        ->postJson(route('projects.services.preview.restart', $this->project))
        ->assertOk();

    expect(collect($this->provider->executed)->pluck('command')->last())->toBe(['/opt/onedrop/restart']);
})->group('SVC-001');

test('a stopped sandbox says why nothing is shown', function () {
    $this->project->sandbox->update(['status' => SandboxStatus::Failed]);

    $this->actingAs($this->user)
        ->getJson(route('projects.services.index', $this->project))
        ->assertStatus(409)
        ->assertJson(['message' => "The project's sandbox isn't running."]);
})->group('SVC-001');

test('other users cannot see or change what runs in the sandbox', function () {
    $other = User::factory()->has(AgentConnection::factory())->create();

    $this->actingAs($other)->getJson(route('projects.services.index', $this->project))->assertForbidden();
    $this->actingAs($other)->putJson(route('projects.services.update', [$this->project, 'workspace-app-1']), ['action' => 'stop'])->assertForbidden();
    $this->actingAs($other)->postJson(route('projects.services.preview.restart', $this->project))->assertForbidden();

    expect($this->provider->executed)->toBe([]);
})->group('SVC-001');
