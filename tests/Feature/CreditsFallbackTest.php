<?php

use App\Enums\AgentHarness;
use App\Enums\AgentProvider;
use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Enums\UsagePayer;
use App\Jobs\RunAgentTask;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\Task;
use App\Models\User;
use App\Sandbox\Agents\CreditsFallback;
use App\Sandbox\Agents\HarnessRunner;
use App\Sandbox\ExecResult;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Fakes Autumn with $cents of AI credits left, and OpenRouter's key management.
 */
function fakeCreditsLeft(float $cents): void
{
    Http::fake([
        'autumn.test/v1/customers' => Http::response(['id' => 'org']),
        'autumn.test/v1/check' => Http::response(['allowed' => $cents > 0, 'balance' => [
            'remaining' => $cents,
            'next_reset_at' => null,
            'breakdown' => [['included_grant' => 500, 'remaining' => $cents, 'reset' => ['interval' => 'one_off']]],
        ]]),
        'autumn.test/v1/track' => Http::response(['value' => 1]),
        'openrouter.ai/api/v1/keys' => Http::response(['key' => 'sk-or-org-key', 'data' => ['hash' => 'hash-1']], 201),
        'openrouter.ai/api/v1/keys/*' => Http::response(['data' => ['hash' => 'hash-1', 'usage' => 0]]),
    ]);
}

beforeEach(function () {
    config([
        'sandbox.task_copies' => false,
        'services.autumn.key' => 'am_sk_test',
        'services.autumn.url' => 'https://autumn.test/v1',
        'services.openrouter.provisioning_key' => 'sk-or-mgmt',
        'sandbox.models.credits' => 'openrouter/deepseek/deepseek-v4.1-flash',
    ]);
    Queue::fake();

    $this->provider = new class extends FakeSandboxProvider
    {
        /** @var list<array<string, string>> */
        public array $envs = [];

        public function exec(string $id, array $command, array $env = [], bool $detach = false, bool $root = false): ExecResult
        {
            if ($command === ['node', '/opt/onedrop/forwarder.mjs']) {
                $this->envs[] = $env;
            }

            return parent::exec($id, $command, $env, $detach, $root);
        }
    };
    app()->instance(SandboxProvider::class, $this->provider);

    // The owner's Claude subscription, on Claude Code.
    $this->user = User::factory()->create();
    AgentConnection::factory()->for($this->user)->claudeLogin()->create();
    $this->project = Project::factory()->for($this->user)->create(['agent_harness' => AgentHarness::ClaudeCode, 'agent_provider' => 'claude', 'agent_model' => 'claude-sonnet-5']);
    $this->sandbox = Sandbox::factory()->for($this->project)->create();
    $this->task = Task::factory()->for($this->project)->working()->create(['pull_request_number' => 12, 'agent_session_id' => 'ses_claude']);

    $this->handOff = fn (array $meta = [CreditsFallback::AUTOMATIC => true]) => $this->task->messages()->create(['role' => MessageRole::User, 'content' => 'Commits pushed to pull request #12 were just merged in here.', 'meta' => $meta]);
    $this->sendEvents = fn (string $agent, array $events) => $this->withToken($this->task->issueEventsToken())
        ->postJson(route('sandbox-events.tasks.store', [$this->sandbox, $this->task]), ['agent' => $agent, 'events' => $events])
        ->assertOk();
    $this->signedOut = [
        ['type' => 'result', 'is_error' => true, 'result' => 'Not logged in · Please run /login'],
        ['type' => 'onedrop.exit', 'code' => 1, 'stderr' => '', 'reported' => true],
    ];
});

