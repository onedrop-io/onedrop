<?php

use App\Models\AgentConnection;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    AgentConnection::factory()->for(User::where('email', 'dev@example.com')->sole())->create();
});

test('the dev user opens settings from the user menu, moves between sections, and closes it', function () {
    $page = visit('/login')
        ->fill('email', 'dev@example.com')
        ->fill('password', 'password')
        ->press('@login-button')
        ->assertPathIs(orgPath())
        ->assertDontSee('Invite people')
        ->click('@sidebar-menu-button')
        ->assertSee('Invite people')
        ->click('@settings-link')
        ->assertPathIs('/settings/profile')
        ->assertVisible('@settings-modal')
        ->assertSeeIn('@settings-modal', 'Groups')
        ->assertSeeIn('@settings-modal', 'Users');

    $page->click('[data-test="settings-modal"] a:has-text("Invite people")')
        ->assertPathIs(orgPath('/invitations'))
        ->assertSeeIn('@settings-modal', 'Create invite link')
        ->keys('@settings-modal', 'Escape')
        ->assertPathIs(orgPath())
        ->assertMissing('@settings-modal')
        ->assertNoJavaScriptErrors();
})->group('SET-001');

test('a member does not see the admin section', function () {
    $this->actingAs(User::where('email', 'sam@example.com')->sole());

    visit('/settings/profile')
        ->assertVisible('@settings-modal')
        ->assertSeeIn('@settings-modal', 'Groups')
        ->assertDontSeeIn('@settings-modal', 'Users')
        ->assertNoJavaScriptErrors();
})->group('SET-001');

test('the dev user switches the theme from the user menu', function () {
    $this->actingAs(User::where('email', 'dev@example.com')->sole());

    $page = visit('/dashboard');

    expect($page->script('document.documentElement.classList.contains("dark")'))->toBeTrue();

    $page->click('@sidebar-menu-button')
        ->click('@theme-menu')
        ->click('Light');

    expect($page->script('document.documentElement.classList.contains("dark")'))->toBeFalse();

    // Choosing a theme closes the menu; wait until it's gone, or the click below lands mid-close and shuts it again.
    $page->assertMissing('@theme-menu')
        ->click('@sidebar-menu-button')
        ->assertSeeIn('@theme-menu', 'Light')
        ->assertNoJavaScriptErrors();
})->group('SET-001');
