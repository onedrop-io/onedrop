<?php

use App\Enums\SandboxStatus;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\ExecResult;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;

beforeEach(function () {
    $this->workspace = sys_get_temp_dir().'/onedrop-secrets-'.bin2hex(random_bytes(4));
    mkdir($this->workspace);
    file_put_contents($this->workspace.'/.env', "APP_NAME=Demo\nSTRIPE_SECRET_KEY=sk_live_123\n");
    $root = $this->workspace;
    register_shutdown_function(fn () => exec('rm -rf '.escapeshellarg($root)));

    $this->provider = fakeDatabaseSandbox($this->workspace);
    app()->instance(SandboxProvider::class, $this->provider);

    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->project = Project::factory()->for($this->user)->create();
    Sandbox::factory()->for($this->project)->create(['external_id' => 'ctr-1']);
});

test('it lists secret names without their values', function () {
    $this->actingAs($this->user)
        ->getJson(route('projects.secrets.index', $this->project))
        ->assertOk()
        ->assertExactJson(['secrets' => ['APP_NAME', 'STRIPE_SECRET_KEY'], 'organization' => []])
        ->assertDontSee('sk_live_123');
})->group('SECRET-001');

test('it reveals one value on request, uncached', function () {
    $this->actingAs($this->user)
        ->getJson(route('projects.secrets.show', [$this->project, 'name' => 'STRIPE_SECRET_KEY']))
        ->assertOk()
        ->assertExactJson(['name' => 'STRIPE_SECRET_KEY', 'value' => 'sk_live_123'])
        ->assertHeader('Cache-Control', 'no-store, private');
})->group('SECRET-001');

test('it adds, changes and deletes secrets in the app\'s env file and restarts the app', function () {
    $this->actingAs($this->user);

    $this->postJson(route('projects.secrets.store', $this->project), ['secrets' => [['name' => 'OPENAI_API_KEY', 'value' => 'sk proj "1"']]])
        ->assertOk()
        ->assertExactJson(['secrets' => ['APP_NAME', 'STRIPE_SECRET_KEY', 'OPENAI_API_KEY']]);

    $this->putJson(route('projects.secrets.update', $this->project), ['name' => 'STRIPE_SECRET_KEY', 'value' => 'sk_live_456'])
        ->assertOk();

    $this->deleteJson(route('projects.secrets.destroy', $this->project), ['name' => 'APP_NAME'])
        ->assertOk()
        ->assertExactJson(['secrets' => ['STRIPE_SECRET_KEY', 'OPENAI_API_KEY']]);

    expect(file_get_contents($this->workspace.'/.env'))->toBe("STRIPE_SECRET_KEY=sk_live_456\nOPENAI_API_KEY='sk proj \"1\"'\n")
        ->and(collect($this->provider->executed)->where('command', ['/opt/onedrop/restart']))->toHaveCount(3);
})->group('SECRET-001');

test('it says when a new secret\'s name is taken instead of overwriting it', function () {
    $this->actingAs($this->user)
        ->postJson(route('projects.secrets.store', $this->project), ['secrets' => [['name' => 'STRIPE_SECRET_KEY', 'value' => 'other']]])
        ->assertUnprocessable()
        ->assertJson(['message' => "There's already a secret called STRIPE_SECRET_KEY. Edit it instead."]);

    expect(file_get_contents($this->workspace.'/.env'))->toContain('sk_live_123');
})->group('SECRET-001');

test('it validates names and values', function (array $payload, string $field) {
    $this->actingAs($this->user)
        ->postJson(route('projects.secrets.store', $this->project), $payload)
        ->assertJsonValidationErrors($field);
})->with([
    'no secrets' => [['secrets' => []], 'secrets'],
    'bad name' => [['secrets' => [['name' => 'my-key', 'value' => 'x']]], 'secrets.0.name'],
    'starts with a digit' => [['secrets' => [['name' => '1KEY', 'value' => 'x']]], 'secrets.0.name'],
    'same name twice' => [['secrets' => [['name' => 'KEY', 'value' => 'a'], ['name' => 'KEY', 'value' => 'b']]], 'secrets.1.name'],
    'missing value' => [['secrets' => [['name' => 'KEY']]], 'secrets.0.value'],
    'value too long' => [['secrets' => [['name' => 'KEY', 'value' => str_repeat('a', 20001)]]], 'secrets.0.value'],
])->group('SECRET-001');

