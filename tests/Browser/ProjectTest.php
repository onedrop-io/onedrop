<?php

use App\Enums\AppTemplate;
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
        ->assertPathIs(orgPath())
        ->assertSee('Dev, what are we working on today?')
        ->assertAttribute('@way-new', 'aria-pressed', 'true')
        ->assertSee('From a template')
        ->assertSee('Something that exists')
        ->assertDontSee('Sales CRM')
        ->fill('#composer-prompt', 'A time-off tracker for my team')
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

test('describing something a template already does offers it, keeping what they typed', function () {
    visit('/login')
        ->fill('email', 'dev@example.com')
        ->fill('password', 'password')
        ->press('@login-button')
        ->fill('#composer-prompt', 'a game where cats jump over boxes')
        ->assertMissing('@already-built')
        ->fill('#composer-prompt', 'a crm for our sales team, with a lead source field')
        ->assertSeeIn('@already-built', 'This might already exist.')
        ->assertSeeIn('@already-built', 'Sales CRM')
        ->click('@already-built-option')
        ->assertSeeIn('@template-details-use', 'Use Sales CRM')
        ->assertValue('@template-details-prompt', AppTemplate::Crm->prompt()."\n\na crm for our sales team, with a lead source field")
        ->click('Cancel')
        ->assertMissing('@template-details')
        ->fill('#composer-prompt', '')
        ->fill('#composer-prompt', 'hire new engineers')
        ->assertSeeIn('@already-built', 'Hiring Pipeline')
        ->click('@already-built-dismiss')
        ->assertMissing('@already-built')
        ->press('@composer-send')
        ->assertSee('Your app will appear here in a moment.')
        ->assertNoJavaScriptErrors();

    expect(Project::sole()->prompt)->toBe('hire new engineers');
})->group('PRJ-001');

test('picking a template opens its details, where its description can be changed before using it', function () {
    $page = visit('/login')
        ->fill('email', 'dev@example.com')
        ->fill('password', 'password')
        ->press('@login-button')
        ->click('@way-template')
        ->assertSee('Hiring Pipeline')
        ->assertMissing('#composer-prompt')
        ->click('Sales CRM')
        ->assertSeeIn('@template-details', 'What the AI will build')
        ->assertValue('@template-details-prompt', AppTemplate::Crm->prompt())
        ->fill('@template-details-prompt', '')
        ->assertDisabled('@template-details-use')
        ->fill('@template-details-prompt', 'A sales CRM for our three regions')
        ->click('@template-details-use')
        ->assertSee('Your app will appear here in a moment.');

    $project = Project::sole();

    $page->assertPathIs("/projects/{$project->id}")
        ->assertSeeIn('[data-sidebar="sidebar"]', 'Sales CRM')
        ->assertNoJavaScriptErrors();

    expect($project->prompt)->toBe('A sales CRM for our three regions');
})->group('PRJ-004');
