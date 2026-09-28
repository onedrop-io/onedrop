<?php

use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    AgentConnection::factory()->for(User::where('email', 'dev@example.com')->sole())->create();
});

test('describing an app creates a project and opens the chat + preview workspace', function () {
    $page = visit('/login')
        ->fill('email', 'dev@example.com')
        ->fill('password', 'password')
        ->press('@login-button')
        ->assertPathIs('/dashboard')
        ->assertSee('Dev, what are we working on today?')
        ->click('A time-off tracker for my team')
        ->assertValue('#composer-prompt', 'A time-off tracker for my team')
        ->press('@composer-send')
        ->assertSee('Your app will appear here in a moment.');

    $project = Project::sole();

    $page->assertPathIs("/projects/{$project->id}")
        ->assertSeeIn('@message-user', 'A time-off tracker for my team')
        ->assertSee('Planning app development')
        ->assertSee('using Claude')
        ->assertVisible('@preview-placeholder')
        ->assertSeeIn('[data-sidebar="sidebar"]', 'A Time-Off Tracker For My Team');

    $page->fill('#composer-content', 'add tags too')
        ->keys('#composer-content', 'Enter')
        ->assertSee('add tags too')
        ->assertNoJavaScriptErrors();

    expect($project->messages()->where('content', 'add tags too')->exists())->toBeTrue();
})->group('PRJ-001', 'PRJ-002');
