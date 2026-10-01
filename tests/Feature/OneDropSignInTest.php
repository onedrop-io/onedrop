<?php

use App\Models\AgentConnection;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\OneDropSignIn;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->project = Project::factory()->for($this->owner)->create(['name' => 'Address Book', 'published_url' => 'https://book.tail1234.ts.net']);
    Sandbox::factory()->for($this->project)->create(['preview_url' => 'http://127.0.0.1:49152']);
    $this->settings = app(OneDropSignIn::class)->enable($this->project, '/auth/onedrop/callback');
    $this->callback = 'http://127.0.0.1:49152/auth/onedrop/callback';
    $this->person = User::factory()->create(['name' => 'Sam', 'email' => 'sam@example.com']);
});

/**
 * @param  array<string, string>  $query
 */
function authorizeApp(User $user, array $query = []): TestResponse
{
    return test()->actingAs($user)->get(route('onedrop.authorize', [
        'response_type' => 'code',
        'client_id' => test()->settings['client_id'],
        'redirect_uri' => test()->callback,
        'state' => 'xyz',
        'scope' => 'openid profile email',
        ...$query,
    ]));
}

function codeFrom(TestResponse $response): string
{
    parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

    return $query['code'];
}

/**
 * @param  array<string, string>  $body
 */
function exchangeCode(string $code, array $body = []): TestResponse
{
    return test()->withHeader('Authorization', 'Basic '.base64_encode(test()->settings['client_id'].':'.test()->settings['client_secret']))
        ->post(route('onedrop.token'), ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => test()->callback, ...$body]);
}

test('a signed-in person gets through to the app with their name and email', function () {
    $response = authorizeApp($this->person);
    expect($response->headers->get('Location'))->toStartWith($this->callback.'?code=')->toEndWith('&state=xyz');

    $token = exchangeCode(codeFrom($response))
        ->assertOk()
        ->assertJsonPath('token_type', 'Bearer')
        ->json('access_token');

    $this->withToken($token)->get(route('onedrop.userinfo'))
        ->assertOk()
        ->assertExactJson(['sub' => (string) $this->person->id, 'name' => 'Sam', 'email' => 'sam@example.com', 'email_verified' => true, 'groups' => []]);
})->group('APPAUTH-002');

test('the settings point the browser at the app builder and the app server at the sandbox-reachable address', function () {
    config(['sandbox.callback_url' => 'http://host.docker.internal:8000']);
    $settings = app(OneDropSignIn::class)->enable($this->project, '/auth/onedrop/callback');

    expect($settings['authorize_url'])->toBe(route('onedrop.authorize'))
        ->and($settings['token_url'])->toBe('http://host.docker.internal:8000/oauth/token')
        ->and($settings['userinfo_url'])->toBe('http://host.docker.internal:8000/oauth/userinfo')
        ->and($settings['client_id'])->toBe($this->settings['client_id'])
        ->and($settings['client_secret'])->not->toBe($this->settings['client_secret']);
})->group('APPAUTH-002');

test('someone not signed in to OneDrop signs in first', function () {
    $this->get(route('onedrop.authorize', ['client_id' => $this->settings['client_id'], 'redirect_uri' => $this->callback, 'response_type' => 'code']))
        ->assertRedirect(route('login'));
})->group('APPAUTH-002');

test('codes work once, only with the right secret and callback', function () {
    $code = codeFrom(authorizeApp($this->person));

    exchangeCode($code, ['redirect_uri' => 'http://127.0.0.1:49152/elsewhere'])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

    $code = codeFrom(authorizeApp($this->person));
    exchangeCode($code)->assertOk();
    exchangeCode($code)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

    $this->withHeader('Authorization', 'Basic '.base64_encode($this->settings['client_id'].':wrong'))
        ->post(route('onedrop.token'), ['grant_type' => 'authorization_code', 'code' => codeFrom(authorizeApp($this->person))])
        ->assertUnauthorized()
        ->assertJsonPath('error', 'invalid_client');
})->group('APPAUTH-002');

