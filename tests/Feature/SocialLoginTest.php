<?php

use App\Models\Invitation;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function () {
    foreach (['google', 'microsoft', 'github', 'gitlab'] as $provider) {
        config(["services.{$provider}.client_id" => "{$provider}-id", "services.{$provider}.client_secret" => "{$provider}-secret"]);
    }

    // Off unless a test sets it up, whatever the local .env has.
    config(['services.apple.client_id' => null]);
});

function configureApple(): void
{
    config(['services.apple' => [...config('services.apple'), 'client_id' => 'com.example.web', 'team_id' => 'TEAM123', 'key_id' => 'KEY123', 'private_key' => 'apple-key']]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function fakeIdentity(string $driver, array $attributes = []): void
{
    Socialite::fake($driver, SocialiteUser::fake([
        'id' => 'provider-123',
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        ...$attributes,
    ]));
}

test('log-in and sign-up pages list only configured providers', function () {
    config(['services.gitlab.client_id' => null]);

    $expected = [
        ['id' => 'google', 'label' => 'Google'],
        ['id' => 'microsoft', 'label' => 'Microsoft'],
        ['id' => 'github', 'label' => 'GitHub'],
    ];

    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('socialProviders', $expected));
    $this->get(route('register'))->assertInertia(fn (Assert $page) => $page->where('socialProviders', $expected));
})->group('AUTH-003');

test('no provider buttons show when none are configured', function () {
    config(['services.google.client_id' => null, 'services.microsoft.client_id' => null, 'services.github.client_id' => null, 'services.gitlab.client_id' => null]);

    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('socialProviders', []));
})->group('AUTH-003');

test('the OIDC provider shows once its issuer and credentials are set', function () {
    config(['services.openidconnect' => [
        'label' => 'Okta',
        'base_url' => 'https://id.example.com',
        'client_id' => 'oidc-id',
        'client_secret' => 'oidc-secret',
        'redirect' => '/login/oidc/callback',
    ]]);

    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('socialProviders.4', ['id' => 'oidc', 'label' => 'Okta']));
})->group('AUTH-003');

test('the Apple private key can be set as its contents, with \\n for newlines, or base64-encoded', function (Closure $encode) {
    $key = '';
    openssl_pkey_export(openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]), $key);
    $key = trim($key);

    $_ENV['APPLE_PRIVATE_KEY'] = $_SERVER['APPLE_PRIVATE_KEY'] = $encode($key);

    try {
        $loaded = (require config_path('services.php'))['apple']['private_key'];
    } finally {
        unset($_ENV['APPLE_PRIVATE_KEY'], $_SERVER['APPLE_PRIVATE_KEY']);
    }

    expect($loaded)->toBe($key);
})->with([
    'as is' => [fn (string $key) => $key],
    'escaped newlines' => [fn (string $key) => str_replace("\n", '\n', $key)],
    'base64' => [fn (string $key) => base64_encode($key)],
])->group('AUTH-003');

test('the Apple button shows once its Services ID, team, key ID and private key are set', function (?string $missing) {
    configureApple();

    if ($missing) {
        config(["services.apple.{$missing}" => '']);
    }

    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where(
        'socialProviders.1',
        $missing ? ['id' => 'microsoft', 'label' => 'Microsoft'] : ['id' => 'apple', 'label' => 'Apple'],
    ));
})->with([null, 'client_id', 'team_id', 'key_id', 'private_key'])->group('AUTH-003');

test('signing in with Apple asks for name and email, posted back to the callback', function () {
    configureApple();

    $location = $this->get(route('social.redirect', 'apple'))->assertRedirect()->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith('https://appleid.apple.com/auth/authorize')
        ->and($query)->toMatchArray([
            'client_id' => 'com.example.web',
            'redirect_uri' => route('social.callback', 'apple'),
            'scope' => 'name email',
            'response_mode' => 'form_post',
        ]);
})->group('AUTH-003');

