<?php

use App\Enums\AgentHarness;
use App\Enums\AgentProvider;
use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Jobs\RunAgentTask;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Agents\ClaudeCodeEvents;
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
            // Only the forwarder's: the skills sync (SandboxSkills) runs first.
            if ($command !== ['php', '/opt/onedrop/skills.php']) {
                $this->envs[] = $env;
            }

            return parent::exec($id, $command, $env, $detach);
        }
    };
    app()->instance(SandboxProvider::class, $this->provider);

    // The user's Claude subscription: only Claude Code can run it.
    $this->user = User::factory()->create();
    $this->claude = AgentConnection::factory()->for($this->user)->claudeLogin()->create();
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

test('a Claude subscription alone makes Claude Code the default agent', function () {
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

    $this->actingAs($this->user)->post(route('projects.store', $this->user->currentOrganization()), [
        'prompt' => 'a timer',
        'agent_harness' => 'claude_code',
        'agent_provider' => 'claude',
        'agent_model' => 'claude-opus-5-5',
    ])->assertSessionHasNoErrors();

    expect($this->user->projects()->sole()->agent_harness)->toBe(AgentHarness::ClaudeCode);
})->group('AGT-007');

test('new projects default to the AI subscription over other connections', function () {
    AgentConnection::factory()->for($this->user)->provider(AgentProvider::OpenRouter)->create(['is_default' => true]);

    $this->actingAs($this->user)
        ->followingRedirects()->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('agent.harness', 'claude_code')
            ->where('agent.provider', 'claude')
            ->where('agent.model', 'claude-sonnet-5'));
})->group('AGT-007');

test('a ChatGPT sign-in makes new projects default to Codex on OpenCode', function () {
    $this->user->agentConnections()->delete();
    AgentConnection::factory()->for($this->user)->provider(AgentProvider::OpenRouter)->create(['is_default' => true]);
    AgentConnection::factory()->for($this->user)->chatGpt()->create(['is_default' => false]);

    expect($this->catalog->newProjectAgent($this->user))->toMatchArray([
        'agent_harness' => AgentHarness::OpenCode,
        'agent_provider' => AgentProvider::Codex,
    ]);
})->group('AGT-007');

test('the agent the user picks sticks for new projects until they pick another', function () {
    Queue::fake();
    AgentConnection::factory()->for($this->user)->provider(AgentProvider::OpenRouter)->create(['is_default' => false]);

    $this->actingAs($this->user)->post(route('projects.store', $this->user->currentOrganization()), [
        'prompt' => 'a timer',
        'agent_harness' => 'opencode',
        'agent_provider' => 'openrouter',
        'agent_model' => 'moonshotai/kimi-k3',
        'agent_variant' => null,
    ])->assertSessionHasNoErrors();

    $this->followingRedirects()->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('agent.harness', 'opencode')->where('agent.model', 'moonshotai/kimi-k3'));

    $this->patch(route('projects.agent.update', Project::factory()->for($this->user)->create()), [
        'agent_harness' => 'claude_code',
        'agent_provider' => 'claude',
        'agent_model' => 'claude-opus-5-5',
        'agent_variant' => 'high',
    ])->assertSessionHasNoErrors();

    expect($this->catalog->newProjectAgent($this->user->fresh()))->toBe([
        'agent_harness' => AgentHarness::ClaudeCode,
        'agent_provider' => AgentProvider::Claude,
        'agent_model' => 'claude-opus-5-5',
        'agent_variant' => 'high',
    ]);
})->group('AGT-007');

test('starting a project on the default does not pin it', function () {
    Queue::fake();

    $this->actingAs($this->user)->post(route('projects.store', $this->user->currentOrganization()), [
        'prompt' => 'a timer',
        'agent_harness' => 'claude_code',
        'agent_provider' => 'claude',
        'agent_model' => 'claude-sonnet-5',
    ])->assertSessionHasNoErrors();

    expect($this->user->fresh()->agent_preference)->toBeNull();
})->group('AGT-007');

