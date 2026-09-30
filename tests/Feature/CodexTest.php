<?php

use App\Enums\AgentHarness;
use App\Enums\AgentProvider;
use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Models\AgentConnection;
use App\Models\Message;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Agents\HarnessRunner;
use App\Sandbox\Agents\ModelCatalog;
use App\Sandbox\ExecResult;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->provider = new class extends FakeSandboxProvider
    {
        /** @var list<array<string, string>> */
        public array $envs = [];

        public function exec(string $id, array $command, array $env = [], bool $detach = false): ExecResult
        {
            $this->envs[] = $env;

            return parent::exec($id, $command, $env, $detach);
        }
    };
    app()->instance(SandboxProvider::class, $this->provider);

    $this->user = User::factory()->create();
    $this->codex = AgentConnection::factory()->for($this->user)->chatGpt(['id_token' => 'chatgpt-id-token'])->create();
    $this->catalog = app(ModelCatalog::class);
});

/**
 * Post Codex events from the project's sandbox, as the forwarder does.
 *
 * @param  list<array<string, mixed>>  $events
 */
function sendCodexEvents(Project $project, array $events): void
{
    $sandbox = $project->sandbox ?? Sandbox::factory()->for($project)->create();

    test()->withToken($sandbox->issueEventsToken())
        ->postJson(route('sandbox-events.store', $sandbox), ['agent' => 'codex', 'events' => $events])
        ->assertOk();
}

/**
 * A Codex project with a running sandbox and a message waiting for the agent.
 *
 * @param  array<string, mixed>  $attributes
 * @return array{Project, Message}
 */
function codexProject(User $user, array $attributes = []): array
{
    $project = Project::factory()->for($user)->create([
        'status' => ProjectStatus::Working,
        'agent_harness' => AgentHarness::Codex,
        'agent_provider' => AgentProvider::Codex,
        'agent_model' => 'gpt-5.5',
        ...$attributes,
    ]);
    Sandbox::factory()->for($project)->create(['external_id' => 'ctr-1']);

    return [$project, $project->messages()->create(['role' => MessageRole::User, 'content' => 'build a timer'])];
}

test('a Codex connection offers both OpenCode and the Codex agent', function () {
    expect($this->catalog->harnesses($this->user))->toBe([AgentHarness::OpenCode, AgentHarness::Codex])
        ->and($this->catalog->usableProviders($this->user, AgentHarness::Codex))->toBe([AgentProvider::Codex]);

    $this->actingAs($this->user)
        ->getJson(route('agent-models.index'))
        ->assertOk()
        ->assertJsonPath('harnesses', [
            ['id' => 'opencode', 'label' => 'OpenCode', 'providers' => ['codex']],
            ['id' => 'codex', 'label' => 'Codex', 'providers' => ['codex']],
        ]);
})->group('AGT-009');

test('the Codex agent is only offered once Codex is connected', function () {
    $this->codex->delete();
    AgentConnection::factory()->for($this->user)->provider(AgentProvider::OpenRouter)->create();

    expect($this->catalog->harnesses($this->user))->toBe([AgentHarness::OpenCode]);
})->group('AGT-009');

test('the Codex agent only runs OpenAI models', function () {
    AgentConnection::factory()->for($this->user)->provider(AgentProvider::OpenRouter)->create(['is_default' => false]);
    $project = Project::factory()->for($this->user)->create();

    $this->actingAs($this->user)
        ->patch(route('projects.agent.update', $project), ['agent_harness' => 'codex', 'agent_provider' => 'openrouter', 'agent_model' => 'moonshotai/kimi-k3'])
        ->assertSessionHasErrors(['agent_provider' => 'Codex only runs OpenAI models.']);

    $this->actingAs($this->user)
        ->patch(route('projects.agent.update', $project), ['agent_harness' => 'codex', 'agent_provider' => 'codex', 'agent_model' => 'gpt-5.5'])
        ->assertSessionHasNoErrors();

    expect($project->fresh()->agent_harness)->toBe(AgentHarness::Codex);
})->group('AGT-009');