test('Apple\'s posted sign-in is passed on to the callback without a session or CSRF token', function () {
    configureApple();

    $user = json_encode(['name' => ['firstName' => 'Ada', 'lastName' => 'Lovelace'], 'email' => 'ada@example.com']);

    $response = $this->post('/login/apple/callback', ['code' => 'abc', 'state' => 'xyz', 'user' => $user, 'extra' => 'dropped']);

    $response->assertStatus(303)
        ->assertRedirect(route('social.callback', ['provider' => 'apple', 'code' => 'abc', 'state' => 'xyz', 'user' => $user]));

    // A new session cookie here would replace the person's own, losing the sign-in's state.
    expect($response->headers->getCookies())->toBeEmpty();
})->group('AUTH-003');

test('signing up with Apple creates an account from the name it shares', function () {
    configureApple();
    fakeIdentity('apple', ['email' => 'ada@privaterelay.appleid.com', 'email_verified' => 'true']);

    $this->get(route('social.callback', ['provider' => 'apple', 'code' => 'abc']))->assertRedirect('/dashboard');

    $user = User::sole();

    expect($user->name)->toBe('Ada Lovelace')
        ->and($user->email)->toBe('ada@privaterelay.appleid.com')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->socialAccounts()->sole()->provider->value)->toBe('apple');
})->group('AUTH-003');

test('a cancelled Apple sign-in goes back to log in with an error', function () {
    configureApple();

    $this->post('/login/apple/callback', ['error' => 'user_cancelled_authorize'])
        ->assertRedirect(route('social.callback', ['provider' => 'apple', 'error' => 'user_cancelled_authorize']));

    $this->get(route('social.callback', ['provider' => 'apple', 'error' => 'user_cancelled_authorize']))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['social' => 'Apple sign-in was cancelled. Try again.']);
})->group('AUTH-003');

test('redirecting sends the person to the provider', function () {
    Socialite::fake('github');

    $this->get(route('social.redirect', 'github'))->assertRedirect('https://socialite.fake/github/authorize');
})->group('AUTH-003');

test('unconfigured or unknown providers are not found', function () {
    config(['services.github.client_id' => null]);

    $this->get(route('social.redirect', 'github'))->assertNotFound();
    $this->get('/login/myspace')->assertNotFound();
})->group('AUTH-003');

test('signing up with a provider creates a password-less, verified account', function () {
    fakeIdentity('github');

    $this->get(route('social.callback', ['provider' => 'github', 'code' => 'abc']))
        ->assertRedirect('/dashboard');

    $user = User::sole();
    expect(Auth::id())->toBe($user->id)
        ->and($user->name)->toBe('Ada Lovelace')
        ->and($user->email)->toBe('ada@example.com')
        ->and($user->hasPassword())->toBeFalse()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->socialAccounts()->sole()->provider_id)->toBe('provider-123');
})->group('AUTH-003');

test('emails are only trusted when the provider verified them', function (string $driver, array $raw, bool $verified) {
    configureApple();
    fakeIdentity($driver, $raw);

    $this->get(route('social.callback', ['provider' => $driver, 'code' => 'abc']));

    expect(User::sole()->email_verified_at !== null)->toBe($verified);
})->with([
    'google verified' => ['google', ['email_verified' => true], true],
    'google unverified' => ['google', ['email_verified' => false], false],
    'gitlab confirmed' => ['gitlab', ['confirmed_at' => '2024-01-01T00:00:00Z'], true],
    'gitlab unconfirmed' => ['gitlab', [], false],
    'microsoft' => ['microsoft', [], false],
    'apple verified' => ['apple', ['email_verified' => 'true'], true],
    'apple unverified' => ['apple', ['email_verified' => 'false'], false],
])->group('AUTH-003');

test('signing in again logs in the linked user', function () {
    $account = SocialAccount::factory()->create(['provider_id' => 'provider-123']);
    fakeIdentity('github', ['email' => 'changed@example.com']);

    $this->get(route('social.callback', ['provider' => 'github', 'code' => 'abc']))
        ->assertRedirect('/dashboard');

    expect(Auth::id())->toBe($account->user_id)
        ->and(User::count())->toBe(1);
})->group('AUTH-003');

