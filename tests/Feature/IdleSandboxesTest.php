<?php

use App\Console\Commands\SuspendIdleSandboxes;
use App\Enums\ProjectStatus;
use App\Enums\PublishStatus;
use App\Enums\SandboxMovePhase;
use App\Enums\SandboxStatus;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\SandboxMove;
use App\Models\Task;
use App\Models\User;
use App\Sandbox\ExecResult;
use App\Sandbox\Gateway;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxProvider;

beforeEach(function () {
    // Docker is the configured provider, so its sandboxes don't count as outdated (SBX-005) when a project opens.
    config(['sandbox.provider' => 'docker', 'sandbox.providers.docker.idle_seconds' => 60, 'sandbox.providers.docker.stop_after_minutes' => 5]);

    $this->provider = new FakeSandboxProvider;
    app()->instance(SandboxProvider::class, $this->provider);

    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->project = Project::factory()->for($this->user)->create();
    $this->sandbox = Sandbox::factory()->for($this->project)->create([
        'provider' => 'docker',
        'external_id' => 'ctr-1',
        'last_active_at' => now()->subMinutes(20),
    ]);
});

test('a docker sandbox nobody has used for a while is suspended', function () {
    $this->artisan('sandbox:suspend-idle')->assertSuccessful();

    expect($this->provider->suspended)->toBe(['ctr-1'])
        ->and($this->sandbox->fresh()->suspended_at)->not->toBeNull();
})->group('SBX-007');

test('sandboxes in use, already suspended, or not running are left alone', function (array $attributes) {
    $this->sandbox->forceFill($attributes)->save();

    $this->artisan('sandbox:suspend-idle')->assertSuccessful();

    expect($this->provider->suspended)->toBe([]);
})->with([
    // Closures, so the times are taken when the test runs, not when the suite loads.
    'used recently' => [fn () => ['last_active_at' => now()->subSeconds(30)]],
    'already suspended' => [fn () => ['suspended_at' => now()->subMinutes(10)]],
    'stopped' => [fn () => ['status' => SandboxStatus::Paused]],
    'on a managed provider, which pauses by itself' => [fn () => ['provider' => 'runtime']],
])->group('SBX-007');

test('a sandbox whose agent is working, whose app is published, or that is updating is never suspended', function (Closure $setUp) {
    $setUp($this->project);

    $this->artisan('sandbox:suspend-idle')->assertSuccessful();

    expect($this->provider->suspended)->toBe([]);
})->with([
    'agent working' => [fn (Project $project) => $project->update(['status' => ProjectStatus::Working])],
    'published' => [fn (Project $project) => $project->update(['publish_status' => PublishStatus::Live])],
    'updating' => [fn (Project $project) => SandboxMove::query()->create(['project_id' => $project->id, 'sandbox_id' => $project->sandbox->id, 'reason' => 'update', 'phase' => SandboxMovePhase::Restoring])],
])->group('SBX-007');

test('an idle task copy is suspended unless its task is working', function (bool $working, array $suspended) {
    $task = Task::factory()->for($this->project)->create($working ? ['status' => ProjectStatus::Working] : []);
    $this->sandbox->forceFill(['last_active_at' => now()])->save();
    Sandbox::factory()->for($this->project)->create([
        'task_id' => $task->id,
        'provider' => 'docker',
        'external_id' => 'copy-1',
        'last_active_at' => now()->subHour(),
    ]);

    $this->artisan('sandbox:suspend-idle')->assertSuccessful();

    expect($this->provider->suspended)->toBe($suspended);
})->with([
    'idle task' => [false, ['copy-1']],
    'working task' => [true, []],
])->group('SBX-007');

test('a sandbox is suspended a minute after its last use', function () {
    $this->sandbox->forceFill(['last_active_at' => now()->subSeconds(61)])->save();

    $this->artisan('sandbox:suspend-idle')->assertSuccessful();

    expect($this->provider->suspended)->toBe(['ctr-1']);
})->group('SBX-007');

test('network traffic counts as use, so a preview open in its own tab keeps the sandbox awake', function () {
    $counters = "100\n200\n";
    $this->provider->execUsing = function (array $command) use (&$counters) {
        return new ExecResult(0, $command === SuspendIdleSandboxes::TRAFFIC ? $counters : '');
    };

    // The first check has nothing to compare with.
    $this->artisan('sandbox:suspend-idle')->assertSuccessful();
    expect($this->provider->suspended)->toBe(['ctr-1']);

    $this->provider->suspended = [];
    $this->sandbox->forceFill(['suspended_at' => null, 'last_active_at' => now()->subMinutes(5)])->save();
    $counters = "180\n260\n";

    $this->artisan('sandbox:suspend-idle')->assertSuccessful();

    expect($this->provider->suspended)->toBe([])
        ->and($this->sandbox->fresh()->last_active_at->isAfter(now()->subSeconds(5)))->toBeTrue();
})->group('SBX-007');

