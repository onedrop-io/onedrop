<?php

use App\Jobs\UpdateSandbox;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use Illuminate\Support\Facades\Queue;

test('opening a project with an outdated sandbox shows it updating', function () {
    Queue::fake();
    $provider = new FakeSandboxProvider;
    $provider->outdated = ['old-ctr'];
    app()->instance(SandboxProvider::class, $provider);
    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['external_id' => 'old-ctr', 'preview_url' => null]);
    $this->actingAs($user);

    visit("/projects/{$project->id}")
        ->click('@tab-preview')
        ->assertSeeIn('@sandbox-status', 'Updating sandbox…')
        ->assertNoJavaScriptErrors();

    Queue::assertPushed(UpdateSandbox::class);
})->group('SBX-002');
