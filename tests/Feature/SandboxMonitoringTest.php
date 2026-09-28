<?php

use App\Enums\SandboxStatus;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\ExecResult;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxMonitoring;
use App\Sandbox\SandboxProvider;

beforeEach(function () {
    $this->now = 1_790_000_000 - (1_790_000_000 % 3600) + 1800; // half past an hour
    $this->provider = new FakeSandboxProvider;
    app()->instance(SandboxProvider::class, $this->provider);

    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->project = Project::factory()->for($this->user)->create();
    $this->sandbox = Sandbox::factory()->for($this->project)->create(['external_id' => 'ctr-1']);
});

function logLines(array $entries): string
{
    return implode("\n", array_map('json_encode', $entries))."\nnot json\n";
}

test('requests are counted per bucket with status classes, durations and unique visitors', function () {
    $ms = fn (int $secondsAgo) => ($this->now - $secondsAgo) * 1000;
    $this->provider->execUsing = fn () => new ExecResult(0, logLines([
        ['t' => $ms(30), 's' => 200, 'd' => 12, 'ip' => '1.1.1.1', 'pub' => true],
        ['t' => $ms(40), 's' => 200, 'd' => 180, 'ip' => '1.1.1.1', 'pub' => false],
        ['t' => $ms(4000), 's' => 404, 'd' => 600, 'ip' => '2.2.2.2', 'pub' => true],
        ['t' => $ms(5000), 's' => 503, 'd' => 1500, 'ip' => '3.3.3.3', 'pub' => true],
        ['t' => $ms(3 * 86400), 's' => 200, 'd' => 5, 'ip' => '9.9.9.9', 'pub' => true], // outside 24h
    ]));

    $app = app(SandboxMonitoring::class)->application($this->sandbox, '24h', now: $this->now);

    expect($app['total'])->toBe(4)
        ->and($app['unique_ips'])->toBe(3)
        ->and($app['error_rate'])->toBe(0.25)
        ->and($app['bucket_seconds'])->toBe(3600)
        ->and($app['requests'])->toHaveCount(24)
        ->and(end($app['requests'])['count'])->toBe(2)
        ->and(array_sum(array_column($app['requests'], 'count')))->toBe(4)
        ->and(array_sum(array_column($app['statuses'], '4xx')))->toBe(1)
        ->and(array_sum(array_column($app['statuses'], '5xx')))->toBe(1)
        ->and(array_column($app['durations'], 'count'))->toBe([1, 0, 1, 0, 1, 1]);
})->group('MON-001');

test('traffic can be limited to the published address', function () {
    $this->provider->execUsing = fn () => new ExecResult(0, logLines([
        ['t' => ($this->now - 10) * 1000, 's' => 200, 'd' => 5, 'ip' => 'a', 'pub' => true],
        ['t' => ($this->now - 20) * 1000, 's' => 200, 'd' => 5, 'ip' => 'b', 'pub' => false],
    ]));

    $app = app(SandboxMonitoring::class)->application($this->sandbox, '1h', publishedOnly: true, now: $this->now);

    expect($app['total'])->toBe(1)->and($app['requests'])->toHaveCount(60);
})->group('MON-001');

test('cpu and memory are averaged per bucket, with gaps where there are no samples', function () {
    $this->provider->execUsing = fn () => new ExecResult(0, logLines([
        ['t' => ($this->now - 30) * 1000, 'cpu' => 0.10, 'mem' => 200 * 1048576, 'memMax' => 2048 * 1048576],
        ['t' => ($this->now - 20) * 1000, 'cpu' => 0.30, 'mem' => 300 * 1048576, 'memMax' => 2048 * 1048576],
    ]));

    $infra = app(SandboxMonitoring::class)->infrastructure($this->sandbox, '1h', now: $this->now);
    $withData = array_values(array_filter($infra['cpu'], fn ($point) => $point['value'] !== null));
    $memory = array_values(array_filter($infra['memory'], fn ($point) => $point['value'] !== null));

    // Both samples fall in the minute before "now": one averaged point, gaps everywhere else.
    expect($infra['memory_limit_mb'])->toBe(2048)
        ->and($infra['cpu'])->toHaveCount(60)
        ->and($withData)->toHaveCount(1)
        ->and($withData[0])->toBe(['t' => $this->now - 60, 'value' => 20.0])
        ->and($memory[0]['value'])->toBe(250.0);
})->group('MON-001');

test('the endpoint returns both panels for the owner and reads both log files', function () {
    $this->provider->execUsing = fn () => new ExecResult(0, '');

    $this->actingAs($this->user)
        ->getJson(route('projects.monitoring.show', [$this->project, 'range' => '7d', 'infra_range' => '24h', 'traffic' => 'published']))
        ->assertOk()
        ->assertJsonPath('application.range', '7d')
        ->assertJsonPath('application.total', 0)
        ->assertJsonPath('infrastructure.range', '24h')
        ->assertJsonCount(96, 'infrastructure.cpu');

    $commands = array_map(fn ($call) => implode(' ', $call['command']), $this->provider->executed);
    expect($commands[0])->toContain('/workspace/.zap/access.log')
        ->and($commands[1])->toContain('/workspace/.zap/metrics.log');
})->group('MON-001');

test('the endpoint validates ranges, requires access, and explains a stopped sandbox', function () {
    $this->actingAs($this->user)->getJson(route('projects.monitoring.show', [$this->project, 'range' => '1y']))->assertUnprocessable();
    $this->actingAs(User::factory()->has(AgentConnection::factory())->create())->getJson(route('projects.monitoring.show', $this->project))->assertForbidden();

    $this->sandbox->update(['status' => SandboxStatus::Paused]);
    $this->actingAs($this->user)->getJson(route('projects.monitoring.show', $this->project))->assertStatus(409);
})->group('MON-001');
