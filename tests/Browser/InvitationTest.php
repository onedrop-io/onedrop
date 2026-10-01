<?php

use App\Models\Invitation;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

test('a user creates an invite, copies the link, and the invitee signs up with it', function () {
    $this->seed(DatabaseSeeder::class);
    $dev = User::where('email', 'dev@example.com')->sole();
    $this->actingAs($dev);

    $page = visit(orgPath('/invitations'))
        ->fill('email', 'newbie@example.com')
        ->press('@create-invite')
        ->assertSee('Invite link created');

    $invitation = Invitation::sole();

    $page->click("@copy-invite-{$invitation->id}")
        ->assertSeeIn("@copy-invite-{$invitation->id}", 'Copied')
        ->assertNoJavaScriptErrors();

    auth()->logout();

    visit($invitation->url())
        ->assertPathIs('/register')
        ->assertSeeIn('@invitation-banner', 'Dev User invited you')
        ->assertValue('#email', 'newbie@example.com')
        ->fill('name', 'New Bie')
        ->fill('password', 'password')
        ->fill('password_confirmation', 'password')
        ->press('Create account')
        ->assertPathIs('/onboarding/ai')
        ->assertNoJavaScriptErrors();

    expect($invitation->fresh()->status())->toBe('accepted');
})->group('INV-001');
