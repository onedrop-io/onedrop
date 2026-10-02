<?php

use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\ExecResult;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;

test('the Services tab shows the preview server, the compose stack and the ports, and restarts a crashing service', function () {
    $container = fn (string $service, string $state, string $status, string $ports = '') => [
        'Names' => "workspace-{$service}-1",
        'Image' => "{$service}:latest",
        'State' => $state,
        'Status' => $status,
        'Ports' => $ports,
        'Labels' => "com.docker.compose.project=workspace,com.docker.compose.service={$service}",
    ];

    $provider = new FakeSandboxProvider;
    $provider->execUsing = fn (array $command) => match (true) {
        $command[0] === 'php' && str_contains($command[2] ?? '', 'onedrop-server.pid') => new ExecResult(0, json_encode([
            'dev' => 'exec /opt/onedrop/compose up --preview 8080',
            'running' => true,
            'docker' => true,
            'containers' => [
                $container('app', 'running', 'Up 12 minutes', '0.0.0.0:8080->80/tcp'),
                $container('worker_emails', 'restarting', 'Restarting (127) 5 seconds ago'),
            ],
        ])),
        $command[0] === 'php' && str_contains($command[2] ?? '', '/proc/net/tcp') => new ExecResult(0, json_encode([
            ['address' => '0.0.0.0', 'port' => 8080, 'pid' => null, 'process' => null],
            ['address' => '0.0.0.0', 'port' => 7681, 'pid' => 21, 'process' => 'ttyd'],
        ])),
        $command[0] === 'bash' && in_array('logs', $command, true) => new ExecResult(0, "li3: No such file or directory\n"),
        default => new ExecResult(0, ''),
    };
    app()->instance(SandboxProvider::class, $provider);

    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['preview_url' => null, 'shell_url' => null]);
    $this->actingAs($user);

    visit("/projects/{$project->id}")
        ->click('@add-tab')
        ->click('@add-tab-services')
        ->assertSeeIn('@services-preview', 'exec /opt/onedrop/compose up --preview 8080')
        ->assertSeeIn('@service-app', 'Up 12 minutes')
        ->assertSeeIn('@service-app', '8080→80')
        ->assertSeeIn('@service-worker_emails', 'Restarting (127)')
        ->assertSeeIn('@services-ports', 'app (Docker)')
        ->assertSeeIn('@services-ports', 'Shell tab')
        ->click('[data-test="service-worker_emails"] [data-test="service-logs"]')
        ->assertSeeIn('@service-log', 'li3: No such file or directory')
        ->click('[data-test="service-worker_emails"] [data-test="service-restart"]')
        ->assertNoJavaScriptErrors();

    expect(collect($provider->executed)->pluck('command')->contains(fn (array $command) => array_slice($command, -2) === ['restart', 'workspace-worker_emails-1']))->toBeTrue();
})->group('SVC-001');