test('a verified email links the provider to the existing account', function () {
    $user = User::factory()->create(['email' => 'Ada@Example.com']);
    fakeIdentity('github');

    $this->get(route('social.callback', ['provider' => 'github', 'code' => 'abc']))
        ->assertRedirect('/dashboard');

    expect(Auth::id())->toBe($user->id)
        ->and($user->socialAccounts()->sole()->provider->value)->toBe('github');
})->group('AUTH-003');

test('an unverified email does not link to an existing account', function (string $driver, bool $existingVerified) {
    User::factory()->state(['email' => 'ada@example.com', 'email_verified_at' => $existingVerified ? now() : null])->create();
    fakeIdentity($driver, ['email_verified' => false]);

    $this->get(route('social.callback', ['provider' => $driver, 'code' => 'abc']))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('social');

    $this->assertGuest();
    expect(SocialAccount::count())->toBe(0);
})->with([
    'microsoft' => ['microsoft', true],
    'google unverified' => ['google', true],
    'existing account unverified' => ['github', false],
])->group('AUTH-003');

test('providers cannot create accounts when registration is closed', function () {
    config(['fortify.features' => array_values(array_diff(config('fortify.features'), ['registration']))]);
    fakeIdentity('github');

    $this->get(route('social.callback', ['provider' => 'github', 'code' => 'abc']))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['social' => "There's no account for ada@example.com."]);

    expect(User::count())->toBe(0);
})->group('AUTH-003');

test('a provider without an email address is rejected', function () {
    fakeIdentity('github', ['email' => null]);

    $this->get(route('social.callback', ['provider' => 'github', 'code' => 'abc']))
        ->assertSessionHasErrors(['social' => "GitHub didn't share an email address with us."]);
})->group('AUTH-003');

test('users with two-factor authentication are asked for their code', function () {
    $user = User::factory()->withTwoFactor()->create();
    SocialAccount::factory()->for($user)->create(['provider_id' => 'provider-123']);
    fakeIdentity('github');

    $this->get(route('social.callback', ['provider' => 'github', 'code' => 'abc']))
        ->assertRedirect(route('two-factor.login'))
        ->assertSessionHas('login.id', $user->id);

    $this->assertGuest();
})->group('AUTH-003');

test('signing up with a provider accepts the invite being used', function () {
    $invitation = Invitation::issue(User::factory()->create());
    fakeIdentity('github');

    $this->withSession(['invitation_id' => $invitation->id])
        ->get(route('social.callback', ['provider' => 'github', 'code' => 'abc']));

    expect($invitation->fresh()->status())->toBe('accepted');
})->group('AUTH-003');

test('a cancelled sign-in goes back to log in with an error', function () {
    $this->get(route('social.callback', ['provider' => 'github', 'error' => 'access_denied']))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['social' => 'GitHub sign-in was cancelled. Try again.']);
})->group('AUTH-003');

test('a failed exchange goes back to log in with an error', function () {
    Socialite::fake('github', fn () => throw new RuntimeException('bad state'));

    $this->get(route('social.callback', ['provider' => 'github', 'code' => 'abc']))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['social' => 'GitHub sign-in failed. Try again.']);
})->group('AUTH-003');

test('signed-in users can connect a provider', function () {
    $user = User::factory()->create(['email' => 'someone-else@example.com']);
    fakeIdentity('google');

    $this->actingAs($user)
        ->get(route('social.callback', ['provider' => 'google', 'code' => 'abc']))
        ->assertRedirect(route('security.edit'));

    expect($user->socialAccounts()->sole())
        ->provider->value->toBe('google')
        ->email->toBe('ada@example.com');
})->group('AUTH-003');

