<?php

use App\Enums\BuildMode;
use App\Enums\MessageRole;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Agents\AgentRunner;
use App\Sandbox\Agents\FakeAgentRunner;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use Database\Seeders\DatabaseSeeder;

beforeEach(function () {
    app()->instance(SandboxProvider::class, new FakeSandboxProvider);
    app()->instance(AgentRunner::class, new FakeAgentRunner);
});

test('a first-time user picks Simple and sees only what they need, then switches to Advanced', function () {
    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create(['name' => 'Orders']);
    Sandbox::factory()->for($project)->create();
    $project->messages()->create(['role' => MessageRole::User, 'content' => 'Add an orders page']);
    $project->messages()->create(['role' => MessageRole::Activity, 'content' => 'Editing resources/js/pages/orders/index.tsx']);
    $project->messages()->create(['role' => MessageRole::Activity, 'content' => 'Creating resources/js/pages/orders/show.tsx']);
    $this->actingAs($user);

    visit(orgPath())
        ->assertSeeIn('@mode-chooser', 'How do you like to build?')
        ->assertVisible('@agent-pickers')
        ->click('@mode-simple')
        ->assertMissing('@mode-chooser')
        ->assertAttribute('@mode-switch-simple', 'aria-pressed', 'true')
        ->assertMissing('@agent-pickers')
        ->assertDontSee('Sales CRM')
        ->click('@way-template')
        ->assertSee('Sales CRM')
        ->click('@way-existing')
        ->assertDontSee('Sales CRM')
        ->assertMissing('@repository-input')
        ->assertMissing('#composer-prompt')
        ->assertNoJavaScriptErrors();

    expect($user->fresh()->build_mode)->toBe(BuildMode::Simple);

    $page = visit("/projects/{$project->id}")
        ->assertSee('Building the orders page')
        ->assertDontSee('resources/js/pages/orders')
        ->assertMissing('@git-actions')
        ->assertMissing('@agent-pickers')
        ->assertDontSee('Shell')
        ->assertMissing('@files-panel');

    // Not open to begin with, but never out of reach (PRJ-013).
    $page->click('@add-tab')
        ->click('@add-tab-shell')
        ->assertSee('Shell')
        ->click('@toggle-files')
        ->assertVisible('@files-panel');

    // Both steps are "Building the orders page", so it shows once.
    expect($page->script("document.querySelectorAll('[data-test=message-activity]').length"))->toBe(1);

    $page->click('@sidebar-menu-button')
        ->click('@mode-menu')
        ->click('@mode-menu-advanced')
        ->assertVisible('@git-actions')
        ->assertSee('Shell')
        ->assertSee('Editing resources/js/pages/orders/index.tsx')
        ->assertNoJavaScriptErrors();

    expect($user->fresh()->build_mode)->toBe(BuildMode::Advanced);
})->group('PRJ-013');

test('in Advanced mode, "Something that exists" offers their own repository above the free apps', function () {
    $this->seed(DatabaseSeeder::class);
    AgentConnection::factory()->for(User::where('email', 'dev@example.com')->sole())->create();

    visit('/login')
        ->fill('email', 'dev@example.com')
        ->fill('password', 'password')
        ->press('@login-button')
        ->assertMissing('@mode-chooser')
        ->assertAttribute('@mode-switch-advanced', 'aria-pressed', 'true')
        ->assertVisible('@agent-pickers')
        ->click('@way-existing')
        ->assertVisible('@repository-input')
        ->assertSee('Or install a free app')
        ->assertNoJavaScriptErrors();
})->group('PRJ-013', 'PRJ-009');

test('in Simple mode the chat shows when a project opens, even if it was hidden before', function () {
    $user = User::factory()->has(AgentConnection::factory())->create(['build_mode' => BuildMode::Simple]);
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create();
    $this->actingAs($user);

    $page = visit("/projects/{$project->id}");
    $page->script("localStorage.setItem('onedrop.chat-open', 'false')");

    visit("/projects/{$project->id}")
        ->assertVisible('#composer-content')
        ->assertAttribute('@toggle-chat', 'aria-label', 'Hide chat')
        ->click('@toggle-chat')
        ->assertMissing('#composer-content')
        ->assertNoJavaScriptErrors();
})->group('PRJ-013', 'LAYOUT-001');
