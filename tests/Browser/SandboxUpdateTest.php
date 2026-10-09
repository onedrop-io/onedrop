<?php

use App\Enums\SandboxMovePhase;
use App\Jobs\UpdateSandbox;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\SandboxMove;
use App\Models\User;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use Illuminate\Support\Facades\Queue;

test('a project whose sandbox is being replaced shows it updating', function () {
    Queue::fake();
    $provider = new FakeSandboxProvider;
    $provider->outdated = ['old-ctr'];
    app()->instance(SandboxProvider::class, $provider);
    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    $sandbox = Sandbox::factory()->for($project)->create(['external_id' => 'old-ctr', 'preview_url' => 'http://127.0.0.1:9/']);
    // Updates wait until the project is unused, so opening it doesn't start one; this one is already under way.
    SandboxMove::query()->create(['project_id' => $project->id, 'sandbox_id' => $sandbox->id, 'reason' => 'update', 'phase' => SandboxMovePhase::Creating]);
    $this->actingAs($user);

    visit("/projects/{$project->id}")
        ->click('@tab-preview')
        ->assertSeeIn('@sandbox-status', 'Updating sandbox…')
        ->assertPresent('@preview-placeholder')
        ->assertMissing('@preview-frame')
        ->assertNoJavaScriptErrors();

    Queue::assertPushed(UpdateSandbox::class);
})->group('SBX-002');