test('a choice the user can no longer run falls back to the subscription', function () {
    $this->user->forceFill(['agent_preference' => ['harness' => 'opencode', 'provider' => 'openrouter', 'model' => 'moonshotai/kimi-k3', 'variant' => null]])->save();

    expect($this->catalog->newProjectAgent($this->user)['agent_harness'])->toBe(AgentHarness::ClaudeCode);
})->group('AGT-007');

test('a project started without a choice is saved on the default agent', function () {
    Queue::fake();

    $this->actingAs($this->user)->post(route('projects.store', $this->user->currentOrganization()), ['prompt' => 'a timer'])->assertSessionHasNoErrors();

    expect($this->user->projects()->sole())
        ->agent_harness->toBe(AgentHarness::ClaudeCode)
        ->agent_provider->toBe(AgentProvider::Claude);
})->group('AGT-007');

test('agent choices that cannot run are rejected with a reason', function (array $payload, string $message) {
    AgentConnection::factory()->for($this->user)->provider(AgentProvider::OpenRouter)->create(['is_default' => false]);
    $project = Project::factory()->for($this->user)->create();

    $this->actingAs($this->user)
        ->patch(route('projects.agent.update', $project), $payload)
        ->assertSessionHasErrors(['agent_provider' => $message]);
})->with([
    'Claude Code with another provider' => [['agent_harness' => 'claude_code', 'agent_provider' => 'openrouter', 'agent_model' => 'moonshotai/kimi-k3'], 'Claude Code only runs Claude models.'],
    'OpenCode with a Claude subscription' => [['agent_harness' => 'opencode', 'agent_provider' => 'claude', 'agent_model' => 'claude-sonnet-5'], "OpenCode can't use a Claude subscription. Choose Claude Code, or connect an Anthropic API key in Settings → AI."],
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

test('Claude Code runs on the user\'s own Claude sign-in with the chosen model and effort', function () {
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

    expect(collect($this->provider->executed)->last()['command'])->toBe(['node', '/opt/onedrop/forwarder.mjs'])
        ->and($this->provider->envs[0])->toMatchArray([
            'APP_AGENT' => 'claude_code',
            'APP_PROMPT' => 'build a timer',
            'APP_MODEL' => 'claude-opus-5-5',
            'APP_VARIANT' => 'high',
            'APP_SESSION_ID' => 'c0ffee00-0000-0000-0000-000000000000',
            'APP_CLAUDE_AUTH' => 'subscription',
        ])
        ->and($this->provider->envs[0])->not->toHaveKeys(['ANTHROPIC_API_KEY', 'CLAUDE_CODE_OAUTH_TOKEN']);
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
        ['type' => 'onedrop.start'],
        ['type' => 'system', 'subtype' => 'init', 'session_id' => 'sess-1'],
        ['type' => 'assistant', 'session_id' => 'sess-1', 'message' => ['model' => 'claude-opus-5-5', 'content' => [['type' => 'thinking']]]],
        ['type' => 'assistant', 'session_id' => 'sess-1', 'message' => ['model' => 'claude-opus-5-5', 'content' => [['type' => 'text', 'text' => "I'll build a timer."]]]],
        ['type' => 'assistant', 'session_id' => 'sess-1', 'message' => ['model' => 'claude-opus-5-5', 'content' => [['type' => 'tool_use', 'name' => 'Write', 'input' => ['file_path' => '/workspace/src/App.tsx']]]]],
        ['type' => 'assistant', 'session_id' => 'sess-1', 'message' => ['model' => 'claude-opus-5-5', 'content' => [['type' => 'tool_use', 'name' => 'Bash', 'input' => ['command' => 'npm install', 'description' => 'Install dependencies']]]]],
        ['type' => 'assistant', 'session_id' => 'sess-1', 'message' => ['model' => 'claude-opus-5-5', 'content' => [['type' => 'text', 'text' => 'Done! Press Start to begin timing.']]]],
        ['type' => 'result', 'subtype' => 'success', 'is_error' => false, 'result' => 'Done! Press Start to begin timing.', 'session_id' => 'sess-1'],
        ['type' => 'onedrop.exit', 'code' => 0, 'stderr' => '', 'reported' => false],
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
        ['type' => 'onedrop.exit', 'code' => 1, 'stderr' => '', 'reported' => true],
    ]);

    expect($project->messages()->pluck('content')->all())->toBe([$expected])
        ->and($project->fresh()->status)->toBe(ProjectStatus::Idle);
})->with([
    'not signed in' => [['result' => 'Not logged in · Please run /login'], "Sign in to Claude to build on your subscription: click **Sign in to Claude** above the chat box. I'll pick up your message as soon as you're signed in."],
    'plan limit' => [['result' => "You've hit your limit · resets 5pm"], "Your Claude plan's usage limit is used up for now. Try again when it resets, or connect an Anthropic API key in Settings → AI."],
    'no API credits' => [['result' => 'Credit balance is too low'], 'Your Anthropic account is out of credits. Add credits at console.anthropic.com, then try again.'],
    'overloaded' => [['api_error_status' => 529, 'result' => 'Overloaded'], 'Claude is overloaded right now. Try again in a minute.'],
    'anything else' => [['subtype' => 'error_during_execution', 'errors' => ['boom']], 'Something went wrong: boom'],
])->group('AGT-007');