test('it adds pasted secrets together, replacing only the existing names the user confirmed, with one restart', function () {
    $this->actingAs($this->user)
        ->postJson(route('projects.secrets.store', $this->project), [
            'secrets' => [
                ['name' => 'STRIPE_SECRET_KEY', 'value' => 'sk_live_new'],
                ['name' => 'RESEND_API_KEY', 'value' => 're_1'],
                ['name' => 'PEM', 'value' => "-----BEGIN-----\nabc\n-----END-----"],
            ],
            'replace' => ['STRIPE_SECRET_KEY'],
        ])
        ->assertOk()
        ->assertExactJson(['secrets' => ['APP_NAME', 'STRIPE_SECRET_KEY', 'RESEND_API_KEY', 'PEM']]);

    expect(file_get_contents($this->workspace.'/.env'))->toBe("APP_NAME=Demo\nSTRIPE_SECRET_KEY=sk_live_new\nRESEND_API_KEY=re_1\nPEM=\"-----BEGIN-----\nabc\n-----END-----\"\n")
        ->and(collect($this->provider->executed)->where('command', ['/opt/onedrop/restart']))->toHaveCount(1);
})->group('SECRET-001');

test('it refuses a batch too big to send to the sandbox', function () {
    $secrets = array_map(fn (int $i) => ['name' => "KEY_{$i}", 'value' => str_repeat('a', 20000)], range(1, 7));

    $this->actingAs($this->user)
        ->postJson(route('projects.secrets.store', $this->project), ['secrets' => $secrets])
        ->assertUnprocessable()
        ->assertJson(['message' => "That's too much to save at once. Add the secrets in smaller batches."]);
})->group('SECRET-001');

test('only the project owner can see or change secrets', function () {
    $other = User::factory()->has(AgentConnection::factory())->create();
    $this->actingAs($other);

    $this->getJson(route('projects.secrets.index', $this->project))->assertForbidden();
    $this->getJson(route('projects.secrets.show', [$this->project, 'name' => 'STRIPE_SECRET_KEY']))->assertForbidden();
    $this->postJson(route('projects.secrets.store', $this->project), ['secrets' => [['name' => 'X', 'value' => '1']]])->assertForbidden();
    $this->putJson(route('projects.secrets.update', $this->project), ['name' => 'X', 'value' => '1'])->assertForbidden();
    $this->deleteJson(route('projects.secrets.destroy', $this->project), ['name' => 'APP_NAME'])->assertForbidden();
})->group('SECRET-001');

test('it explains when the sandbox is not running or has a missing or outdated secrets tool', function () {
    $this->actingAs($this->user);

    $provider = new FakeSandboxProvider;
    $provider->execUsing = fn () => new ExecResult(0, '');
    app()->instance(SandboxProvider::class, $provider);

    $this->getJson(route('projects.secrets.index', $this->project))
        ->assertStatus(502)
        ->assertJson(['message' => "This sandbox's Secrets tool is missing or out of date. Rebuild the sandbox image and recreate the sandbox."]);

    // A tool from an older image doesn't know how to add several secrets at once.
    $provider->execUsing = fn () => new ExecResult(0, json_encode(['ok' => false, 'error' => 'Unknown operation.']));

    $this->postJson(route('projects.secrets.store', $this->project), ['secrets' => [['name' => 'A', 'value' => '1']]])
        ->assertStatus(502)
        ->assertJson(['message' => "This sandbox's Secrets tool is missing or out of date. Rebuild the sandbox image and recreate the sandbox."]);

    $this->project->sandbox->update(['status' => SandboxStatus::Paused]);

    $this->getJson(route('projects.secrets.index', $this->project))->assertStatus(409);
})->group('SECRET-001');
