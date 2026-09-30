<?php

use App\Enums\AgentProvider;
use App\Enums\CredentialType;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A fake unsigned JWT carrying the given claims.
 *
 * @param  array<string, mixed>  $claims
 */
function fakeJwt(array $claims): string
{
    $encode = fn (array $part) => rtrim(strtr(base64_encode(json_encode($part)), '+/', '-_'), '=');

    return $encode(['alg' => 'none']).'.'.$encode($claims).'.signature';
}

beforeEach(function () {
    $this->freezeTime();
    $this->user = User::factory()->create();
    $this->device = ['device_auth_id' => 'dev-auth-1', 'user_code' => 'ABCD-1234', 'expires_at' => now()->addMinutes(15)->getTimestamp()];
});

test('starting ChatGPT sign-in returns a one-time code and remembers it', function () {
    Http::fake(['auth.openai.com/api/accounts/deviceauth/usercode' => Http::response([
        'device_auth_id' => 'dev-auth-1', 'user_code' => 'ABCD-1234', 'interval' => '5',
    ])]);

    $this->actingAs($this->user)
        ->postJson(route('chatgpt.store'))
        ->assertOk()
        ->assertExactJson(['user_code' => 'ABCD-1234', 'verification_url' => 'https://auth.openai.com/codex/device', 'interval' => 5]);

    expect(session('chatgpt.device'))->toMatchArray(['device_auth_id' => 'dev-auth-1', 'user_code' => 'ABCD-1234']);
    Http::assertSent(fn (Request $request) => $request['client_id'] === 'app_EMoamEEZ73f0CkXaXp7hrann');
})->group('AI-003');

test('starting ChatGPT sign-in reports when OpenAI fails', function () {
    Http::fake(['auth.openai.com/*' => Http::response([], 500)]);

    $this->actingAs($this->user)
        ->postJson(route('chatgpt.store'))
        ->assertUnprocessable()
        ->assertJsonPath('message', "Couldn't start ChatGPT sign-in. Try again, or paste an OpenAI API key instead.");
})->group('AI-003');

test('polling waits while the code is not approved yet', function () {
    Http::fake(['auth.openai.com/api/accounts/deviceauth/token' => Http::response([], 403)]);

    $this->actingAs($this->user)
        ->withSession(['chatgpt.device' => $this->device])
        ->postJson(route('chatgpt.poll'))
        ->assertOk()
        ->assertExactJson(['status' => 'pending']);

    expect($this->user->agentConnections()->count())->toBe(0);
})->group('AI-003');

test('an approved code connects Codex with the ChatGPT tokens', function () {
    $idToken = fakeJwt(['email' => 'dev@example.com', 'https://api.openai.com/auth' => ['chatgpt_account_id' => 'acct-9876']]);
    Http::fake([
        'auth.openai.com/api/accounts/deviceauth/token' => Http::response(['authorization_code' => 'auth-code', 'code_challenge' => 'c', 'code_verifier' => 'the-verifier']),
        'auth.openai.com/oauth/token' => Http::response([
            'access_token' => 'access-1',
            'refresh_token' => 'refresh-1',
            'id_token' => $idToken,
            'expires_in' => 864000,
        ]),
    ]);

    $this->actingAs($this->user)
        ->withSession(['chatgpt.device' => $this->device])
        ->postJson(route('chatgpt.poll'))
        ->assertOk()
        ->assertExactJson(['status' => 'connected'])
        ->assertSessionMissing('chatgpt.device');

    $connection = $this->user->agentConnections()->sole();
    expect($connection->provider)->toBe(AgentProvider::Codex)
        ->and($connection->credential_type)->toBe(CredentialType::ChatGpt)
        ->and($connection->hint)->toBe('9876')
        ->and($connection->is_default)->toBeTrue()
        ->and($connection->verified_at)->not->toBeNull()
        ->and($connection->chatGptTokens())->toMatchArray(['access' => 'access-1', 'refresh' => 'refresh-1', 'account_id' => 'acct-9876', 'email' => 'dev@example.com', 'id_token' => $idToken])
        ->and($connection->chatGptTokens()['expires'])->toBe(now()->addSeconds(864000)->getTimestamp());

    Http::assertSent(fn (Request $request) => $request->url() === 'https://auth.openai.com/oauth/token'
        && $request['grant_type'] === 'authorization_code'
        && $request['code'] === 'auth-code'
        && $request['code_verifier'] === 'the-verifier'
        && $request['redirect_uri'] === 'https://auth.openai.com/deviceauth/callback');
})->group('AI-003');

test('polling fails safely without a pending or with an expired sign-in', function (?array $device) {
    Http::fake();

    $this->actingAs($this->user)
        ->withSession($device ? ['chatgpt.device' => $device] : [])
        ->postJson(route('chatgpt.poll'))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'The ChatGPT sign-in code expired. Start again.');

    Http::assertNothingSent();
})->with([
    'no sign-in' => [null],
    'expired code' => [['device_auth_id' => 'dev-auth-1', 'user_code' => 'ABCD-1234', 'expires_at' => now()->subMinute()->getTimestamp()]],
])->group('AI-003');

test('a denied sign-in stores nothing and ends the attempt', function () {
    Http::fake(['auth.openai.com/*' => Http::response(['error' => 'access_denied'], 400)]);

    $this->actingAs($this->user)
        ->withSession(['chatgpt.device' => $this->device])
        ->postJson(route('chatgpt.poll'))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'ChatGPT sign-in failed. Try again.')
        ->assertSessionMissing('chatgpt.device');

    expect($this->user->agentConnections()->count())->toBe(0);
})->group('AI-003');

test('signing in with ChatGPT replaces a Codex API key', function () {
    $this->user->agentConnections()->create(['provider' => AgentProvider::Codex, 'credential_type' => CredentialType::ApiKey, 'credential' => 'sk-old', 'hint' => 'old', 'is_default' => true]);
    Http::fake([
        'auth.openai.com/api/accounts/deviceauth/token' => Http::response(['authorization_code' => 'auth-code', 'code_verifier' => 'v']),
        'auth.openai.com/oauth/token' => Http::response(['access_token' => fakeJwt(['chatgpt_account_id' => 'acct-5555']), 'refresh_token' => 'refresh-1']),
    ]);

    $this->actingAs($this->user)
        ->withSession(['chatgpt.device' => $this->device])
        ->postJson(route('chatgpt.poll'))
        ->assertOk();

    expect($this->user->agentConnections()->sole()->credential_type)->toBe(CredentialType::ChatGpt)
        ->and($this->user->agentConnections()->sole()->hint)->toBe('5555');
})->group('AI-003');
