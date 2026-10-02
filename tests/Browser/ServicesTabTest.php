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
        $command[0] === 'bash' && in_array('logs', $command, true) => new ExecResult(0, "li3: No such file or directory\n\e[31mpanicked at main.rs\e[0m\n2026-10-02T12:00:00.123Z ERROR openobserve::job: \"ingest\" failed\n"),
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
        ->click('@service-worker_emails')
        ->assertSeeIn('@service-log', 'li3: No such file or directory')
        ->assertSeeIn('[data-test="service-log"] .text-red-400', 'panicked at main.rs')
        ->assertSeeIn('[data-test="service-log"] .font-semibold', 'ERROR')
        ->assertSeeIn('[data-test="service-log"] .text-neutral-500', '2026-10-02T12:00:00.123Z')
        ->assertDontSeeIn('@service-log', '[31m')
        ->type('@service-log-search', 'openobserve')
        ->assertSeeIn('@service-log-matches', '1 line')
        ->assertSeeIn('[data-test="service-log"] mark', 'openobserve')
        ->assertDontSeeIn('@service-log-lines', 'li3: No such file or directory')
        ->click('@service-log-expand')
        ->assertSeeIn('@service-log-expanded', 'Logs of workspace-worker_emails-1')
        ->assertSeeIn('[data-test="service-log-expanded"] [data-test="service-log-lines"]', '"ingest" failed')
        ->assertDontSeeIn('[data-test="service-log-expanded"] [data-test="service-log-lines"]', 'li3: No such file or directory')
        ->assertValue('[data-test="service-log-expanded"] [data-test="service-log-search"]', 'openobserve')
        ->keys('[data-test="service-log-expanded"] [data-test="service-log-search"]', ['Escape'])
        ->click('[data-test="service-worker_emails"] [data-test="service-restart"]')
        ->assertVisible('@service-log')
        ->click('@service-worker_emails')
        ->assertMissing('@service-log')
        ->assertNoJavaScriptErrors();

    expect(collect($provider->executed)->pluck('command')->contains(fn (array $command) => array_slice($command, -2) === ['restart', 'workspace-worker_emails-1']))->toBeTrue()
        ->and(collect($provider->executed)->pluck('command')->contains(fn (array $command) => array_slice($command, -2) === ['2000', 'workspace-worker_emails-1']))->toBeTrue();
})->group('SVC-001');

test('a container\'s logs can be searched like Papertrail, a match shown in context, and tailed live', function () {
    $logs = "GET /healthcheck 200\nGET /healthcheck 200\n2026-10-02T12:00:00.123Z ERROR openobserve::job: \"ingest\" failed\nconnection refused by redis\nGET /api/users 500\n";

    $provider = new FakeSandboxProvider;
    $provider->execUsing = function (array $command) use (&$logs) {
        return match (true) {
            $command[0] === 'php' && str_contains($command[2] ?? '', 'onedrop-server.pid') => new ExecResult(0, json_encode([
                'dev' => 'exec /opt/onedrop/compose up --preview 8080',
                'running' => true,
                'docker' => true,
                'containers' => [[
                    'Names' => 'workspace-app-1',
                    'Image' => 'app:latest',
                    'State' => 'running',
                    'Status' => 'Up 12 minutes',
                    'Ports' => '',
                    'Labels' => 'com.docker.compose.project=workspace,com.docker.compose.service=app',
                ]],
            ])),
            $command[0] === 'php' => new ExecResult(0, '[]'),
            $command[0] === 'bash' && in_array('logs', $command, true) => new ExecResult(0, $logs),
            default => new ExecResult(0, ''),
        };
    };
    app()->instance(SandboxProvider::class, $provider);

    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['preview_url' => null, 'shell_url' => null]);
    $this->actingAs($user);

    $page = visit("/projects/{$project->id}")
        ->click('@add-tab')
        ->click('@add-tab-services')
        ->click('[data-test="service-app"] [data-test="service-logs"]')
        ->assertSeeIn('@service-log-lines', 'connection refused by redis')
        ->type('@service-log-search', 'get -healthcheck')
        ->assertSeeIn('@service-log-matches', '1 line')
        ->assertSeeIn('@service-log-lines', 'GET /api/users 500')
        ->assertDontSeeIn('@service-log-lines', 'healthcheck')
        ->type('@service-log-search', '"connection refused" OR 500')
        ->assertSeeIn('@service-log-matches', '2 lines')
        ->assertSeeIn('[data-test="service-log-lines"] mark', 'connection refused')
        ->type('@service-log-search', '(error OR refused) -redis')
        ->assertSeeIn('@service-log-matches', '1 line')
        ->click('[data-test="service-log-lines"] [data-line="2"]')
        ->assertValue('@service-log-search', '')
        ->assertSeeIn('@service-log-lines', 'GET /api/users 500')
        ->assertPresent('[data-line="2"].bg-amber-400\/15');

    $logs .= "worker picked up job 42\n";

    $page->click('@service-log-live')
        ->waitForText('worker picked up job 42')
        ->assertNoJavaScriptErrors();
})->group('SVC-001');
