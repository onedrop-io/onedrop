<?php

use App\Models\AgentConnection;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

test('an admin can impersonate a user from their page and stop', function () {
    AgentConnection::factory()->for(User::where('email', 'dev@example.com')->sole())->create();
    $sam = User::where('email', 'sam@example.com')->sole();

    visit('/login')
        ->fill('email', 'dev@example.com')
        ->fill('password', 'password')
        ->press('@login-button')
        ->assertPathIs(orgPath())
        ->navigate("/users/{$sam->id}")
        ->click('@impersonate')
        ->click('@impersonate-confirm')
        ->assertSeeIn('@impersonation-banner', "signed in as {$sam->name}")
        ->click('@stop-impersonating')
        ->assertPathIs("/users/{$sam->id}")
        ->assertSee('You are signed in as yourself again.')
        ->assertSeeIn('@user-details', 'Impersonations (1)')
        ->assertNoJavaScriptErrors();
})->group('USR-003');
