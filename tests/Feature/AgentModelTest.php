<?php

use App\Enums\AgentProvider;
use App\Enums\CredentialType;
use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Agents\ModelCatalog;
use App\Sandbox\Agents\OpenCodeRunner;
use App\Sandbox\ExecResult;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->user = User::factory()->create();
    AgentConnection::factory()->for($this->user)->create(['is_default' => true]); // claude api key
    AgentConnection::factory()->for($this->user)->provider(AgentProvider::OpenRouter)->create(['is_default' => false, 'credential' => 'sk-or-key']);
    $this->catalog = app(ModelCatalog::class);
});

test('the catalog lists tool-capable, current models with featured ones first', function () {
    config(['sandbox.featured_models.claude' => ['claude-sonnet-5', 'claude-opus-5-5', 'not-in-catalog']]);

    $models = $this->catalog->models(AgentProvider::Claude);

    expect(array_column($models, 'id'))->toBe(['claude-sonnet-5', 'claude-opus-5-5', 'claude-haiku-4-5'])
        ->and($models[0])->toMatchArray([
            'name' => 'Claude Sonnet 5',
            'featured' => true,
            'efforts' => ['low', 'medium', 'high', 'xhigh', 'max'],
            'context' => 1000000,
            'cost' => ['input' => 2.0, 'output' => 10.0],
        ])
        ->and($models[2]['featured'])->toBeFalse()
        ->and($models[2]['efforts'])->toBe([]);
})->group('AGT-002');

test('featured ids match catalog ids spelled with dots or dashes', function () {
    config(['sandbox.featured_models.codex' => ['gpt-5-6']]);

    expect($this->catalog->models(AgentProvider::Codex)[0])->toMatchArray(['id' => 'gpt-5.6', 'featured' => true]);
})->group('AGT-002');

test('the catalog is cached', function () {
    $this->catalog->models(AgentProvider::Claude);
    $this->catalog->models(AgentProvider::OpenRouter);

    Http::assertSentCount(1);
})->group('AGT-002');

test('without the catalog the configured default and featured models are still offered', function () {
    config(['sandbox.catalog_url' => 'https://models-down.test/api.json']);
    Http::fake(['models-down.test/*' => Http::response('', 503)]);

    expect(array_column($this->catalog->models(AgentProvider::Codex), 'id'))
        ->toContain(explode('/', config('sandbox.models.codex'))[1], ...config('sandbox.featured_models.codex'));
})->group('AGT-002');

test('the picker lists only providers the agent can use, plus favorites', function () {
    $this->user->agentConnections()->where('provider', 'openrouter')->delete();
    AgentConnection::factory()->for($this->user)->provider(AgentProvider::Codex)->create(['is_default' => false, 'credential_type' => CredentialType::OAuthToken]);
    $this->user->forceFill(['favorite_models' => ['claude:claude-sonnet-5']])->save();

    $this->actingAs($this->user)
        ->getJson(route('agent-models.index'))
        ->assertOk()
        ->assertJsonCount(1, 'providers')
        ->assertJsonPath('providers.0.id', 'claude')
        ->assertJsonPath('providers.0.label', 'Anthropic')
        ->assertJsonPath('providers.0.default_model', 'claude-sonnet-5')
        ->assertJsonPath('favorites', ['claude:claude-sonnet-5']);
})->group('AGT-002');

test('users can star and unstar models', function () {
    $this->actingAs($this->user)->putJson(route('agent-models.favorite'), ['provider' => 'openrouter', 'model' => 'moonshotai/kimi-k3', 'favorite' => true])->assertOk();
    expect($this->user->fresh()->favorite_models)->toBe(['openrouter:moonshotai/kimi-k3']);

    $this->actingAs($this->user)->putJson(route('agent-models.favorite'), ['provider' => 'openrouter', 'model' => 'moonshotai/kimi-k3', 'favorite' => false])->assertOk();
    expect($this->user->fresh()->favorite_models)->toBe([]);
})->group('AGT-002');

test('the picker lists recently chosen models, newest first', function () {
    $project = Project::factory()->for($this->user)->create();

    $this->actingAs($this->user)->patch(route('projects.agent.update', $project), ['agent_provider' => 'claude', 'agent_model' => 'claude-opus-5-5']);
    $this->actingAs($this->user)->patch(route('projects.agent.update', $project), ['agent_provider' => 'openrouter', 'agent_model' => 'moonshotai/kimi-k3']);
    $this->actingAs($this->user)->patch(route('projects.agent.update', $project), ['agent_provider' => 'claude', 'agent_model' => 'claude-opus-5-5']);

    $this->actingAs($this->user)
        ->getJson(route('agent-models.index'))
        ->assertJsonPath('recent', ['claude:claude-opus-5-5', 'openrouter:moonshotai/kimi-k3']);
})->group('AGT-002');

