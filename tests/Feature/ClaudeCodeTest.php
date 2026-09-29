<?php

use App\Enums\AgentHarness;
use App\Enums\AgentProvider;
use App\Enums\CredentialType;
use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Agents\HarnessRunner;
use App\Sandbox\Agents\ModelCatalog;
use App\Sandbox\ExecResult;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use Illuminate\Support\Facades\Queue;

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

    // A Claude subscription token: only Claude Code can run it.
    $this->user = User::factory()->create();
    $this->claude = AgentConnection::factory()->for($this->user)->create([
        'credential_type' => CredentialType::OAuthToken,
        'credential' => 'sk-ant-oat01-subscription',
        'verified_at' => null,
    ]);
    $this->catalog = app(ModelCatalog::class);
});

/**
 * Post Claude Code events from the project's sandbox, as the forwarder does.
 *
 * @param  list<array<string, mixed>>  $events
 */
function sendClaudeEvents(Project $project, array $events): void
{
    $sandbox = $project->sandbox ?? Sandbox::factory()->for($project)->create();

    test()->withToken($sandbox->issueEventsToken())
        ->postJson(route('sandbox-events.store', $sandbox), ['agent' => 'claude_code', 'events' => $events])
        ->assertOk();
}

test('a subscription token alone makes Claude Code the default agent', function () {
    $project = Project::factory()->for($this->user)->create();

    expect($this->catalog->harnesses($this->user))->toBe([AgentHarness::ClaudeCode])
        ->and($this->catalog->harnessFor($project))->toBe(AgentHarness::ClaudeCode)
        ->and($this->catalog->selectionFor($project))->toBe(['provider' => AgentProvider::Claude, 'model' => 'claude-sonnet-5', 'variant' => null]);
})->group('AGT-007');

test('OpenCode stays the default when the user can run it', function () {
    AgentConnection::factory()->for($this->user)->provider(AgentProvider::OpenRouter)->create(['is_default' => false]);
    $project = Project::factory()->for($this->user)->create();

    expect($this->catalog->harnesses($this->user))->toBe([AgentHarness::OpenCode, AgentHarness::ClaudeCode])
        ->and($this->catalog->harnessFor($project))->toBe(AgentHarness::OpenCode)
        ->and($this->catalog->selectionFor($project)['provider'])->toBe(AgentProvider::OpenRouter);
})->group('AGT-007');

test('the picker lists each agent with the providers it can use', function () {
    AgentConnection::factory()->for($this->user)->provider(AgentProvider::OpenRouter)->create(['is_default' => false]);

    $this->actingAs($this->user)
        ->getJson(route('agent-models.index'))
        ->assertOk()
        ->assertJsonPath('harnesses', [
            ['id' => 'opencode', 'label' => 'OpenCode', 'providers' => ['openrouter']],
            ['id' => 'claude_code', 'label' => 'Claude Code', 'providers' => ['claude']],
        ])
        ->assertJsonPath('providers.*.id', ['openrouter', 'claude']);
})->group('AGT-007');

test('a project can be started on Claude Code with a Claude model', function () {
    Queue::fake();

    $this->actingAs($this->user)->post(route('projects.store'), [
        'prompt' => 'a timer',
        'agent_harness' => 'claude_code',
        'agent_provider' => 'claude',
        'agent_model' => 'claude-opus-5-5',
    ])->assertSessionHasNoErrors();

    expect($this->user->projects()->sole()->agent_harness)->toBe(AgentHarness::ClaudeCode);
})->group('AGT-007');

test('agent choices that cannot run are rejected with a reason', function (array $payload, string $message) {
    AgentConnection::factory()->for($this->user)->provider(AgentProvider::OpenRouter)->create(['is_default' => false]);
    $project = Project::factory()->for($this->user)->create();

    $this->actingAs($this->user)
        ->patch(route('projects.agent.update', $project), $payload)
        ->assertSessionHasErrors(['agent_provider' => $message]);
})->with([
    'Claude Code with another provider' => [['agent_harness' => 'claude_code', 'agent_provider' => 'openrouter', 'agent_model' => 'moonshotai/kimi-k3'], 'Claude Code only runs Claude models.'],
    'OpenCode with a subscription token' => [['agent_harness' => 'opencode', 'agent_provider' => 'claude', 'agent_model' => 'claude-sonnet-5'], "OpenCode can't use a Claude subscription token. Choose Claude Code, or connect an Anthropic API key in Settings → AI."],
])->group('AGT-007');

