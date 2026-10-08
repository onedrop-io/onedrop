<?php

use App\Models\AgentConnection;
use App\Models\Group;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    AgentConnection::factory()->for(User::where('email', 'dev@example.com')->sole())->create();
});

test('the dev user finds a teammate\'s app on Apps, pins it, puts it in a group and features it', function () {
    $app = Project::factory()->for(User::where('email', 'sam@example.com')->sole())->create([
        'name' => 'Expense Tracker',
        'publish_status' => 'live',
        'publish_target' => 'domain',
        'publish_visibility' => 'private',
        'published_url' => 'https://expenses.example.com',
        'published_at' => now(),
    ]);
    Project::factory()->for(User::where('email', 'sam@example.com')->sole())->create([
        'name' => 'Lunch Orders',
        'publish_status' => 'live',
        'publish_target' => 'hosting',
        'publish_visibility' => 'public',
        'published_url' => 'https://lunch.example.com',
        'published_at' => now(),
    ]);
    $engineering = Group::where('name', 'Engineering')->sole();

    $page = visit('/login')
        ->fill('email', 'dev@example.com')
        ->fill('password', 'password')
        ->press('@login-button')
        ->assertPathIs(orgPath())
        ->click('@sidebar-apps')
        ->assertPathIs(orgPath('/apps'))
        ->assertSee('Expense Tracker')
        ->assertSee('Lunch Orders')
        ->assertSee('expenses.example.com');

    $tile = '[data-test="apps-all"] [data-test="app-tile"]:has-text("Expense Tracker")';

    $page->click("{$tile} [data-test=\"app-pin\"]")
        ->assertSee('Your apps');

    $page->click("{$tile} [data-test=\"app-menu\"]")
        ->click('@app-groups')
        ->click("@app-group-option-{$engineering->id}")
        ->click('@app-groups-save')
        ->click("@apps-group-{$engineering->id}")
        ->assertSee('Expense Tracker')
        ->assertDontSee('Lunch Orders');

    $page->click("@apps-group-{$engineering->id}")
        ->click('[role="group"] button:has-text("All")')
        ->click('[data-test="app-tile"]:has-text("Lunch Orders") [data-test="app-menu"]')
        ->click('@app-feature')
        ->assertSeeIn('[data-test="apps-all"] [data-test="app-tile"]:first-child', 'Lunch Orders')
        ->fill('@apps-search', 'expense')
        ->assertDontSee('Lunch Orders')
        ->assertNoJavaScriptErrors();

    expect($app->groups()->pluck('groups.id')->all())->toBe([$engineering->id])
        ->and(User::where('email', 'dev@example.com')->sole()->pinnedApps()->pluck('projects.id')->all())->toBe([$app->id]);
})->group('APPS-001', 'APPS-002', 'APPS-003');
