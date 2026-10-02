<?php

use App\Enums\AgentHarness;
use App\Enums\AgentProvider;
use App\Enums\UsagePayer;
use App\Models\AgentConnection;
use App\Models\AgentUsage;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\Task;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->for($this->user)->create([
        'agent_provider' => AgentProvider::Claude,
        'agent_model' => 'claude-sonnet-5',
    ]);
    $this->sandbox = Sandbox::factory()->for($this->project)->create();
});

/**
 * Post events as the sandbox's forwarder does, to the main chat or a task's.
 *
 * @param  list<array<string, mixed>>  $events
 */
function sendUsageEvents(Sandbox $sandbox, string $agent, array $events, ?Task $task = null): void
{
    test()->withToken($task ? $task->issueEventsToken() : $sandbox->issueEventsToken())
        ->postJson($task ? route('sandbox-events.tasks.store', [$sandbox, $task]) : route('sandbox-events.store', $sandbox), ['agent' => $agent, 'events' => $events])
        ->assertOk();
}

test('a Claude Code result records each model it used, charged to the project owner', function () {
    $task = Task::factory()->for($this->project)->create();

    sendUsageEvents($this->sandbox, 'claude_code', [[
        'type' => 'result',
        'subtype' => 'success',
        'is_error' => false,
        'session_id' => 'sess-1',
        'modelUsage' => [
            'claude-opus-5-5' => ['inputTokens' => 9, 'outputTokens' => 85, 'cacheReadInputTokens' => 14053, 'cacheCreationInputTokens' => 12803, 'costUSD' => 0.0274],
            'claude-haiku-4-5-20251001' => ['inputTokens' => 100, 'outputTokens' => 20, 'cacheReadInputTokens' => 0, 'cacheCreationInputTokens' => 0, 'costUSD' => 0.0002],
            '<synthetic>' => ['inputTokens' => 0, 'outputTokens' => 0, 'costUSD' => 0],
        ],
    ]], $task);

    $usages = AgentUsage::query()->orderBy('id')->get();

    expect($usages)->toHaveCount(2)
        ->and($usages[0]->only(['user_id', 'organization_id', 'project_id', 'task_id', 'harness', 'provider', 'model', 'session_id', 'input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens', 'cost']))->toBe([
            'user_id' => $this->user->id,
            'organization_id' => $this->project->organization_id,
            'project_id' => $this->project->id,
            'task_id' => $task->id,
            'harness' => AgentHarness::ClaudeCode,
            'provider' => AgentProvider::Claude,
            'model' => 'claude-opus-5-5',
            'session_id' => 'sess-1',
            'input_tokens' => 9,
            'output_tokens' => 85,
            'cache_read_tokens' => 14053,
            'cache_write_tokens' => 12803,
            'cost' => 0.0274,
        ])
        ->and($usages[1]->model)->toBe('claude-haiku-4-5-20251001');
})->group('USAGE-001');

test('a failed Claude Code run still records what it used', function () {
    sendUsageEvents($this->sandbox, 'claude_code', [[
        'type' => 'result',
        'subtype' => 'error_during_execution',
        'is_error' => true,
        'result' => 'overloaded',
        'modelUsage' => ['claude-opus-5-5' => ['inputTokens' => 50, 'outputTokens' => 5, 'costUSD' => 0.001]],
    ]]);

    expect(AgentUsage::query()->sole()->input_tokens)->toBe(50)
        ->and($this->project->messages()->count())->toBe(1);
})->group('USAGE-001');

test('OpenCode steps record their tokens and cost with the model the forwarder ran', function () {
    sendUsageEvents($this->sandbox, 'opencode', [
        ['type' => 'step_finish', 'sessionID' => 'ses_1', 'model' => 'openai/gpt-5.5', 'part' => ['type' => 'step-finish', 'tokens' => ['input' => 13655, 'output' => 2, 'reasoning' => 40, 'cache' => ['read' => 1792, 'write' => 0]], 'cost' => 0.00031]],
        // Older forwarders don't say the model; it's the project's.
        ['type' => 'step_finish', 'sessionID' => 'ses_1', 'part' => ['type' => 'step-finish', 'tokens' => ['input' => 10, 'output' => 1], 'cost' => 0.0001]],
        // Steps without tokens (e.g. a stopped one) aren't worth a row.
        ['type' => 'step_finish', 'sessionID' => 'ses_1', 'part' => ['type' => 'step-finish']],
    ]);

    $usages = AgentUsage::query()->orderBy('id')->get();

    expect($usages)->toHaveCount(2)
        ->and($usages[0]->only(['harness', 'provider', 'model', 'session_id', 'input_tokens', 'output_tokens', 'cache_read_tokens', 'cost']))->toBe([
            'harness' => AgentHarness::OpenCode,
            'provider' => AgentProvider::Codex,
            'model' => 'gpt-5.5',
            'session_id' => 'ses_1',
            'input_tokens' => 13655,
            'output_tokens' => 42,
            'cache_read_tokens' => 1792,
            'cost' => 0.00031,
        ])
        ->and($usages[1]->only(['provider', 'model']))->toBe(['provider' => AgentProvider::Claude, 'model' => 'claude-sonnet-5']);
})->group('USAGE-001');