test('switching agents starts a fresh conversation and says so', function () {
    AgentConnection::factory()->for($this->user)->provider(AgentProvider::OpenRouter)->create(['is_default' => false]);
    $project = Project::factory()->for($this->user)->create(['agent_session_id' => 'ses_opencode']);

    $this->actingAs($this->user)
        ->patch(route('projects.agent.update', $project), ['agent_harness' => 'claude_code', 'agent_provider' => 'claude', 'agent_model' => 'claude-sonnet-5'])
        ->assertSessionHasNoErrors();

    $project->refresh();
    expect($project->agent_harness)->toBe(AgentHarness::ClaudeCode)
        ->and($project->agent_session_id)->toBeNull()
        ->and($project->messages()->sole()->content)->toBe('Switched to Claude Code. It starts a fresh conversation; your files are kept.');

    // Changing only the model keeps the conversation.
    $project->update(['agent_session_id' => 'ses_claude']);
    $this->actingAs($this->user)
        ->patch(route('projects.agent.update', $project), ['agent_harness' => 'claude_code', 'agent_provider' => 'claude', 'agent_model' => 'claude-opus-5-5']);

    expect($project->fresh()->agent_session_id)->toBe('ses_claude')
        ->and($project->messages()->count())->toBe(1);
})->group('AGT-007');

test('the agent cannot be switched while it works', function () {
    AgentConnection::factory()->for($this->user)->provider(AgentProvider::OpenRouter)->create(['is_default' => false]);
    $project = Project::factory()->for($this->user)->create(['status' => ProjectStatus::Working, 'agent_harness' => AgentHarness::OpenCode]);

    $this->actingAs($this->user)
        ->patch(route('projects.agent.update', $project), ['agent_harness' => 'claude_code', 'agent_provider' => 'claude', 'agent_model' => 'claude-sonnet-5'])
        ->assertSessionHasErrors(['agent_harness' => 'Wait for the agent to finish, or stop it, before switching agents.']);

    expect($project->fresh()->agent_harness)->toBe(AgentHarness::OpenCode);
})->group('AGT-007');

test('Claude Code runs in the sandbox on the subscription token with the chosen model and effort', function () {
    $project = Project::factory()->for($this->user)->create([
        'status' => ProjectStatus::Working,
        'agent_harness' => AgentHarness::ClaudeCode,
        'agent_provider' => AgentProvider::Claude,
        'agent_model' => 'claude-opus-5-5',
        'agent_variant' => 'high',
        'agent_session_id' => 'c0ffee00-0000-0000-0000-000000000000',
    ]);
    Sandbox::factory()->for($project)->create(['external_id' => 'ctr-1']);
    $message = $project->messages()->create(['role' => MessageRole::User, 'content' => 'build a timer']);

    app(HarnessRunner::class)->start($project, $message);

    expect($this->provider->executed[0]['command'])->toBe(['node', '/opt/zap/forwarder.mjs'])
        ->and($this->provider->envs[0])->toMatchArray([
            'APP_AGENT' => 'claude_code',
            'APP_PROMPT' => 'build a timer',
            'APP_MODEL' => 'claude-opus-5-5',
            'APP_VARIANT' => 'high',
            'APP_SESSION_ID' => 'c0ffee00-0000-0000-0000-000000000000',
            'CLAUDE_CODE_OAUTH_TOKEN' => 'sk-ant-oat01-subscription',
        ])
        ->and($this->provider->envs[0])->not->toHaveKey('ANTHROPIC_API_KEY');
})->group('AGT-007');

test('a Claude Code project falls back to OpenCode once Claude is disconnected', function () {
    $this->claude->delete();
    AgentConnection::factory()->for($this->user)->provider(AgentProvider::OpenRouter)->create();
    $project = Project::factory()->for($this->user)->create(['status' => ProjectStatus::Working, 'agent_harness' => AgentHarness::ClaudeCode]);
    Sandbox::factory()->for($project)->create(['external_id' => 'ctr-1']);
    $message = $project->messages()->create(['role' => MessageRole::User, 'content' => 'hi']);

    app(HarnessRunner::class)->start($project, $message);

    expect($this->provider->envs[0]['APP_AGENT'])->toBe('opencode');
})->group('AGT-007');

