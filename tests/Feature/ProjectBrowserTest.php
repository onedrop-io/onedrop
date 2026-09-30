<?php

use App\Enums\MessageRole;
use App\Enums\SandboxStatus;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Agents\HarnessRunner;
use App\Sandbox\ExecResult;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;

beforeEach(function () {
    $this->provider = new FakeSandboxProvider;
    app()->instance(SandboxProvider::class, $this->provider);

    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->project = Project::factory()->for($this->user)->create();
    Sandbox::factory()->for($this->project)->create(['external_id' => 'ctr-1', 'preview_url' => 'http://127.0.0.1:49152']);
    $this->token = str_repeat('9f', 32);
});

/** Take over "User should see a greeting" after its second step. */
function takeOver(): array
{
    return ['target' => 'tests/e2e/home.spec.ts:9', 'step' => 2, 'test' => 'User should see a greeting', 'step_title' => 'Fill "Ada"'];
}

test('takes over a test after one of its steps, on the preview\'s address', function () {
    $this->provider->execUsing = fn () => new ExecResult(0, json_encode(['token' => $this->token]));

    $this->actingAs($this->user)
        ->postJson(route('projects.browser.store', $this->project), takeOver())
        ->assertOk()
        ->assertExactJson(['url' => "http://127.0.0.1:49152/__onedrop/browser/?token={$this->token}"]);

    expect($this->provider->executed[0]['command'])->toBe(['node', '/opt/onedrop/browser.mjs', 'start', 'tests/e2e/home.spec.ts:9', '2']);
})->group('TEST-005');

test('on a server the browser opens through the gateway, like the preview', function () {
    config(['sandbox.gateway_domain' => 'apps.example.com']);
    $this->provider->execUsing = fn () => new ExecResult(0, json_encode(['token' => $this->token]));

    $this->actingAs($this->user)
        ->postJson(route('projects.browser.store', $this->project), takeOver())
        ->assertOk()
        ->assertJsonPath('url', route('projects.gateway.open', [$this->project, 'preview', 'path' => "/__onedrop/browser/?token={$this->token}"]));
})->group('TEST-005');

test('refuses targets that are not a test at a line, and odd steps', function (array $changes) {
    $this->actingAs($this->user)
        ->postJson(route('projects.browser.store', $this->project), [...takeOver(), ...$changes])
        ->assertUnprocessable();

    expect($this->provider->executed)->toBe([]);
})->with([
    'no line' => [['target' => 'tests/e2e/home.spec.ts']],
    'an option' => [['target' => '--ui']],
    'climbing out' => [['target' => 'tests/e2e/../../x.spec.ts:1']],
    'a negative step' => [['step' => -1]],
])->group('TEST-005');

test('reports whether the browser is starting, open, or failed', function (array $status, array $expected) {
    $this->provider->execUsing = fn () => new ExecResult(0, json_encode($status));

    $this->actingAs($this->user)
        ->getJson(route('projects.browser.show', $this->project))
        ->assertOk()
        ->assertJson($expected);

    expect($this->provider->executed[0]['command'])->toBe(['node', '/opt/onedrop/browser.mjs', 'status']);
})->with([
    'starting' => [['open' => false, 'starting' => true, 'error' => null], ['open' => false, 'starting' => true]],
    'open' => [['open' => true, 'starting' => false, 'error' => null, 'url' => 'http://127.0.0.1:8000/cart', 'title' => 'Cart'], ['open' => true, 'url' => 'http://127.0.0.1:8000/cart']],
    'failed' => [['open' => false, 'starting' => false, 'error' => 'The test ended before reaching that step'], ['open' => false, 'error' => 'The test ended before reaching that step']],
])->group('TEST-005');

test('while the browser is open the agent is told which page the user is on, and not once it closes', function () {
    $this->provider->execUsing = fn () => new ExecResult(0, json_encode(['token' => $this->token]));
    $this->actingAs($this->user)->postJson(route('projects.browser.store', $this->project), takeOver())->assertOk();

    $message = $this->project->messages()->create(['role' => MessageRole::User, 'content' => 'make this button red']);
    app(HarnessRunner::class)->start($this->project, $message);
    $prompt = collect($this->provider->executed)->firstWhere('command', ['node', '/opt/onedrop/forwarder.mjs'])['env']['APP_PROMPT'];

    expect($prompt)->toStartWith('make this button red')
        ->toContain('the test "User should see a greeting" up to its step "Fill "Ada""')
        ->toContain('/opt/onedrop/browser screenshot');

    $this->actingAs($this->user)->deleteJson(route('projects.browser.destroy', $this->project))->assertOk();
    $this->provider->executed = [];
    app(HarnessRunner::class)->start($this->project, $message);

    expect(collect($this->provider->executed)->firstWhere('command', ['node', '/opt/onedrop/forwarder.mjs'])['env']['APP_PROMPT'])->toBe('make this button red');
})->group('TEST-005');

test('only people who can change the project use the browser', function () {
    $viewer = User::factory()->has(AgentConnection::factory())->create();

    $this->actingAs($viewer)->getJson(route('projects.browser.show', $this->project))->assertForbidden();
    $this->actingAs($viewer)->postJson(route('projects.browser.store', $this->project), takeOver())->assertForbidden();
    $this->actingAs($viewer)->deleteJson(route('projects.browser.destroy', $this->project))->assertForbidden();

    expect($this->provider->executed)->toBe([]);
})->group('TEST-005');

test('a stopped sandbox says why the browser is unavailable', function () {
    $this->project->sandbox->update(['status' => SandboxStatus::Paused]);

    $this->actingAs($this->user)
        ->postJson(route('projects.browser.store', $this->project), takeOver())
        ->assertStatus(409);
})->group('TEST-005');
