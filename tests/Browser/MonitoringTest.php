<?php

use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\ExecResult;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;

/**
 * A realistic day of traffic and an hour of resource samples, served as the sandbox's log files.
 */
function fakeMonitoringProvider(): FakeSandboxProvider
{
    $now = time();
    $access = [];
    for ($i = 0; $i < 400; $i++) {
        $status = [200, 200, 200, 200, 304, 404, 500][$i % 7];
        $access[] = json_encode(['t' => ($now - $i * 200) * 1000, 's' => $status, 'd' => [20, 90, 120, 260, 700, 1400][$i % 6], 'ip' => '10.0.0.'.($i % 5), 'pub' => $i % 3 === 0]);
    }
    $metrics = [];
    for ($i = 0; $i < 60; $i++) {
        $metrics[] = json_encode(['t' => ($now - $i * 60) * 1000, 'cpu' => 0.05 + ($i % 10) / 100, 'mem' => (300 + $i) * 1048576, 'memMax' => 2048 * 1048576]);
    }

    $provider = new FakeSandboxProvider;
    $provider->execUsing = fn (array $command) => new ExecResult(0, implode("\n", str_contains(implode(' ', $command), 'metrics.log') ? $metrics : $access));

    return $provider;
}

test('the monitoring section shows traffic and resource charts', function () {
    app()->instance(SandboxProvider::class, fakeMonitoringProvider());
    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['preview_url' => null]);
    $this->actingAs($user);

    visit("/projects/{$project->id}")
        ->resize(1500, 1000)
        ->click('@tab-tools')
        ->click('@tool-monitoring')
        ->assertVisible('@monitoring-panel')
        ->assertSeeIn('@monitoring-stats', '400')
        ->assertSeeIn('@monitoring-stats', 'Unique visitors')
        ->assertVisible('@chart-requests')
        ->assertVisible('@chart-statuses')
        ->assertVisible('@chart-durations')
        ->assertVisible('@chart-cpu')
        ->assertVisible('@chart-memory')
        ->assertSee('Memory (of 2,048 MB)')
        ->click('[aria-checked="false"]')
        ->assertSeeIn('@monitoring-stats', '134')
        ->assertNoJavaScriptErrors();
})->group('MON-001');

test('monitoring explains when the sandbox is not running', function () {
    app()->instance(SandboxProvider::class, new FakeSandboxProvider);
    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['status' => 'paused', 'preview_url' => null]);
    $this->actingAs($user);

    visit("/projects/{$project->id}")
        ->click('@tab-tools')
        ->click('@tool-monitoring')
        ->assertSeeIn('@monitoring-empty', 'Monitoring starts when the sandbox is running')
        ->assertNoJavaScriptErrors();
})->group('MON-001');
