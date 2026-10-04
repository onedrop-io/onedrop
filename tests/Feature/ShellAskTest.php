<?php

use App\Jobs\CreateSandbox;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use Illuminate\Support\Facades\URL;

/**
 * The address `ask` in a sandbox's shell gets the project's AI from, without the callback host.
 */
function askPath(Sandbox $sandbox): string
{
    return URL::signedRoute('sandbox-ai.show', $sandbox, absolute: false);
}

test('new sandboxes get a signed address for ask', function () {
    config(['sandbox.callback_url' => 'http://host.docker.internal:8000/']);
    $project = Project::factory()->create();

    CreateSandbox::dispatchSync($project);

    /** @var FakeSandboxProvider $provider */
    $provider = app(SandboxProvider::class);

    expect(collect($provider->created)->sole()->env['ONEDROP_AI_URL'])
        ->toBe('http://host.docker.internal:8000'.askPath($project->sandbox));
})->group('SBX-012');

test('ask gets the project\'s AI with its credentials for one question, able to read but not change anything', function () {
    $user = User::factory()->has(AgentConnection::factory())->create();
    $sandbox = Sandbox::factory()->for(Project::factory()->for($user))->create(['last_active_at' => now()->subHour()]);

    $response = $this->getJson(askPath($sandbox))->assertOk();

    expect($response->json('harness'))->toBe('opencode')
        ->and($response->json('model'))->toStartWith('anthropic/')
        ->and($response->json('env.ANTHROPIC_API_KEY'))->toBe($user->agentConnections()->sole()->credential)
        ->and(json_decode($response->json('env.OPENCODE_CONFIG_CONTENT'), true)['permission'])->toBe(['edit' => 'deny', 'bash' => 'deny', 'webfetch' => 'deny'])
        ->and($sandbox->fresh()->last_active_at->greaterThan(now()->subMinute()))->toBeTrue();
})->group('SBX-012');

test('on a Claude subscription ask uses Claude Code\'s own sign-in, with no credentials handed over', function () {
    $user = User::factory()->create();
    AgentConnection::factory()->for($user)->claudeLogin()->create();
    $sandbox = Sandbox::factory()->for(Project::factory()->for($user))->create();

    $response = $this->getJson(askPath($sandbox))->assertOk();

    expect($response->json('harness'))->toBe('claude_code')
        ->and($response->json('model'))->not->toBeEmpty()
        ->and($response->json('env'))->toBe([]);
})->group('SBX-012');

test('ask says why when the project has no AI to ask', function () {
    $sandbox = Sandbox::factory()->for(Project::factory()->for(User::factory()))->create();

    $this->getJson(askPath($sandbox))
        ->assertUnprocessable()
        ->assertExactJson(['message' => 'No connected AI can be asked.']);
})->group('SBX-012');

test('only the signed address gets the AI, and only for its own sandbox', function () {
    $user = User::factory()->has(AgentConnection::factory())->create();
    $sandbox = Sandbox::factory()->for(Project::factory()->for($user))->create();
    $other = Sandbox::factory()->for(Project::factory()->for($user))->create();
    $signature = str(askPath($other))->after('?')->toString();

    $this->getJson(route('sandbox-ai.show', $sandbox, absolute: false))->assertForbidden();
    $this->getJson(route('sandbox-ai.show', $sandbox, absolute: false).'?'.$signature)->assertForbidden();
})->group('SBX-012');
