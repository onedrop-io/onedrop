<?php

use App\Enums\PublishStatus;
use App\Enums\PublishVisibility;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;

test('the tools tab lists sections and shows publishing status', function () {
    app()->instance(SandboxProvider::class, new FakeSandboxProvider);
    $user = User::factory()->has(AgentConnection::factory())->create(['name' => 'Jeff']);
    $project = Project::factory()->for($user)->create([
        'publish_status' => PublishStatus::Live,
        'publish_visibility' => PublishVisibility::Public,
        'published_url' => 'https://timer-1.example.ts.net',
        'published_at' => now(),
        'published_by' => $user->id,
    ]);
    Sandbox::factory()->for($project)->create(['preview_url' => null]);
    $this->actingAs($user);

    visit("/projects/{$project->id}")
        ->assertVisible('@tab-tools')
        ->assertVisible('@tab-preview')
        ->assertMissing('[data-test="tab-tools"] + button')
        ->assertMissing('[data-test="tab-preview"] + button')
        ->click('@tab-tools')
        ->assertSeeIn('@tool-publishing', 'Publishing')
        ->assertSeeIn('@tool-skills', 'Agent Skills')
        ->assertSeeIn('@tools-publish-status', 'Live')
        ->assertSee('timer-1.example.ts.net')
        ->click('@tool-security')
        ->assertVisible('@tool-page-security')
        ->assertSeeIn('@tool-coming-soon', 'Coming soon')
        ->click('@tab-preview')
        ->assertMissing('@tools-panel')
        ->assertNoJavaScriptErrors();
})->group('TOOL-001');

test('on a phone the tools menu and a section take turns at full width', function () {
    app()->instance(SandboxProvider::class, new FakeSandboxProvider);
    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['preview_url' => null]);
    $this->actingAs($user);

    visit("/projects/{$project->id}")
        ->on()->mobile()
        ->click('@mobile-bar-tools')
        ->assertVisible('@tool-security')
        ->assertMissing('@tools-publish-status')
        ->click('@tool-security')
        ->assertVisible('@tool-page-security')
        ->assertMissing('@tool-publishing')
        ->click('@tools-back')
        ->assertVisible('@tool-publishing')
        ->assertMissing('@tool-page-security')
        ->assertNoJavaScriptErrors();
})->group('TOOL-001');
