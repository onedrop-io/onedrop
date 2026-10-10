<?php

use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;

test('the preview reloads when the open workspace wakes its sandbox', function () {
    // Waking a container may move its ports, so its addresses are read again.
    $provider = new class extends FakeSandboxProvider
    {
        public function previewUrl(string $id, int $port): ?string
        {
            return "http://127.0.0.1:{$port}/";
        }
    };
    app()->instance(SandboxProvider::class, $provider);
    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    $sandbox = Sandbox::factory()->for($project)->create(['external_id' => 'ctr', 'preview_url' => 'http://127.0.0.1:'.config('sandbox.proxy_port').'/']);
    $this->actingAs($user);

    $page = visit("/projects/{$project->id}")
        ->click('@tab-preview')
        ->assertPresent('@preview-frame');
    $page->script("document.querySelector('[data-test=preview-frame]').dataset.stale = 'yes'");

    // It falls asleep with the tab still visible and nobody using the page: only the workspace's own ping wakes it.
    $sandbox->forceFill(['suspended_at' => now()])->save();
    $provider->wakes = true;

    $page->wait(22);

    expect($page->script("document.querySelector('[data-test=preview-frame]').dataset.stale ?? null"))->toBeNull();
    expect($sandbox->fresh()->suspended_at)->toBeNull();
})->group('SBX-007');

test('a visible workspace nobody touches for 15 minutes lets its sandbox sleep, and using it wakes it', function () {
    app()->instance(SandboxProvider::class, $provider = new FakeSandboxProvider);
    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    $sandbox = Sandbox::factory()->for($project)->create(['external_id' => 'ctr']);
    $this->actingAs($user);

    $page = visit("/projects/{$project->id}")->assertPresent('@tab-preview');

    // 16 minutes pass with the window left open.
    $page->script('const realNow = Date.now; Date.now = () => realNow() + 16 * 60_000');
    $sandbox->forceFill(['suspended_at' => now()])->save();
    $provider->wakes = true;

    $page->wait(22);

    expect($sandbox->fresh()->suspended_at)->not->toBeNull();

    // It said it was left, so an E2B sandbox can pause within a minute (SBX-014).
    expect($provider->released)->toBe([['ctr', true]]);

    $page->click('@tab-preview')->wait(2);

    expect($sandbox->fresh()->suspended_at)->toBeNull();
})->group('SBX-007', 'SBX-014');

test('hiding the workspace says nobody is watching, so an E2B sandbox can pause soon', function () {
    app()->instance(SandboxProvider::class, $provider = new FakeSandboxProvider);
    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['provider' => 'e2b', 'external_id' => 'e2b-1']);
    $this->actingAs($user);

    $page = visit("/projects/{$project->id}")->assertPresent('@tab-preview');

    $page->script("Object.defineProperty(document, 'visibilityState', { value: 'hidden', configurable: true }); document.dispatchEvent(new Event('visibilitychange'))");
    $page->wait(1);

    expect($provider->released)->toBe([['e2b-1', true]]);
})->group('SBX-014');

test('a workspace away long enough for its sandbox to pause unloads the preview, and brings it back on return', function () {
    $provider = new class extends FakeSandboxProvider
    {
        public function previewUrl(string $id, int $port): ?string
        {
            return "http://127.0.0.1:{$port}/";
        }
    };
    app()->instance(SandboxProvider::class, $provider);
    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['external_id' => 'ctr', 'preview_url' => 'http://127.0.0.1:'.config('sandbox.proxy_port').'/']);
    $this->actingAs($user);

    $page = visit("/projects/{$project->id}")
        ->click('@tab-preview')
        ->assertPresent('@preview-frame');

    // The 50 seconds away pass at once.
    $page->script('const realTimeout = window.setTimeout; window.setTimeout = (run, ms, ...args) => realTimeout(run, ms === 50_000 ? 50 : ms, ...args)');
    $page->script("Object.defineProperty(document, 'visibilityState', { value: 'hidden', configurable: true }); document.dispatchEvent(new Event('visibilitychange'))");
    $page->wait(1);
    $page->assertMissing('@preview-frame');

    $page->script("Object.defineProperty(document, 'visibilityState', { value: 'visible', configurable: true }); document.dispatchEvent(new Event('visibilitychange'))");
    $page->assertPresent('@preview-frame');
})->group('SBX-014');

test('closing the workspace says nobody is watching', function () {
    app()->instance(SandboxProvider::class, $provider = new FakeSandboxProvider);
    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['provider' => 'e2b', 'external_id' => 'e2b-1']);
    $this->actingAs($user);

    $page = visit("/projects/{$project->id}")->assertPresent('@tab-preview');
    $page->script("window.dispatchEvent(new PageTransitionEvent('pagehide'))");
    $page->wait(1);

    expect($provider->released)->toBe([['e2b-1', true]]);
})->group('SBX-014');

test('a workspace away long enough for its sandbox to pause unloads its Shell, and brings the same session back', function () {
    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['preview_url' => null, 'shell_url' => 'about:blank']);
    $this->actingAs($user);

    $page = visit("/projects/{$project->id}")
        ->click('@tab-shell')
        ->assertPresent('@shell-frame');
    $src = $page->script("document.querySelector('[data-test=shell-frame]').getAttribute('src')");

    $page->script('const realTimeout = window.setTimeout; window.setTimeout = (run, ms, ...args) => realTimeout(run, ms === 50_000 ? 50 : ms, ...args)');
    $page->script("Object.defineProperty(document, 'visibilityState', { value: 'hidden', configurable: true }); document.dispatchEvent(new Event('visibilitychange'))");
    $page->wait(1);
    $page->assertMissing('@shell-frame');

    $page->script("Object.defineProperty(document, 'visibilityState', { value: 'visible', configurable: true }); document.dispatchEvent(new Event('visibilitychange'))");
    $page->assertPresent('@shell-frame');

    expect($page->script("document.querySelector('[data-test=shell-frame]').getAttribute('src')"))->toBe($src);
})->group('SBX-014');

test('moving the mouse over a workspace left untouched brings its preview back at once', function () {
    $provider = new class extends FakeSandboxProvider
    {
        public function previewUrl(string $id, int $port): ?string
        {
            return "http://127.0.0.1:{$port}/";
        }
    };
    app()->instance(SandboxProvider::class, $provider);
    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['external_id' => 'ctr', 'preview_url' => 'http://127.0.0.1:'.config('sandbox.proxy_port').'/']);
    $this->actingAs($user);

    $page = visit("/projects/{$project->id}")
        ->click('@tab-preview')
        ->assertPresent('@preview-frame');

    // Left untouched past the cutoff, then the 50 seconds away, all at once.
    $page->script('const realTimeout = window.setTimeout; window.setTimeout = (run, ms, ...args) => realTimeout(run, ms === 50_000 ? 50 : ms, ...args)');
    $page->script('const realNow = Date.now; Date.now = () => realNow() + 16 * 60_000');
    $page->wait(22);
    $page->assertMissing('@preview-frame');

    $page->script("document.dispatchEvent(new PointerEvent('pointermove'))");
    $page->assertPresent('@preview-frame');
})->group('SBX-014');
