<?php

use App\Enums\SandboxStatus;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\Providers\RoutingSandboxProvider;
use App\Sandbox\Publishing\FakePublisher;
use App\Sandbox\Publishing\Publisher;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxProvider;
use App\Sandbox\SandboxSpec;
use App\Sandbox\SandboxUpdater;

beforeEach(function () {
    $this->blaxel = new FakeSandboxProvider;
    $this->runtime = new FakeSandboxProvider;
    $this->route = fn (string $default) => new RoutingSandboxProvider([
        'blaxel' => fn () => $this->blaxel,
        'runtime' => fn () => $this->runtime,
    ], $default);

    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->project = Project::factory()->for($this->user)->create(['agent_session_id' => 'ses_1']);
});

test('each sandbox is driven by the provider it was created on', function () {
    Sandbox::factory()->for($this->project)->create(['provider' => 'blaxel', 'external_id' => 'bl-1']);
    $router = ($this->route)('runtime');

    $router->exec('bl-1', ['ls']);
    $router->pause('bl-1');

    expect($this->blaxel->executed)->toHaveCount(1)
        ->and($this->blaxel->paused)->toBe(['bl-1'])
        ->and($this->runtime->executed)->toBe([]);
})->group('SBX-005');

test('new sandboxes are created on the configured provider and remembered there', function () {
    $router = ($this->route)('runtime');

    $id = $router->create(new SandboxSpec('onedrop-project-1-x'));
    $router->exec($id, ['ls']);

    expect($this->runtime->created)->toHaveKey($id)
        ->and($this->runtime->executed)->toHaveCount(1)
        ->and($this->blaxel->created)->toBe([]);
})->group('SBX-005');

test('a sandbox on a provider that is not set up says so', function () {
    Sandbox::factory()->for($this->project)->create(['provider' => 'e2b', 'external_id' => 'e2b-1']);

    expect(fn () => ($this->route)('runtime')->exec('e2b-1', ['ls']))
        ->toThrow(SandboxException::class, 'Sandbox provider [e2b] isn\'t available');
})->group('SBX-005');

test('switching providers moves a project to the new one with its files, and removes the old sandbox there', function () {
    $sandbox = Sandbox::factory()->for($this->project)->create(['provider' => 'blaxel', 'external_id' => 'bl-1']);
    $this->blaxel->created['bl-1'] = new SandboxSpec('onedrop-project-old');
    config(['sandbox.provider' => 'runtime']);
    app()->instance(SandboxProvider::class, ($this->route)('runtime'));
    app()->instance(Publisher::class, new FakePublisher);

    expect(app(SandboxUpdater::class)->isOutdated($this->project))->toBeTrue()
        ->and(app(SandboxUpdater::class)->updateIfOutdated($this->project))->toBeTrue();

    $sandbox->refresh();
    $copies = collect([...$this->blaxel->copied, ...$this->runtime->copied]);

    expect($sandbox->provider)->toBe('runtime')
        ->and($sandbox->status)->toBe(SandboxStatus::Running)
        ->and($this->runtime->created)->toHaveKey($sandbox->external_id)
        ->and($this->blaxel->created)->not->toHaveKey('bl-1')
        ->and($this->blaxel->paused)->toBe(['bl-1'])
        ->and(collect($this->blaxel->copied)->pluck(0)->unique()->all())->toBe(['out'])
        ->and(collect($this->runtime->copied)->pluck(0)->unique()->all())->toBe(['in'])
        ->and($copies->where(0, 'in')->pluck(2)->all())->toBe(SandboxUpdater::KEPT_PATHS)
        ->and($this->project->fresh()->agent_session_id)->toBe('ses_1')
        ->and(app(SandboxUpdater::class)->isOutdated($this->project->fresh()))->toBeFalse();
})->group('SBX-005');

test('a sandbox already on the configured provider is not moved', function () {
    Sandbox::factory()->for($this->project)->create(['provider' => 'runtime', 'external_id' => 'rt-1']);
    config(['sandbox.provider' => 'runtime']);
    app()->instance(SandboxProvider::class, ($this->route)('runtime'));

    expect(app(SandboxUpdater::class)->isOutdated($this->project))->toBeFalse();
})->group('SBX-005');

test('deleting a project removes its sandbox on the provider it was created on, not the configured one', function () {
    Sandbox::factory()->for($this->project)->create(['provider' => 'blaxel', 'external_id' => 'bl-1']);
    $this->blaxel->created['bl-1'] = new SandboxSpec('onedrop-project-old');
    $this->runtime->created['bl-1'] = new SandboxSpec('onedrop-project-other');
    app()->instance(SandboxProvider::class, ($this->route)('runtime'));

    $this->actingAs($this->user)->delete(route('projects.destroy', $this->project));

    expect($this->blaxel->created)->not->toHaveKey('bl-1')
        ->and($this->runtime->created)->toHaveKey('bl-1');
})->group('PRJ-003');