test('a sandbox suspended for a while is stopped, freeing its memory', function () {
    $this->sandbox->forceFill(['suspended_at' => now()->subMinutes(6), 'last_active_at' => now()->subMinutes(7)])->save();

    $this->artisan('sandbox:suspend-idle')->assertSuccessful();

    expect($this->provider->paused)->toBe(['ctr-1'])
        ->and($this->sandbox->fresh()->stopped_at)->not->toBeNull();
})->group('SBX-007');

test('a suspended sandbox is not stopped too soon, twice, or while it is in use', function (array $attributes, ?Closure $setUp = null) {
    $this->sandbox->forceFill(['suspended_at' => now()->subMinutes(6), 'last_active_at' => now()->subMinutes(7), ...$attributes])->save();
    $setUp && $setUp($this->project);

    $this->artisan('sandbox:suspend-idle')->assertSuccessful();

    expect($this->provider->paused)->toBe([]);
})->with([
    'suspended recently' => [['suspended_at' => now()->subMinutes(2)]],
    'already stopped' => [['stopped_at' => now()->subMinute()]],
    'used since, e.g. by an agent run' => [['last_active_at' => now()->subMinute()]],
    'agent working' => [[], fn (Project $project) => $project->update(['status' => ProjectStatus::Working])],
])->group('SBX-007');

test('stopping can be turned off', function () {
    config(['sandbox.providers.docker.stop_after_minutes' => 0]);
    $this->sandbox->forceFill(['suspended_at' => now()->subHour(), 'last_active_at' => now()->subHour()])->save();

    $this->artisan('sandbox:suspend-idle')->assertSuccessful();

    expect($this->provider->paused)->toBe([]);
})->group('SBX-007');

test('waking a stopped sandbox clears both marks', function () {
    $this->sandbox->forceFill(['suspended_at' => now()->subHour(), 'stopped_at' => now()->subMinutes(30)])->save();

    $this->actingAs($this->user)->post(route('projects.sandbox.activity', $this->project))->assertOk()->assertJson(['woke' => true]);

    $sandbox = $this->sandbox->fresh();
    expect($sandbox->suspended_at)->toBeNull()->and($sandbox->stopped_at)->toBeNull();
})->group('SBX-007');

test('idle suspension can be turned off', function () {
    config(['sandbox.providers.docker.idle_seconds' => 0]);

    $this->artisan('sandbox:suspend-idle')->assertSuccessful();

    expect($this->provider->suspended)->toBe([]);
})->group('SBX-007');

test('a sandbox that can\'t be suspended stays marked as running', function () {
    $this->provider = new class extends FakeSandboxProvider
    {
        public function suspend(string $id): void
        {
            throw new SandboxException('Docker is not running.');
        }
    };
    app()->instance(SandboxProvider::class, $this->provider);

    $this->artisan('sandbox:suspend-idle')->assertSuccessful();

    expect($this->sandbox->fresh()->suspended_at)->toBeNull();
})->group('SBX-007');

test('a sandbox whose container was removed outside the app is marked as failed', function () {
    $this->provider = new class extends FakeSandboxProvider
    {
        public function suspend(string $id): void
        {
            throw new SandboxException("Docker error: Error response from daemon: No such container: {$id}");
        }
    };
    app()->instance(SandboxProvider::class, $this->provider);

    $this->artisan('sandbox:suspend-idle')->assertSuccessful();

    $sandbox = $this->sandbox->fresh();
    expect($sandbox->status)->toBe(SandboxStatus::Failed)
        ->and($sandbox->error)->toBe("The sandbox's container no longer exists.");
})->group('SBX-007');

test('opening the project wakes its suspended sandbox', function () {
    $this->sandbox->forceFill(['suspended_at' => now()->subMinutes(5)])->save();

    $this->actingAs($this->user)->get(route('projects.show', $this->project))->assertOk();

    $sandbox = $this->sandbox->fresh();
    expect($this->provider->woken)->toBe(['ctr-1'])
        ->and($sandbox->suspended_at)->toBeNull()
        ->and($sandbox->last_active_at->isAfter(now()->subMinute()))->toBeTrue();
})->group('SBX-007');

test('the sidebar reloading the project page in the background doesn\'t count as using its sandbox; reloading the sandbox does', function () {
    $this->sandbox->forceFill(['suspended_at' => now()->subMinutes(5), 'last_active_at' => now()->subMinutes(30)])->save();
    $reload = fn (string $only) => $this->actingAs($this->user)->get(route('projects.show', $this->project), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
        'X-Inertia-Partial-Component' => 'projects/show',
        'X-Inertia-Partial-Data' => $only,
    ])->assertOk();

    $reload('sidebarProjects,openProject');

    expect($this->provider->woken)->toBe([])
        ->and($this->sandbox->fresh()->suspended_at)->not->toBeNull()
        ->and($this->sandbox->fresh()->last_active_at->isBefore(now()->subMinutes(29)))->toBeTrue();

    $reload('project,sandbox,messages');

    expect($this->provider->woken)->toBe(['ctr-1'])->and($this->sandbox->fresh()->suspended_at)->toBeNull();
})->group('SBX-007', 'LIVE-001');