test('Codex runs on the ChatGPT sign-in without its refresh token, with the chosen model and effort', function () {
    $this->freezeTime();
    [$project, $message] = codexProject($this->user, ['agent_variant' => 'high', 'agent_session_id' => 'thread-1']);

    app(HarnessRunner::class)->start($project, $message);

    $env = $this->provider->envs[0];
    expect($this->provider->executed[0]['command'])->toBe(['node', '/opt/zap/forwarder.mjs'])
        ->and($env)->toMatchArray([
            'APP_AGENT' => 'codex',
            'APP_PROMPT' => 'build a timer',
            'APP_MODEL' => 'gpt-5.5',
            'APP_VARIANT' => 'high',
            'APP_SESSION_ID' => 'thread-1',
            'APP_FILES' => '[]',
        ])
        ->and($env)->not->toHaveKeys(['OPENCODE_AUTH_CONTENT', 'OPENAI_API_KEY'])
        ->and(json_decode($env['CODEX_AUTH_CONTENT'], true))->toBe([
            'OPENAI_API_KEY' => null,
            'tokens' => [
                'id_token' => 'chatgpt-id-token',
                'access_token' => 'chatgpt-access-token',
                'refresh_token' => '',
                'account_id' => 'acct-1234',
            ],
            'last_refresh' => now()->toIso8601ZuluString(),
        ]);
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'auth.openai.com'));
})->group('AGT-009');

test('Codex runs on an OpenAI API key', function () {
    $this->codex->delete();
    AgentConnection::factory()->for($this->user)->provider(AgentProvider::Codex)->create(['credential' => 'sk-openai-key']);
    [$project, $message] = codexProject($this->user);

    app(HarnessRunner::class)->start($project, $message);

    expect(json_decode($this->provider->envs[0]['CODEX_AUTH_CONTENT'], true))->toBe(['OPENAI_API_KEY' => 'sk-openai-key']);
})->group('AGT-009');

test('a ChatGPT sign-in saved without an ID token is refreshed to get one before Codex runs', function () {
    Http::fake(['auth.openai.com/oauth/token' => Http::response(['access_token' => 'access-2', 'refresh_token' => 'refresh-2', 'id_token' => 'id-2', 'expires_in' => 864000])]);
    $this->codex->delete();
    $connection = AgentConnection::factory()->for($this->user)->chatGpt()->create();
    [$project, $message] = codexProject($this->user);

    app(HarnessRunner::class)->start($project, $message);

    expect(json_decode($this->provider->envs[0]['CODEX_AUTH_CONTENT'], true)['tokens'])->toMatchArray(['id_token' => 'id-2', 'access_token' => 'access-2'])
        ->and($connection->fresh()->chatGptTokens())->toMatchArray(['id_token' => 'id-2', 'refresh' => 'refresh-2']);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://auth.openai.com/oauth/token' && $request['grant_type'] === 'refresh_token' && $request['scope'] === 'openid profile email');
})->group('AGT-009');

test('a Codex project falls back to OpenCode once Codex is disconnected', function () {
    AgentConnection::factory()->for($this->user)->provider(AgentProvider::OpenRouter)->create();
    [$project, $message] = codexProject($this->user);
    $this->codex->delete();

    app(HarnessRunner::class)->start($project, $message);

    expect($this->provider->envs[0]['APP_AGENT'])->toBe('opencode');
})->group('AGT-009');

test('a Codex run becomes chat messages in order and remembers its thread', function () {
    [$project] = codexProject($this->user);

    sendCodexEvents($project, [
        ['type' => 'zap.start'],
        ['type' => 'thread.started', 'thread_id' => 'thread-1'],
        ['type' => 'item.completed', 'item' => ['type' => 'reasoning']],
        ['type' => 'item.completed', 'item' => ['type' => 'agent_message', 'text' => "I'll build a timer."]],
        ['type' => 'item.started', 'item' => ['type' => 'command_execution', 'command' => "/bin/bash -lc 'npm install'", 'status' => 'in_progress']],
        ['type' => 'item.completed', 'item' => ['type' => 'command_execution', 'command' => "/bin/bash -lc 'npm install'", 'status' => 'completed']],
        ['type' => 'item.started', 'item' => ['type' => 'command_execution', 'command' => '/bin/bash -lc "printf \'hi\' > hello.txt"', 'status' => 'in_progress']],
        ['type' => 'item.completed', 'item' => ['type' => 'file_change', 'changes' => [['path' => '/workspace/src/App.tsx', 'kind' => 'add'], ['path' => '/workspace/src/main.tsx', 'kind' => 'update']]]],
        ['type' => 'item.completed', 'item' => ['type' => 'error', 'message' => 'Model metadata not found']],
        ['type' => 'item.completed', 'item' => ['type' => 'agent_message', 'text' => 'Done! Press Start to begin timing.']],
        ['type' => 'turn.completed', 'usage' => ['input_tokens' => 1000, 'cached_input_tokens' => 800, 'output_tokens' => 50], 'model' => 'gpt-5.5'],
        ['type' => 'zap.exit', 'code' => 0, 'stderr' => '', 'reported' => false],
    ]);

    $project->refresh();

    expect($project->messages->map(fn ($m) => [$m->role, $m->content])->all())->toBe([
        [MessageRole::User, 'build a timer'],
        [MessageRole::Activity, 'Thinking'],
        [MessageRole::Assistant, "I'll build a timer."],
        [MessageRole::Activity, 'Running `npm install`'],
        [MessageRole::Activity, "Running `printf 'hi' > hello.txt`"],
        [MessageRole::Activity, 'Creating src/App.tsx'],
        [MessageRole::Activity, 'Editing src/main.tsx'],
        [MessageRole::Assistant, 'Done! Press Start to begin timing.'],
    ])
        ->and($project->agent_session_id)->toBe('thread-1')
        ->and($project->status)->toBe(ProjectStatus::Idle)
        ->and($this->user->agentUsages()->sole())
        ->harness->toBe(AgentHarness::Codex)
        ->provider->toBe(AgentProvider::Codex)
        ->model->toBe('gpt-5.5')
        ->input_tokens->toBe(200)
        ->cache_read_tokens->toBe(800)
        ->output_tokens->toBe(50)
        // At the catalog's API prices: $2/M input (a tenth for cached) and $16/M output.
        ->cost->toEqualWithDelta(0.00136, 0.000001);
})->group('AGT-009', 'USAGE-001');

