<?php

use App\Actions\Auth\DesktopSignIn;
use App\Models\AgentConnection;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(function () {
    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->verifier = str_repeat('v', 64);
    $this->signIn = [
        'redirect_uri' => 'http://127.0.0.1:53682/callback',
        'state' => 'xyz',
        'code_challenge' => DesktopSignIn::challengeFor($this->verifier),
        'code_challenge_method' => 'S256',
        'device' => "Jeff's MacBook Pro",
    ];
});

/**
 * Allow the sign-in in the browser and return the one-time code the app gets back.
 */
function allowDesktopSignIn(array $signIn, User $user): string
{
    $location = test()->actingAs($user)
        ->post(route('desktop.authorize.store'), [...$signIn, 'allow' => '1'])
        ->assertRedirect()
        ->headers->get('Location');

    expect($location)->toStartWith('http://127.0.0.1:53682/callback?');
    parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);
    expect($query['state'])->toBe('xyz');

    return $query['code'];
}

test('the browser asks the signed-in user to let the desktop app in', function () {
    $this->actingAs($this->user)
        ->get(route('desktop.authorize', $this->signIn))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('auth/desktop-authorize')
            ->where('device', "Jeff's MacBook Pro")
            ->where('email', $this->user->email)
            ->where('request.redirect_uri', 'http://127.0.0.1:53682/callback'));
})->group('DESK-001');

test('signing in to the desktop app asks guests to log in first', function () {
    $this->get(route('desktop.authorize', $this->signIn))->assertRedirect(route('login'));
})->group('DESK-001');

test('sign-in requests that do not come back to this computer are refused', function (array $changes) {
    $this->actingAs($this->user)
        ->get(route('desktop.authorize', [...$this->signIn, ...$changes]))
        ->assertStatus(400)
        ->assertSee('This sign-in link');

    $this->actingAs($this->user)
        ->post(route('desktop.authorize.store'), [...$this->signIn, ...$changes, 'allow' => '1'])
        ->assertStatus(400);
})->with([
    'another site' => [['redirect_uri' => 'https://evil.example/callback']],
    'a lookalike host' => [['redirect_uri' => 'http://127.0.0.1.evil.example:80/callback']],
    'another path' => [['redirect_uri' => 'http://127.0.0.1:53682/steal']],
    'no PKCE' => [['code_challenge' => null]],
    'plain PKCE' => [['code_challenge_method' => 'plain']],
])->group('DESK-001');

test('allowing the app gives it a one-time code it trades, with its verifier, for a token', function () {
    $code = allowDesktopSignIn($this->signIn, $this->user);

    $token = $this->postJson(route('api.desktop.token.store'), [
        'code' => $code,
        'code_verifier' => $this->verifier,
        'redirect_uri' => $this->signIn['redirect_uri'],
    ])->assertOk()->json('token');

    expect($token)->toMatch('/^\d+\|onedrop_/')
        ->and($this->user->tokens()->sole()->name)->toBe("Jeff's MacBook Pro");

    $this->withToken($token)->getJson(route('api.user.show'))
        ->assertOk()
        ->assertJsonPath('user.email', $this->user->email)
        ->assertJsonPath('user.ai_connected', true);

    // The code works once.
    $this->postJson(route('api.desktop.token.store'), [
        'code' => $code,
        'code_verifier' => $this->verifier,
        'redirect_uri' => $this->signIn['redirect_uri'],
    ])->assertStatus(422);
})->group('DESK-001');

test('a code is useless without the verifier of the app that asked for it', function (array $changes) {
    $code = allowDesktopSignIn($this->signIn, $this->user);

    $this->postJson(route('api.desktop.token.store'), [
        'code' => $code,
        'code_verifier' => $this->verifier,
        'redirect_uri' => $this->signIn['redirect_uri'],
        ...$changes,
    ])->assertStatus(422);

    expect(PersonalAccessToken::count())->toBe(0);
})->with([
    'another verifier' => [['code_verifier' => str_repeat('w', 64)]],
    'another address' => [['redirect_uri' => 'http://127.0.0.1:1234/callback']],
])->group('DESK-001');

test('codes expire', function () {
    $code = allowDesktopSignIn($this->signIn, $this->user);

    $this->travel(DesktopSignIn::CODE_SECONDS + 1)->seconds();

    $this->postJson(route('api.desktop.token.store'), [
        'code' => $code,
        'code_verifier' => $this->verifier,
        'redirect_uri' => $this->signIn['redirect_uri'],
    ])->assertStatus(422);
})->group('DESK-001');

test('cancelling goes back to the app without a code', function () {
    $this->actingAs($this->user)
        ->get(route('desktop.authorize', $this->signIn))
        ->assertInertia(fn ($page) => $page->where('request.state', 'xyz'));

    expect(PersonalAccessToken::count())->toBe(0);
})->group('DESK-001');

test('the API needs a token', function () {
    $this->getJson(route('api.user.show'))->assertUnauthorized();
    $this->withToken('onedrop_nope')->getJson(route('api.projects.index'))->assertUnauthorized();
})->group('DESK-001');

test('the API ignores the web session', function () {
    $this->actingAs($this->user)->getJson(route('api.user.show'))->assertUnauthorized();
})->group('DESK-001');

test('signing out of the desktop app revokes its token', function () {
    $token = $this->user->createToken('Laptop', [DesktopSignIn::ABILITY])->plainTextToken;

    $this->withToken($token)->deleteJson(route('api.desktop.token.destroy'))->assertNoContent();

    expect($this->user->tokens()->count())->toBe(0);
})->group('DESK-001');

test('settings list where the desktop app is signed in, and sign it out there', function () {
    $laptop = $this->user->createToken('Laptop');
    $other = User::factory()->create()->createToken('Not mine');

    $this->actingAs($this->user)
        ->get(route('desktop-devices.index'))
        ->assertInertia(fn ($page) => $page
            ->component('settings/desktop')
            ->has('devices', 1)
            ->where('devices.0.name', 'Laptop'));

    $this->actingAs($this->user)
        ->delete(route('desktop-devices.destroy', $other->accessToken->id))
        ->assertNotFound();

    $this->actingAs($this->user)
        ->delete(route('desktop-devices.destroy', $laptop->accessToken->id))
        ->assertRedirect();

    expect($this->user->tokens()->count())->toBe(0)
        ->and(PersonalAccessToken::count())->toBe(1);

    $this->withToken($laptop->plainTextToken)->getJson(route('api.user.show'))->assertUnauthorized();
})->group('DESK-001');
