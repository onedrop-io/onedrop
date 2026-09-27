<?php

use App\Enums\PublishStatus;
use App\Enums\PublishVisibility;
use App\Enums\SandboxStatus;
use App\Models\Project;
use App\Models\Sandbox;
use App\Sandbox\Publishing\FakePublisher;
use App\Sandbox\Publishing\Publisher;

test('recreating a sandbox replaces it and clears the agent session', function () {
    $project = Project::factory()->create(['agent_session_id' => 'ses_old']);
    Sandbox::factory()->for($project)->create(['external_id' => 'old-ctr', 'status' => SandboxStatus::Failed]);

    $this->artisan('sandbox:recreate', ['project' => $project->id])->assertSuccessful();

    $sandbox = $project->sandbox()->first();
    expect($sandbox->status)->toBe(SandboxStatus::Running)
        ->and($sandbox->external_id)->not->toBe('old-ctr')
        ->and($project->fresh()->agent_session_id)->toBeNull();
})->group('SBX-001');

test('recreating a published project publishes it again', function () {
    app()->instance(Publisher::class, new FakePublisher);
    $project = Project::factory()->create([
        'publish_status' => PublishStatus::Live,
        'publish_visibility' => PublishVisibility::Public,
        'published_url' => 'https://old.ts.net',
    ]);
    Sandbox::factory()->for($project)->create(['external_id' => 'old-ctr']);

    $this->artisan('sandbox:recreate', ['project' => $project->id])->assertSuccessful();

    expect($project->fresh()->publish_status)->toBe(PublishStatus::Live)
        ->and($project->fresh()->publish_visibility)->toBe(PublishVisibility::Public);
})->group('PUB-001');
