<?php

use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

/**
 * @return array<string, string>
 */
function signUp(string $email): array
{
    return ['name' => 'Someone', 'email' => $email, 'password' => 'password', 'password_confirmation' => 'password'];
}

test('the first person to sign up on a new install becomes its admin', function () {
    $this->post(route('register.store'), signUp('first@example.com'))->assertRedirect();

    expect(User::sole()->is_admin)->toBeTrue();
})->group('INSTALL-001');

test('people who sign up later are not admins', function () {
    User::factory()->create();

    $this->post(route('register.store'), signUp('second@example.com'))->assertRedirect();

    expect(User::where('email', 'second@example.com')->sole()->is_admin)->toBeFalse();
})->group('INSTALL-001');

test('the first person to sign up with a provider becomes its admin', function () {
    config(['services.github.client_id' => 'github-id', 'services.github.client_secret' => 'github-secret']);
    Socialite::fake('github', SocialiteUser::fake(['id' => 'provider-123', 'name' => 'Ada', 'email' => 'ada@example.com']));

    $this->get(route('social.callback', ['provider' => 'github', 'code' => 'abc']))->assertRedirect();

    expect(User::sole()->is_admin)->toBeTrue();
})->group('INSTALL-001');

test('on a server install, the first account needs the setup link', function () {
    config(['auth.setup_token' => 'the-setup-token']);

    $this->get(route('register'))->assertInertia(fn ($page) => $page->where('setupRequired', true));
    $this->post(route('register.store'), signUp('stranger@example.com'))->assertSessionHasErrors('email');
    $this->get(route('register', ['setup' => 'a-guess']))->assertInertia(fn ($page) => $page->where('setupRequired', true));

    expect(User::count())->toBe(0);

    $this->get(route('register', ['setup' => 'the-setup-token']))->assertInertia(fn ($page) => $page->where('setupRequired', false));
    $this->post(route('register.store'), signUp('owner@example.com'))->assertRedirect();

    expect(User::sole())->email->toBe('owner@example.com')->is_admin->toBeTrue();
})->group('INSTALL-002');

test('once the first account exists, sign-up works without the setup link', function () {
    config(['auth.setup_token' => 'the-setup-token']);
    User::factory()->create();

    $this->get(route('register'))->assertInertia(fn ($page) => $page->where('setupRequired', false));
    $this->post(route('register.store'), signUp('colleague@example.com'))->assertRedirect();

    expect(User::where('email', 'colleague@example.com')->exists())->toBeTrue();
})->group('INSTALL-002');

test('on a server install, the first account cannot be made with a provider without the setup link', function () {
    config(['auth.setup_token' => 'the-setup-token', 'services.github.client_id' => 'github-id', 'services.github.client_secret' => 'github-secret']);
    Socialite::fake('github', SocialiteUser::fake(['id' => 'provider-123', 'name' => 'Ada', 'email' => 'ada@example.com']));

    $this->get(route('social.callback', ['provider' => 'github', 'code' => 'abc']))->assertSessionHasErrors('social');

    expect(User::count())->toBe(0);
})->group('INSTALL-002');

test('the installer can print the setup link until the first account exists', function () {
    config(['auth.setup_token' => 'the-setup-token']);

    $this->artisan('onedrop:setup-link')
        ->expectsOutputToContain('/register?setup=the-setup-token')
        ->assertSuccessful();

    User::factory()->create();

    $this->artisan('onedrop:setup-link')->doesntExpectOutputToContain('setup=')->assertFailed();
})->group('INSTALL-002');

test('without a setup token there is no setup link', function () {
    config(['auth.setup_token' => null]);

    $this->artisan('onedrop:setup-link')->assertFailed();
})->group('INSTALL-002');
