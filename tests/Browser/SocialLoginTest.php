<?php

use App\Models\AgentConnection;
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

test('the dev user reconnects GitHub to let their projects download private packages', function () {
    config(['services.github_app.client_id' => 'github-app-id']);
    $dev = User::where('email', 'dev@example.com')->sole();
    AgentConnection::factory()->for($dev)->create();
    // Signed in with GitHub only, so Settings → Security doesn't ask to confirm a password (sessions don't last here).
    $dev->forceFill(['password' => null])->save();
    $dev->socialAccounts()->create(['provider' => 'github', 'provider_id' => 'gh-dev', 'email' => 'dev@github.test']);
    $this->actingAs($dev);

    visit('/settings/security')
        ->assertSeeIn('@social-account-github', 'Reconnect to let your projects download your private GitHub packages')
        ->assertNoJavaScriptErrors();

    Socialite::fake('github', SocialiteUser::fake([
        'id' => 'gh-dev',
        'email' => 'dev@github.test',
        'token' => 'gho_dev',
        'approvedScopes' => ['read:packages', 'user:email'],
    ]));

    visit('/login/github/callback?code=abc')
        ->assertPathIs('/settings/security')
        ->assertSee('GitHub connected.')
        ->assertSeeIn('@social-account-github', 'Your projects can download your private GitHub packages')
        ->assertNoJavaScriptErrors();

    expect($dev->socialAccounts()->sole()->canReadPackages())->toBeTrue();
})->group('GIT-016');
