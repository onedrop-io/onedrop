<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);

    config(['services.github.client_id' => 'github-id', 'services.github.client_secret' => 'github-secret']);
});

test('a new person signs up with GitHub, then connects Google from security settings', function () {
    config(['services.google.client_id' => 'google-id', 'services.google.client_secret' => 'google-secret']);

    Socialite::fake('github', SocialiteUser::fake([
        'id' => 'gh-42',
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
    ]));

    visit('/login')
        ->assertSeeIn('@social-login-github', 'Continue with GitHub')
        ->assertSeeIn('@social-login-google', 'Continue with Google')
        ->assertNoJavaScriptErrors();

    // The provider's sign-in page is external; pick up where it sends people back.
    visit('/login/github/callback?code=abc')
        ->assertPathIs('/onboarding/ai')
        ->assertNoJavaScriptErrors();

    $ada = User::where('email', 'ada@example.com')->sole();
    expect($ada->hasPassword())->toBeFalse();

    visit('/settings/security')
        ->assertPathIs('/settings/security')
        ->assertSee('Set a password')
        ->assertSeeIn('@social-account-github', 'ada@example.com')
        ->assertSeeIn('@social-account-google', 'Not connected')
        ->assertSee('Set a password to turn on two-factor authentication and passkeys.')
        ->assertNoJavaScriptErrors();

    Socialite::fake('google', SocialiteUser::fake([
        'id' => 'g-42',
        'email' => 'ada@gmail.test',
        'email_verified' => true,
    ]));

    visit('/login/google/callback?code=abc')
        ->assertPathIs('/settings/security')
        ->assertSee('Google connected.')
        ->assertSeeIn('@social-account-google', 'ada@gmail.test')
        ->assertNoJavaScriptErrors();

    expect($ada->socialAccounts()->count())->toBe(2);
})->group('AUTH-003');

test('a person sees an error when GitHub sign-in is cancelled', function () {
    visit('/login/github/callback?error=access_denied')
        ->assertPathIs('/login')
        ->assertSee('GitHub sign-in was cancelled. Try again.')
        ->assertNoJavaScriptErrors();
})->group('AUTH-003');