test('a Claude Code run becomes chat messages in order', function () {
    $project = Project::factory()->for($this->user)->create(['status' => ProjectStatus::Working]);

    sendClaudeEvents($project, [
        ['type' => 'zap.start'],
        ['type' => 'system', 'subtype' => 'init', 'session_id' => 'sess-1'],
        ['type' => 'assistant', 'session_id' => 'sess-1', 'message' => ['model' => 'claude-opus-5-5', 'content' => [['type' => 'thinking']]]],
        ['type' => 'assistant', 'session_id' => 'sess-1', 'message' => ['model' => 'claude-opus-5-5', 'content' => [['type' => 'text', 'text' => "I'll build a timer."]]]],
        ['type' => 'assistant', 'session_id' => 'sess-1', 'message' => ['model' => 'claude-opus-5-5', 'content' => [['type' => 'tool_use', 'name' => 'Write', 'input' => ['file_path' => '/workspace/src/App.tsx']]]]],
        ['type' => 'assistant', 'session_id' => 'sess-1', 'message' => ['model' => 'claude-opus-5-5', 'content' => [['type' => 'tool_use', 'name' => 'Bash', 'input' => ['command' => 'npm install', 'description' => 'Install dependencies']]]]],
        ['type' => 'assistant', 'session_id' => 'sess-1', 'message' => ['model' => 'claude-opus-5-5', 'content' => [['type' => 'text', 'text' => 'Done! Press Start to begin timing.']]]],
        ['type' => 'result', 'subtype' => 'success', 'is_error' => false, 'result' => 'Done! Press Start to begin timing.', 'session_id' => 'sess-1'],
        ['type' => 'zap.exit', 'code' => 0, 'stderr' => '', 'reported' => false],
    ]);

    $project->refresh();

    expect($project->messages->map(fn ($m) => [$m->role, $m->content])->all())->toBe([
        [MessageRole::Activity, 'Thinking'],
        [MessageRole::Assistant, "I'll build a timer."],
        [MessageRole::Activity, 'Creating src/App.tsx'],
        [MessageRole::Activity, 'Running Install dependencies'],
        [MessageRole::Assistant, 'Done! Press Start to begin timing.'],
    ])
        ->and($project->agent_session_id)->toBe('sess-1')
        ->and($project->status)->toBe(ProjectStatus::Idle);
})->group('AGT-007');

test('failed Claude Code runs are explained once', function (array $result, string $expected) {
    $project = Project::factory()->for($this->user)->create(['status' => ProjectStatus::Working]);

    sendClaudeEvents($project, [
        ['type' => 'assistant', 'message' => ['model' => '<synthetic>', 'content' => [['type' => 'text', 'text' => 'API Error']]]],
        ['type' => 'result', 'is_error' => true, ...$result],
        ['type' => 'zap.exit', 'code' => 1, 'stderr' => '', 'reported' => true],
    ]);

    expect($project->messages()->pluck('content')->all())->toBe([$expected])
        ->and($project->fresh()->status)->toBe(ProjectStatus::Idle);
})->with([
    'rejected token' => [['api_error_status' => 401, 'result' => 'Failed to authenticate. API Error: 401 API key is invalid.'], 'Claude rejected your key or token. Reconnect Claude in Settings → AI (for a subscription, run `claude setup-token` again).'],
    'plan limit' => [['result' => "You've hit your limit · resets 5pm"], "Your Claude plan's usage limit (or its monthly Agent SDK credit) is used up for now. Try again later, or connect an Anthropic API key in Settings → AI."],
    'no API credits' => [['result' => 'Credit balance is too low'], 'Your Anthropic account is out of credits. Add credits at console.anthropic.com, then try again.'],
    'overloaded' => [['api_error_status' => 529, 'result' => 'Overloaded'], 'Claude is overloaded right now. Try again in a minute.'],
    'anything else' => [['subtype' => 'error_during_execution', 'errors' => ['boom']], 'Something went wrong: boom'],
])->group('AGT-007');

test('a Claude Code crash without a result is still explained', function () {
    $project = Project::factory()->for($this->user)->create(['status' => ProjectStatus::Working]);

    sendClaudeEvents($project, [['type' => 'zap.exit', 'code' => 1, 'stderr' => 'Error: spawn claude ENOENT', 'reported' => false]]);

    expect($project->messages()->sole()->content)->toBe('The agent stopped unexpectedly. Error: Error: spawn claude ENOENT');
})->group('AGT-007');
