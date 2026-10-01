<?php

use App\Models\AgentConnection;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

test('the dev user sees the OneDrop name and links after logging in', function () {
    $this->seed(DatabaseSeeder::class);
    AgentConnection::factory()->for(User::where('email', 'dev@example.com')->sole())->create();

    visit('/login')
        ->fill('email', 'dev@example.com')
        ->fill('password', 'password')
        ->press('@login-button')
        ->assertPathIs(orgPath())
        ->assertTitleContains('OneDrop')
        ->assertSee('OneDrop')
        ->assertDontSee('Laravel')
        ->click('@sidebar-menu-button')
        ->click('@help-menu')
        ->assertAttribute('a[href="https://github.com/onedrop-io/onedrop"]', 'target', '_blank')
        ->assertPresent('a[href="https://docs.onedrop.io/introduction"]')
        ->assertNoJavaScriptErrors();
})->group('BRAND-001');
