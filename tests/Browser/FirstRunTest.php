<?php

use App\Models\User;

test('the first person to sign up on a new install becomes the admin', function () {
    // As in the one-container install, which can't send email.
    config(['auth.verify_email' => false]);

    visit('/register')
        ->fill('name', 'First Person')
        ->fill('email', 'first@example.com')
        ->fill('password', 'Correct-Horse-42-battery')
        ->fill('password_confirmation', 'Correct-Horse-42-battery')
        ->press('Create account')
        ->assertPathIs('/onboarding/ai')
        ->assertNoJavaScriptErrors();

    expect(User::sole()->is_admin)->toBeTrue();
})->group('INSTALL-001');