test('starting a project with a model remembers it as recent', function () {
    Queue::fake();

    $this->actingAs($this->user)->post(route('projects.store'), ['prompt' => 'A todo app', 'agent_provider' => 'openrouter', 'agent_model' => 'anthropic/claude-sonnet-5']);

    expect($this->user->fresh()->recent_models)->toBe(['openrouter:anthropic/claude-sonnet-5']);
})->group('AGT-002');

test('a project uses the owner default until a model is chosen', function () {
    $project = Project::factory()->for($this->user)->create();

    $this->actingAs($this->user)
        ->get(route('projects.show', $project))
        ->assertInertia(fn ($page) => $page
            ->where('agent.provider', 'claude')
            ->where('agent.model', 'claude-sonnet-5')
            ->where('agent.name', 'Claude Sonnet 5')
            ->where('agent.variant', null)
            ->where('agent.efforts', ['low', 'medium', 'high', 'xhigh', 'max']));
})->group('AGT-002');

test('users can change a project model and reasoning level', function () {
    $project = Project::factory()->for($this->user)->create();

    $this->actingAs($this->user)
        ->patch(route('projects.agent.update', $project), ['agent_provider' => 'openrouter', 'agent_model' => 'anthropic/claude-sonnet-5', 'agent_variant' => 'max'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('projects.show', $project));

    $project->refresh();
    expect($project->agent_provider)->toBe(AgentProvider::OpenRouter)
        ->and($project->agent_model)->toBe('anthropic/claude-sonnet-5')
        ->and($project->agent_variant)->toBe('max');
})->group('AGT-002');

test('invalid choices are rejected with a reason', function (array $payload, string $field, string $message) {
    $project = Project::factory()->for($this->user)->create();

    $this->actingAs($this->user)
        ->patch(route('projects.agent.update', $project), $payload)
        ->assertSessionHasErrors([$field => $message]);

    expect($project->fresh()->agent_model)->toBeNull();
})->with([
    'provider not connected' => [['agent_provider' => 'codex', 'agent_model' => 'gpt-5.6'], 'agent_provider', 'Connect OpenAI in Settings → AI first.'],
    'unknown model' => [['agent_provider' => 'claude', 'agent_model' => 'claude-imaginary'], 'agent_model', "That model isn't available from Anthropic."],
    'deprecated model' => [['agent_provider' => 'claude', 'agent_model' => 'claude-old'], 'agent_model', "That model isn't available from Anthropic."],
    'unsupported reasoning' => [['agent_provider' => 'openrouter', 'agent_model' => 'moonshotai/kimi-k3', 'agent_variant' => 'max'], 'agent_variant', "That reasoning level isn't available for MoonshotAI: Kimi K3."],
])->group('AGT-002');

test('only the owner can change the model', function () {
    $project = Project::factory()->for($this->user)->create();

    $this->actingAs(User::factory()->has(AgentConnection::factory())->create())
        ->patch(route('projects.agent.update', $project), ['agent_provider' => 'claude', 'agent_model' => 'claude-sonnet-5'])
        ->assertForbidden();
})->group('AGT-002');

test('a model can be picked when starting a project', function () {
    Queue::fake();

    $this->actingAs($this->user)->post(route('projects.store'), [
        'prompt' => 'a timer',
        'agent_provider' => 'claude',
        'agent_model' => 'claude-opus-5-5',
        'agent_variant' => 'xhigh',
    ])->assertSessionHasNoErrors();

    expect($this->user->projects()->sole()->only('agent_model', 'agent_variant'))
        ->toBe(['agent_model' => 'claude-opus-5-5', 'agent_variant' => 'xhigh']);
})->group('AGT-002');

test('the agent runs the chosen model and reasoning level with that provider key', function () {
    $provider = new class extends FakeSandboxProvider
    {
        /** @var list<array<string, string>> */
        public array $envs = [];

        public function exec(string $id, array $command, array $env = [], bool $detach = false): ExecResult
        {
            $this->envs[] = $env;

            return parent::exec($id, $command, $env, $detach);
        }
    };
    app()->instance(SandboxProvider::class, $provider);

    $project = Project::factory()->for($this->user)->create([
        'status' => ProjectStatus::Working,
        'agent_provider' => AgentProvider::OpenRouter,
        'agent_model' => 'anthropic/claude-sonnet-5',
        'agent_variant' => 'high',
    ]);
    Sandbox::factory()->for($project)->create(['external_id' => 'ctr-1']);
    $message = $project->messages()->create(['role' => MessageRole::User, 'content' => 'go']);

    app(OpenCodeRunner::class)->start($project, $message);

    expect($provider->envs[0])->toMatchArray([
        'APP_MODEL' => 'openrouter/anthropic/claude-sonnet-5',
        'APP_VARIANT' => 'high',
        'OPENROUTER_API_KEY' => 'sk-or-key',
    ])->and($provider->envs[0])->not->toHaveKey('ANTHROPIC_API_KEY');
})->group('AGT-002');

test('a chosen provider that was disconnected falls back to the default', function () {
    $project = Project::factory()->for($this->user)->create(['agent_provider' => AgentProvider::OpenRouter, 'agent_model' => 'moonshotai/kimi-k3']);
    $this->user->agentConnections()->where('provider', 'openrouter')->delete();

    expect($this->catalog->selectionFor($project->fresh()))
        ->toBe(['provider' => AgentProvider::Claude, 'model' => 'claude-sonnet-5', 'variant' => null]);
})->group('AGT-002');

test('only models included with Codex are offered on a ChatGPT sign-in', function (string $id, bool $included) {
    expect($this->catalog->includedWithChatGpt($id))->toBe($included);
})->with([
    ['gpt-5.5', true],
    ['gpt-5.4-mini', true],
    ['gpt-6-astra', true],
    ['gpt-5.6', false],
    ['gpt-5.5-pro', false],
    ['gpt-6-pro', false],
    ['gpt-5.2', false],
    ['o3', false],
])->group('AI-003');

test('a ChatGPT sign-in gets a filtered picker and a default it can run', function () {
    Cache::put(ModelCatalog::CACHE_KEY, ['openai' => ['models' => [
        'gpt-5.6' => ['id' => 'gpt-5.6', 'name' => 'GPT-5.6', 'tool_call' => true, 'release_date' => '2026-08-01'],
        'gpt-5.5' => ['id' => 'gpt-5.5', 'name' => 'GPT-5.5', 'tool_call' => true, 'release_date' => '2026-05-01'],
        'gpt-5.5-pro' => ['id' => 'gpt-5.5-pro', 'name' => 'GPT-5.5 Pro', 'tool_call' => true, 'release_date' => '2026-05-01'],
    ]]]);
    config(['sandbox.models.codex' => 'openai/gpt-5.6', 'sandbox.featured_models.codex' => []]);
    $this->user->agentConnections()->delete();
    AgentConnection::factory()->for($this->user)->chatGpt()->create(['is_default' => true]);

    $provider = $this->actingAs($this->user)->getJson(route('agent-models.index'))->assertOk()->json('providers.0');

    expect($provider['id'])->toBe('codex')
        ->and($provider['default_model'])->toBe('gpt-5.5')
        ->and(array_column($provider['models'], 'id'))->toBe(['gpt-5.5'])
        ->and($this->catalog->defaultSelection($this->user)['model'])->toBe('gpt-5.5')
        ->and($this->catalog->models(AgentProvider::Codex))->toHaveCount(3);

    // A project saved on a model the sign-in can't run falls back to the default.
    $project = Project::factory()->for($this->user)->create(['agent_provider' => AgentProvider::Codex, 'agent_model' => 'gpt-5.6']);
    expect($this->catalog->selectionFor($project)['model'])->toBe('gpt-5.5');
})->group('AI-003');

test('a Gemini key runs Google models through OpenCode with that key', function () {
    $provider = new class extends FakeSandboxProvider
    {
        /** @var list<array<string, string>> */
        public array $envs = [];

        public function exec(string $id, array $command, array $env = [], bool $detach = false): ExecResult
        {
            $this->envs[] = $env;

            return parent::exec($id, $command, $env, $detach);
        }
    };
    app()->instance(SandboxProvider::class, $provider);
    AgentConnection::factory()->for($this->user)->provider(AgentProvider::Gemini)->create(['is_default' => false, 'credential' => 'AIza-gemini-key']);

    $project = Project::factory()->for($this->user)->create([
        'status' => ProjectStatus::Working,
        'agent_provider' => AgentProvider::Gemini,
        'agent_model' => 'gemini-3.8-flash',
    ]);
    Sandbox::factory()->for($project)->create(['external_id' => 'ctr-1']);
    $message = $project->messages()->create(['role' => MessageRole::User, 'content' => 'go']);

    app(OpenCodeRunner::class)->start($project, $message);

    expect(array_column($this->catalog->models(AgentProvider::Gemini), 'id'))->toBe(['gemini-3.8-flash'])
        ->and($provider->envs[0])->toMatchArray([
            'APP_MODEL' => 'google/gemini-3.8-flash',
            'GOOGLE_GENERATIVE_AI_API_KEY' => 'AIza-gemini-key',
        ]);
})->group('AI-004');