test("OneDrop's own work for a pull request's task runs on AI credits when Claude isn't signed in, in a conversation of its own", function () {
    fakeCreditsLeft(300);
    $message = ($this->handOff)();

    ($this->sendEvents)('claude_code', $this->signedOut);

    $message->refresh();
    expect(CreditsFallback::moved($message))->toBeTrue()
        ->and($this->task->messages()->where('role', MessageRole::Activity)->pluck('content')->all())
        ->toBe(["Claude isn't signed in, so this runs on AI credits (DeepSeek: DeepSeek V4.1 Flash) instead. Your next message goes back to the task's own agent."])
        ->and($this->task->messages()->where('role', MessageRole::Assistant)->count())->toBe(0)
        ->and($this->task->fresh()->status)->toBe(ProjectStatus::Working);
    Queue::assertPushed(RunAgentTask::class, fn (RunAgentTask $job) => $job->message->is($message));

    // It runs on OpenCode with the credits' model, starting a conversation of its own.
    app(HarnessRunner::class)->start($this->project, $message);

    expect($this->provider->envs)->toHaveCount(1)
        ->and($this->provider->envs[0])->toMatchArray([
            'APP_AGENT' => 'opencode',
            'APP_MODEL' => 'openrouter/deepseek/deepseek-v4.1-flash',
            'APP_SESSION_ID' => '',
            'OPENROUTER_API_KEY' => 'sk-or-org-key',
        ]);

    // Its OpenCode session doesn't replace Claude Code's, and AI credits pay for it.
    ($this->sendEvents)('opencode', [
        ['type' => 'step_finish', 'sessionID' => 'ses_credits', 'model' => 'openrouter/deepseek/deepseek-v4.1-flash', 'part' => ['type' => 'step-finish', 'tokens' => ['input' => 100, 'output' => 10], 'cost' => 0.001]],
    ]);

    expect($this->task->fresh()->agent_session_id)->toBe('ses_claude')
        ->and($this->user->agentUsages()->sole()->only('provider', 'paid_by'))->toBe(['provider' => AgentProvider::Credits, 'paid_by' => UsagePayer::Credits]);
})->group('GIT-029');

test("the user's own messages, and OneDrop's own work without credits to spend, wait for Claude's sign-in", function (bool $automatic, ?float $cents) {
    fakeCreditsLeft($cents ?? 300);

    if ($cents === null) {
        config(['services.openrouter.provisioning_key' => null]);
    }
    $message = ($this->handOff)($automatic ? [CreditsFallback::AUTOMATIC => true] : []);

    ($this->sendEvents)('claude_code', $this->signedOut);

    expect(CreditsFallback::moved($message->fresh()))->toBeFalse()
        ->and($this->task->messages()->reorder()->latest('id')->value('content'))->toStartWith('Sign in to Claude to build on your subscription')
        ->and($this->task->fresh()->sign_in_retry_message_id)->toBe($message->id)
        ->and($this->task->fresh()->status)->toBe(ProjectStatus::Idle);
    Queue::assertNotPushed(RunAgentTask::class);
})->with([
    "the user's own message" => [false, 300],
    'AI credits off' => [true, null],
    'no AI credits left' => [true, 0],
])->group('GIT-029');

test("OneDrop's own work moves to AI credits when the ChatGPT sign-in has expired, but not when OpenAI is only unreachable", function (int $status, bool $moves) {
    AgentConnection::factory()->for($this->user)->chatGpt(['expires' => now()->subMinute()->getTimestamp()])->create(['is_default' => false]);
    $this->task->update(['agent_harness' => AgentHarness::Codex, 'agent_provider' => 'codex', 'agent_model' => 'gpt-5.5']);
    Http::fake(['auth.openai.com/*' => Http::response(['error' => 'x'], $status)]);
    fakeCreditsLeft(300);
    $message = ($this->handOff)();

    app(HarnessRunner::class)->start($this->project, $message);

    expect(CreditsFallback::moved($message->fresh()))->toBe($moves)
        ->and(array_column($this->provider->envs, 'APP_AGENT'))->toBe($moves ? ['opencode'] : []);
})->with([
    'expired' => [400, true],
    'OpenAI unreachable' => [500, false],
])->group('GIT-029');
