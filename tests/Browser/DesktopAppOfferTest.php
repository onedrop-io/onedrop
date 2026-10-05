<?php

use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

test('the dev user is offered the desktop app once they have a project, and closes it', function () {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'dev@example.com')->sole();
    AgentConnection::factory()->for($user)->create();

    $page = visit('/login')
        ->fill('email', 'dev@example.com')
        ->fill('password', 'password')
        ->press('@login-button')
        ->assertPathIs(orgPath())
        ->assertMissing('@desktop-app-offer');

    Project::factory()->for($user)->create();

    // For the browser's own system: the tests run on Macs and on Linux.
    $page->navigate(orgPath())
        ->assertSeeIn('@desktop-app-offer', 'Get the desktop app')
        ->assertSeeIn('@desktop-app-offer-download', PHP_OS_FAMILY === 'Darwin' ? 'Download for Mac' : 'Download for Linux')
        ->click('@desktop-app-offer-close')
        ->assertMissing('@desktop-app-offer')
        ->navigate(orgPath())
        ->assertSee('what are we working on today?')
        ->assertMissing('@desktop-app-offer')
        ->assertNoJavaScriptErrors();
})->group('DESK-005');

test('the desktop app page offers a download while it is signed in nowhere', function () {
    $this->seed(DatabaseSeeder::class);
    $this->actingAs(User::where('email', 'dev@example.com')->sole());

    visit('/settings/desktop')
        ->assertSee("The desktop app isn't signed in anywhere.")
        ->assertSeeIn('@desktop-devices-download', 'Download for')
        ->assertNoJavaScriptErrors();
})->group('DESK-005');