test('a provider account connected to someone else cannot be connected again', function () {
    SocialAccount::factory()->create(['provider' => 'google', 'provider_id' => 'provider-123']);
    $user = User::factory()->create();
    fakeIdentity('google');

    $this->actingAs($user)
        ->get(route('social.callback', ['provider' => 'google', 'code' => 'abc']))
        ->assertRedirect(route('security.edit'))
        ->assertSessionHasErrors(['social' => 'That Google account is already connected to another user.']);

    expect($user->socialAccounts()->count())->toBe(0);
})->group('AUTH-003');

test('the security page lists providers and their connections', function () {
    $user = User::factory()->create();
    $account = SocialAccount::factory()->for($user)->create(['email' => 'ada@github.test']);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('hasPassword', true)
            ->where('socialAccounts', [
                ['provider' => 'google', 'label' => 'Google', 'account' => null, 'packages' => null],
                ['provider' => 'microsoft', 'label' => 'Microsoft', 'account' => null, 'packages' => null],
                ['provider' => 'github', 'label' => 'GitHub', 'account' => ['id' => $account->id, 'email' => 'ada@github.test'], 'packages' => 'reconnect'],
                ['provider' => 'gitlab', 'label' => 'GitLab', 'account' => null, 'packages' => null],
            ]));
})->group('AUTH-003');

test('users can disconnect a provider when they can still log in', function () {
    $user = User::factory()->create();
    $account = SocialAccount::factory()->for($user)->create();

    $this->actingAs($user)->delete(route('social-accounts.destroy', $account))->assertSessionHasNoErrors();

    expect($account->exists())->toBeFalse();
})->group('AUTH-003');

test('users cannot disconnect their only way to log in', function () {
    $user = User::factory()->withoutPassword()->create();
    $account = SocialAccount::factory()->for($user)->create();

    $this->actingAs($user)
        ->delete(route('social-accounts.destroy', $account))
        ->assertSessionHasErrors('social');

    expect($account->fresh())->not->toBeNull();
})->group('AUTH-003');

test('users cannot disconnect someone else\'s provider', function () {
    $account = SocialAccount::factory()->create();

    $this->actingAs(User::factory()->create())
        ->delete(route('social-accounts.destroy', $account))
        ->assertNotFound();
})->group('AUTH-003');

test('password-less users skip password confirmation on the security page', function () {
    $user = User::factory()->withoutPassword()->create();

    $this->actingAs($user)
        ->get(route('security.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('hasPassword', false));

    $this->actingAs(User::factory()->create())
        ->get(route('security.edit'))
        ->assertRedirect(route('password.confirm'));
})->group('AUTH-003');

test('password-less users can set a password without a current one', function () {
    $user = User::factory()->withoutPassword()->create();

    $this->actingAs($user)
        ->put(route('user-password.update'), ['password' => 'new-password-123', 'password_confirmation' => 'new-password-123'])
        ->assertSessionHasNoErrors();

    expect($user->fresh()->hasPassword())->toBeTrue();
})->group('AUTH-003');

test('password-less users can delete their account without a password', function () {
    $user = User::factory()->withoutPassword()->create();

    $this->actingAs($user)->delete(route('profile.destroy'))->assertRedirect('/');

    expect($user->fresh())->toBeNull();
})->group('AUTH-003');

test('password-less users cannot log in with an empty password', function () {
    $user = User::factory()->withoutPassword()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'anything']);

    $this->assertGuest();
})->group('AUTH-003');

test('signing up with a provider uses its profile picture as the avatar', function () {
    fakeIdentity('github', ['avatar' => 'https://avatars.githubusercontent.com/u/1']);

    $this->get(route('social.callback', ['provider' => 'github', 'code' => 'abc']));

    expect(User::sole()->avatar)->toBe('https://avatars.githubusercontent.com/u/1');

    $this->get(route('profile.edit'))->assertInertia(fn (Assert $page) => $page->where('auth.user.avatar', 'https://avatars.githubusercontent.com/u/1'));
})->group('AUTH-003');

