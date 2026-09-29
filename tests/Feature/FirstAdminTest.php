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