test('PKCE challenges are checked', function () {
    $verifier = str_repeat('v', 50);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

    $code = codeFrom(authorizeApp($this->person, ['code_challenge' => $challenge, 'code_challenge_method' => 'S256']));
    exchangeCode($code, ['code_verifier' => 'wrong'])->assertStatus(400);

    $code = codeFrom(authorizeApp($this->person, ['code_challenge' => $challenge, 'code_challenge_method' => 'S256']));
    exchangeCode($code, ['code_verifier' => $verifier])->assertOk();

    expect(authorizeApp($this->person, ['code_challenge' => $challenge, 'code_challenge_method' => 'plain'])->headers->get('Location'))
        ->toContain('error=invalid_request');
})->group('APPAUTH-002');

test('only the app\'s own callback addresses are accepted, and nothing redirects elsewhere', function () {
    authorizeApp($this->person, ['redirect_uri' => 'https://evil.example.com/auth/onedrop/callback'])
        ->assertStatus(400)
        ->assertSee("This app can't use Sign in with OneDrop");

    $published = authorizeApp($this->person, ['redirect_uri' => 'https://book.tail1234.ts.net/auth/onedrop/callback']);
    expect($published->headers->get('Location'))->toStartWith('https://book.tail1234.ts.net/auth/onedrop/callback?code=');
})->group('APPAUTH-002');

test('when only some groups may sign in, others are refused with an explanation', function () {
    $group = Group::factory()->create();
    $this->project->update(['onedrop_group_ids' => [$group->id]]);

    authorizeApp($this->person)->assertForbidden()->assertSee("You can't sign in to Address Book")->assertSee($this->owner->name);

    // A token issued before access was narrowed stops working too.
    $group->members()->attach($this->person, ['role' => 'member']);
    $token = exchangeCode(codeFrom(authorizeApp($this->person)))->json('access_token');
    $this->withToken($token)->get(route('onedrop.userinfo'))->assertOk()->assertJsonPath('groups', [$group->name]);

    $group->members()->detach($this->person);
    $this->withToken($token)->get(route('onedrop.userinfo'))->assertUnauthorized();

    // The app's owner can always get in.
    expect(authorizeApp($this->owner)->headers->get('Location'))->toContain('code=');
})->group('APPAUTH-002');

test('turning it off stops sign-ins through OneDrop right away', function () {
    $token = exchangeCode(codeFrom(authorizeApp($this->person)))->json('access_token');

    app(OneDropSignIn::class)->disable($this->project);

    authorizeApp($this->person)->assertStatus(400);
    $this->withToken($token)->get(route('onedrop.userinfo'))->assertUnauthorized();
})->group('APPAUTH-002');

test('only people in the app\'s organization can sign in, and the app only sees their groups there', function () {
    config(['app.multi_tenant' => true]);
    $outsider = User::factory()->create();
    Organization::factory()->create()->addMember($outsider);

    authorizeApp($outsider)->assertForbidden()->assertSee("It only lets in people in {$this->project->organization->name} on OneDrop.");

    $here = Group::factory()->create(['name' => 'Engineering', 'organization_id' => $this->project->organization_id]);
    $elsewhere = Group::factory()->create(['name' => 'Elsewhere', 'organization_id' => Organization::factory()->create()->id]);
    $here->members()->attach($this->person, ['role' => 'member']);
    $elsewhere->members()->attach($this->person, ['role' => 'member']);

    $token = exchangeCode(codeFrom(authorizeApp($this->person)))->json('access_token');
    $this->withToken($token)->get(route('onedrop.userinfo'))->assertOk()->assertJsonPath('groups', ['Engineering']);
})->group('ORG-007');

test('the group picker only offers and accepts the organization\'s groups', function () {
    $here = Group::factory()->create(['name' => 'Engineering', 'organization_id' => $this->project->organization_id]);
    $elsewhere = Group::factory()->create(['organization_id' => Organization::factory()->create()->id]);
    AgentConnection::factory()->for($this->owner)->create();

    $this->actingAs($this->owner)
        ->putJson(route('projects.auth.onedrop', $this->project), ['group_ids' => [$elsewhere->id]])
        ->assertJsonValidationErrors('group_ids.0');

    $this->actingAs($this->owner)
        ->putJson(route('projects.auth.onedrop', $this->project), ['group_ids' => [$here->id]])
        ->assertOk()
        ->assertJsonPath('group_ids', [$here->id])
        ->assertJsonPath('groups', [['id' => $here->id, 'name' => 'Engineering']]);
})->group('ORG-007');