test('a run that fails because Claude isn\'t signed in waits to run again after sign-in', function (array $result, bool $waits) {
    $project = Project::factory()->for($this->user)->create(['status' => ProjectStatus::Working]);
    $message = $project->messages()->create(['role' => MessageRole::User, 'content' => 'Build a timer']);

    sendClaudeEvents($project, [
        ['type' => 'result', 'is_error' => true, ...$result],
        ['type' => 'onedrop.exit', 'code' => 1, 'stderr' => '', 'reported' => true],
    ]);

    expect($project->fresh()->sign_in_retry_message_id)->toBe($waits ? $message->id : null);
})->with([
    'not signed in' => [['result' => 'Not logged in · Please run /login'], true],
    'plan limit' => [['result' => "You've hit your limit · resets 5pm"], false],
])->group('AI-005');

test('a run whose Claude sign-in is rejected tries once more before asking the user to sign in again', function () {
    Queue::fake();
    $project = Project::factory()->for($this->user)->create(['status' => ProjectStatus::Working]);
    $message = $project->messages()->create(['role' => MessageRole::User, 'content' => 'Build a timer']);
    $rejected = [
        ['type' => 'result', 'is_error' => true, 'api_error_status' => 401, 'result' => 'OAuth access token has been revoked.'],
        ['type' => 'onedrop.exit', 'code' => 1, 'stderr' => '', 'reported' => true],
    ];
    $logouts = fn () => collect($this->provider->executed)->where('command', ['claude', 'auth', 'logout'])->count();

    // A token revoked by a refresh elsewhere: the message just runs again, still signed in.
    sendClaudeEvents($project, $rejected);

    Queue::assertPushed(RunAgentTask::class, fn (RunAgentTask $job) => $job->message->is($message));
    expect($project->messages()->pluck('content')->all())->toBe(['Build a timer', ClaudeCodeEvents::RETRYING_SIGN_IN])
        ->and($project->fresh()->status)->toBe(ProjectStatus::Working)
        ->and($logouts())->toBe(0);

    // Turned down again: sign Claude Code out so the chat box offers to sign in, and wait for it.
    sendClaudeEvents($project, $rejected);

    expect($project->messages()->reorder()->latest('id')->value('content'))->toBe("Your Claude sign-in has expired or was signed out. Click **Sign in to Claude** above the chat box to sign in again. I'll pick up your message as soon as you're signed in.")
        ->and($project->fresh()->status)->toBe(ProjectStatus::Idle)
        ->and($project->fresh()->sign_in_retry_message_id)->toBe($message->id)
        ->and($logouts())->toBe(1);
    Queue::assertPushed(RunAgentTask::class, 1);
})->group('AI-005');

test('a Claude Code crash without a result is still explained', function () {
    $project = Project::factory()->for($this->user)->create(['status' => ProjectStatus::Working]);

    sendClaudeEvents($project, [['type' => 'onedrop.exit', 'code' => 1, 'stderr' => 'Error: spawn claude ENOENT', 'reported' => false]]);

    expect($project->messages()->sole()->content)->toBe('The agent stopped unexpectedly. Error: Error: spawn claude ENOENT');
})->group('AGT-007');