test('signing in again fills in and refreshes the avatar from that provider', function () {
    $account = SocialAccount::factory()->create(['provider' => 'github', 'provider_id' => 'provider-123']);

    fakeIdentity('github', ['avatar' => 'https://avatars.example/old.png']);
    $this->get(route('social.callback', ['provider' => 'github', 'code' => 'abc']));
    expect($account->user->fresh()->avatar)->toBe('https://avatars.example/old.png');

    auth()->logout();
    fakeIdentity('github', ['avatar' => 'https://avatars.example/new.png']);
    $this->get(route('social.callback', ['provider' => 'github', 'code' => 'abc']));
    expect($account->user->fresh()->avatar)->toBe('https://avatars.example/new.png');
})->group('AUTH-003');

test('connecting another provider keeps the current avatar', function () {
    $user = User::factory()->create(['avatar' => 'https://avatars.example/github.png']);
    SocialAccount::factory()->for($user)->create(['provider' => 'github', 'avatar' => 'https://avatars.example/github.png']);
    fakeIdentity('google', ['avatar' => 'https://avatars.example/google.png', 'email_verified' => true]);

    $this->actingAs($user)->get(route('social.callback', ['provider' => 'google', 'code' => 'abc']));

    expect($user->fresh()->avatar)->toBe('https://avatars.example/github.png');
})->group('AUTH-003');

test('disconnecting a provider drops its avatar for another provider\'s', function () {
    $user = User::factory()->create(['avatar' => 'https://avatars.example/github.png']);
    $github = SocialAccount::factory()->for($user)->create(['provider' => 'github', 'avatar' => 'https://avatars.example/github.png']);
    SocialAccount::factory()->for($user)->create(['provider' => 'google', 'provider_id' => 'g-1', 'avatar' => 'https://avatars.example/google.png']);

    $this->actingAs($user)->delete(route('social-accounts.destroy', $github));
    expect($user->fresh()->avatar)->toBe('https://avatars.example/google.png');
})->group('AUTH-003');

/**
 * Load config/services.php with the given environment variables set.
 *
 * @param  array<string, string>  $variables
 * @return array<string, mixed>
 */
function servicesConfigWithEnv(array $variables): array
{
    $previous = [];

    foreach ($variables as $name => $value) {
        $previous[$name] = getenv($name);
        putenv("{$name}={$value}");
        $_ENV[$name] = $_SERVER[$name] = $value;
    }

    try {
        return require config_path('services.php');
    } finally {
        foreach ($previous as $name => $value) {
            $value === false ? putenv($name) : putenv("{$name}={$value}");
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }
}

test('log in with GitHub falls back to the GitHub App credentials', function () {
    $appOnly = servicesConfigWithEnv([
        'GITHUB_CLIENT_ID' => '', 'GITHUB_CLIENT_SECRET' => '',
        'GITHUB_APP_CLIENT_ID' => 'Iv23.app', 'GITHUB_APP_CLIENT_SECRET' => 'app-secret',
    ]);

    expect($appOnly['github'])->toMatchArray(['client_id' => 'Iv23.app', 'client_secret' => 'app-secret']);

    $override = servicesConfigWithEnv([
        'GITHUB_CLIENT_ID' => 'Ov23.oauth', 'GITHUB_CLIENT_SECRET' => 'oauth-secret',
        'GITHUB_APP_CLIENT_ID' => 'Iv23.app', 'GITHUB_APP_CLIENT_SECRET' => 'app-secret',
    ]);

    expect($override['github'])->toMatchArray(['client_id' => 'Ov23.oauth', 'client_secret' => 'oauth-secret']);
})->group('AUTH-003');

test('signing in with a provider records it as the last sign-in method', function () {
    $account = SocialAccount::factory()->create(['provider_id' => 'provider-123']);
    fakeIdentity('github');

    $this->get(route('social.callback', ['provider' => 'github', 'code' => 'abc']));

    expect(Auth::id())->toBe($account->user_id)
        ->and($account->user->fresh()->last_login_method)->toBe('github');
})->group('USR-002');