test('the usage page sums the range by agent, model and project', function () {
    $this->travelTo(now()->setTime(12, 0));
    $other = Project::factory()->for($this->user)->create(['name' => 'Other app']);

    AgentUsage::factory()->for($this->user)->create(['project_id' => $this->project->id, 'model' => 'claude-opus-5-5', 'session_id' => 'a', 'cost' => 3, 'input_tokens' => 10, 'output_tokens' => 20, 'cache_read_tokens' => 70, 'cache_write_tokens' => 0]);
    AgentUsage::factory()->for($this->user)->create(['project_id' => $this->project->id, 'model' => 'claude-opus-5-5', 'session_id' => 'a', 'cost' => 1, 'input_tokens' => 0, 'output_tokens' => 0, 'cache_read_tokens' => 100, 'cache_write_tokens' => 0, 'created_at' => now()->subDays(2)]);
    AgentUsage::factory()->openCode()->for($this->user)->create(['project_id' => $other->id, 'session_id' => 'b', 'cost' => 0.5, 'input_tokens' => 5, 'output_tokens' => 5, 'cache_read_tokens' => 0, 'cache_write_tokens' => 0]);
    // Outside the range, and someone else's.
    AgentUsage::factory()->for($this->user)->create(['project_id' => $this->project->id, 'cost' => 99, 'created_at' => now()->subDays(31)]);
    AgentUsage::factory()->create(['cost' => 50]);

    $this->actingAs($this->user)->get(route('usage.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('usage/index')
            ->where('range', '30d')
            ->where('bucket', 'day')
            ->where('totals.cost', 4.5)
            ->where('totals.sessions', 2)
            ->where('totals.tokens', 210)
            ->where('totals.cache_read', 170)
            ->where('agents.0.harness', 'claude_code')
            ->where('agents.0.cost', 4)
            ->where('agents.0.sessions', 1)
            ->where('agents.1.harness', 'opencode')
            ->where('models.0.name', 'claude-opus-5-5')
            ->where('models.0.tokens', 200)
            ->where('models.1.name', 'gpt-5.5')
            ->where('models.1.provider', 'codex')
            ->where('projects.0.name', $this->project->name)
            ->where('projects.1.name', 'Other app')
            ->has('series', 30)
            ->where('series.29.cost.claude_code', 3)
            ->where('series.29.cost.opencode', 0.5)
            ->where('series.27.cost.claude_code', 1)
        );
})->group('USAGE-001');

test('the past day is shown by the hour', function () {
    $this->travelTo(now()->setTime(12, 30));
    AgentUsage::factory()->for($this->user)->create(['project_id' => $this->project->id, 'cost' => 2, 'created_at' => now()->subHours(3)]);
    AgentUsage::factory()->for($this->user)->create(['project_id' => $this->project->id, 'cost' => 7, 'created_at' => now()->subHours(30)]);

    $this->actingAs($this->user)->get(route('usage.index', ['range' => '24h']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('bucket', 'hour')
            ->where('totals.cost', 2)
            ->has('series', 24)
            ->where('series.20.cost.claude_code', 2)
        );
})->group('USAGE-001');

test('usage from a deleted project is kept', function () {
    AgentUsage::factory()->for($this->user)->create(['project_id' => $this->project->id, 'cost' => 2]);

    $this->project->delete();

    $this->actingAs($this->user)->get(route('usage.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('totals.cost', 2)
            ->where('projects.0.id', null)
            ->where('projects.0.name', 'Deleted project')
        );
})->group('USAGE-001');

test('an unknown range is rejected and guests are sent to log in', function () {
    $this->actingAs($this->user)->get(route('usage.index', ['range' => '1y']))->assertSessionHasErrors('range');

    auth()->logout();

    $this->get(route('usage.index'))->assertRedirect(route('login'));
})->group('USAGE-001');

test('each run records what paid for it', function (Closure $connect, string $paidBy) {
    $connect($this->user);

    sendUsageEvents($this->sandbox, 'claude_code', [[
        'type' => 'result',
        'subtype' => 'success',
        'is_error' => false,
        'session_id' => 'sess-1',
        'modelUsage' => ['claude-opus-5-5' => ['inputTokens' => 50, 'outputTokens' => 5, 'costUSD' => 0.4]],
    ]]);

    expect(AgentUsage::sole()->paid_by->value)->toBe($paidBy);
})->with([
    'a Claude plan' => [fn (User $user) => AgentConnection::factory()->for($user)->claudeLogin()->create(), 'plan'],
    'an API key' => [fn (User $user) => AgentConnection::factory()->for($user)->provider(AgentProvider::Claude)->create(), 'api_key'],
])->group('USAGE-001');

test('runs on a plan are included, not spent; the rest are cost', function () {
    AgentUsage::factory()->for($this->user)->create(['project_id' => $this->project->id, 'model' => 'claude-opus-5-5', 'session_id' => 'a', 'cost' => 40, 'paid_by' => UsagePayer::Plan]);
    AgentUsage::factory()->openCode()->for($this->user)->create(['project_id' => $this->project->id, 'session_id' => 'b', 'cost' => 0.5, 'paid_by' => UsagePayer::Credits]);
    AgentUsage::factory()->openCode()->for($this->user)->create(['project_id' => $this->project->id, 'session_id' => 'c', 'cost' => 2, 'paid_by' => UsagePayer::ApiKey]);

    $this->actingAs($this->user)->get(route('usage.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('totals.cost', 2.5)
            ->where('totals.included', 40)
            ->where('agents.0.harness', 'opencode')
            ->where('agents.1.included', 40)
            ->where('models.0.paid_by', 'plan')
            ->where('models.0.cost', 0)
            ->where('models.0.included', 40)
            ->where('projects.0.cost', 2.5)
            ->where('series.29.cost.claude_code', 0)
        );
})->group('USAGE-001');
