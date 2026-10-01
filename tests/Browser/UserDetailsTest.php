<?php

use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

test('an admin can open a user from the users list and see their details', function () {
    AgentConnection::factory()->for(User::where('email', 'dev@example.com')->sole())->create();
    $sam = User::where('email', 'sam@example.com')->sole();
    Project::factory()->for($sam)->create(['name' => 'Recipe box']);

    visit('/login')
        ->fill('email', 'dev@example.com')
        ->fill('password', 'password')
        ->press('@login-button')
        ->assertPathIs(orgPath())
        ->click('@sidebar-menu-button')
        ->click('@settings-link')
        ->click('[data-test="settings-modal"] a:has-text("Users")')
        ->click('[data-test="settings-modal"] a:has-text("'.$sam->name.'")')
        ->assertPathIs("/users/{$sam->id}")
        ->assertSeeIn('@user-details', 'sam@example.com')
        ->assertSeeIn('@user-details', 'Recipe box')
        ->assertSeeIn('@user-details', 'Engineering')
        ->assertPresent('@user-usage-chart')
        ->click('@sign-out-everywhere')
        ->click('@sign-out-everywhere-confirm')
        ->assertSee("{$sam->name} was signed out everywhere.")
        ->assertNoJavaScriptErrors();
})->group('USR-002');
