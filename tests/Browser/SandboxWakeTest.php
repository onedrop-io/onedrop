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

    $page->click('@tab-preview')->wait(2);

    expect($sandbox->fresh()->suspended_at)->toBeNull();
})->group('SBX-007');