test('failed Codex turns are explained once', function (string $error, string $expected) {
    [$project] = codexProject($this->user);

    sendCodexEvents($project, [
        ['type' => 'error', 'message' => 'Reconnecting... 1/5 (stream disconnected, cf-ray: a4301e429503)'],
        ['type' => 'error', 'message' => $error],
        ['type' => 'turn.failed', 'error' => ['message' => $error]],
        ['type' => 'zap.exit', 'code' => 1, 'stderr' => '', 'reported' => true],
    ]);

    expect($project->messages()->where('role', '!=', MessageRole::User)->pluck('content')->all())->toBe(['Reconnecting to OpenAI', $expected])
        ->and($project->fresh()->status)->toBe(ProjectStatus::Idle);
})->with([
    'sign-in turned down' => ['unexpected status 401 Unauthorized: Missing bearer or basic authentication in header', 'OpenAI turned down your ChatGPT sign-in. Sign in with ChatGPT again in Settings → AI.'],
    'plan limit' => ["You've hit your usage limit. Try again later.", "Your ChatGPT plan's Codex usage limit is used up for now. Try again when it resets, or connect an OpenAI API key in Settings → AI."],
    'model not in ChatGPT' => ['{"type":"error","status":400,"error":{"type":"invalid_request_error","message":"The \'gpt-nope\' model is not supported when using Codex with a ChatGPT account."}}', "That model isn't included with ChatGPT. Choose another model under the chat box."],
    'anything else' => ['{"type":"error","status":400,"error":{"message":"boom"}}', 'Something went wrong: boom'],
])->group('AGT-009');

test('a Codex crash without a failed turn is still explained', function () {
    [$project] = codexProject($this->user);

    sendCodexEvents($project, [['type' => 'zap.exit', 'code' => 1, 'stderr' => 'Error: spawn codex ENOENT', 'reported' => false]]);

    expect($project->messages()->reorder()->latest('id')->value('content'))->toBe('The agent stopped unexpectedly. Error: Error: spawn codex ENOENT');
})->group('AGT-009');

test('a ChatGPT sign-in is offered the models its plan includes, as ChatGPT lists them', function () {
    config(['sandbox.chatgpt_models_url' => 'https://chatgpt-plan.test/models']);
    Http::fake(['chatgpt-plan.test/*' => Http::response(['models' => [
        ['slug' => 'gpt-5.6', 'visibility' => 'list'],
        ['slug' => 'codex-auto-review', 'visibility' => 'hide'],
    ]])]);

    expect(array_column($this->catalog->models(AgentProvider::Codex, $this->user), 'id'))->toBe(['gpt-5.6'])
        ->and($this->catalog->defaultModel(AgentProvider::Codex, $this->user))->toBe('gpt-5.6');
    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://chatgpt-plan.test/models?client_version=')
        && $request->hasHeader('Authorization', 'Bearer chatgpt-access-token')
        && $request->hasHeader('ChatGPT-Account-ID', 'acct-1234'));
})->group('AI-003');

test('without an answer from ChatGPT the models OpenAI includes with Codex are offered', function () {
    expect(array_column($this->catalog->models(AgentProvider::Codex, $this->user), 'id'))->toBe(['gpt-5.5']);
})->group('AI-003');