test('a reload of the sandbox from a tab nobody is looking at doesn\'t count as using it', function () {
    $this->sandbox->forceFill(['suspended_at' => now()->subMinutes(5)])->save();

    $this->actingAs($this->user)->get(route('projects.show', $this->project), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
        'X-Inertia-Partial-Component' => 'projects/show',
        'X-Inertia-Partial-Data' => 'project,sandbox,messages',
        'X-Onedrop-Unseen' => '1',
    ])->assertOk();

    expect($this->provider->woken)->toBe([])->and($this->sandbox->fresh()->suspended_at)->not->toBeNull();
})->group('SBX-007', 'SBX-014');

test('an open workspace keeps its sandbox in use and wakes it if it was suspended', function () {
    $this->sandbox->forceFill(['suspended_at' => now()])->save();

    $this->actingAs($this->user)->post(route('projects.sandbox.activity', $this->project))->assertOk()->assertJson(['woke' => true]);

    $sandbox = $this->sandbox->fresh();
    expect($this->provider->woken)->toBe(['ctr-1'])
        ->and($sandbox->suspended_at)->toBeNull()
        ->and($sandbox->last_active_at->isAfter(now()->subMinute()))->toBeTrue();
})->group('SBX-007');

test('a docker container stopped outside the app starts again when its project is opened, on its new ports', function () {
    $this->provider = new class extends FakeSandboxProvider
    {
        public bool $wakes = true;

        public function previewUrl(string $id, int $port): ?string
        {
            return "http://127.0.0.1:4{$port}";
        }
    };
    app()->instance(SandboxProvider::class, $this->provider);
    $this->sandbox->forceFill(['preview_url' => 'http://127.0.0.1:33457'])->save();

    $this->actingAs($this->user)->get(route('projects.show', $this->project))->assertOk();

    expect($this->provider->woken)->toBe(['ctr-1'])
        ->and($this->sandbox->fresh()->preview_url)->toBe('http://127.0.0.1:4'.config('sandbox.proxy_port'));
})->group('SBX-007');

test('an open workspace on an awake sandbox says it didn\'t need waking, so the preview isn\'t reloaded', function () {
    $this->actingAs($this->user)->post(route('projects.sandbox.activity', $this->project))->assertOk()->assertJson(['woke' => false]);
})->group('SBX-007');

test('a docker container stopped outside the app counts as woken, so the preview reloads', function () {
    $this->provider->wakes = true;

    $this->actingAs($this->user)->post(route('projects.sandbox.activity', $this->project))->assertOk()->assertJson(['woke' => true]);
})->group('SBX-007');

test('a running docker container is checked at most every 30 seconds', function () {
    $this->actingAs($this->user)->post(route('projects.sandbox.activity', $this->project));
    $this->actingAs($this->user)->post(route('projects.sandbox.activity', $this->project));

    expect($this->provider->woken)->toBe(['ctr-1']);
})->group('SBX-007');

test('an open task workspace wakes the task\'s own copy', function () {
    $task = Task::factory()->for($this->project)->create();
    Sandbox::factory()->for($this->project)->create(['task_id' => $task->id, 'external_id' => 'copy-1', 'suspended_at' => now()]);

    $this->actingAs($this->user)->post(route('projects.sandbox.activity', $this->project), ['task' => $task->id])->assertJson(['woke' => true]);

    expect($this->provider->woken)->toBe(['copy-1']);
})->group('SBX-007');

test('only people who can see the project can keep its sandbox awake', function () {
    $this->actingAs(User::factory()->has(AgentConnection::factory())->create())->post(route('projects.sandbox.activity', $this->project))->assertForbidden();

    expect($this->sandbox->fresh()->last_active_at->isBefore(now()->subMinutes(15)))->toBeTrue();
})->group('SBX-007');

test('the gateway wakes a suspended sandbox before sending someone to it', function () {
    config(['sandbox.gateway_domain' => 'onedrop.example.com']);
    $this->sandbox->forceFill(['suspended_at' => now(), 'preview_url' => 'http://127.0.0.1:32800'])->save();
    $host = "preview-{$this->sandbox->id}.onedrop.example.com";
    $pass = app(Gateway::class)->pass($this->user->id, app(Gateway::class)->parse($host));

    $this->withHeaders(['X-Forwarded-Host' => $host])->withCookie(Gateway::COOKIE, $pass)
        ->get(route('sandbox-gateway.authorize'))
        ->assertOk();

    expect($this->provider->woken)->toBe(['ctr-1'])
        ->and($this->sandbox->fresh()->suspended_at)->toBeNull();
})->group('SBX-007');
