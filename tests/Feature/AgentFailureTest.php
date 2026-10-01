<?php

use App\Enums\AgentFailure;
use App\Enums\AgentProvider;
use App\Enums\CredentialType;
use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Jobs\ExplainAgentFailure;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\User;
use App\Sandbox\Agents\ClaudeCodeEvents;
use App\Sandbox\Agents\CodexEvents;
use App\Sandbox\Agents\Jev;
use App\Sandbox\Agents\OpenCodeEvents;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['services.openrouter.key' => 'sk-or-system']);

    $this->user = User::factory()->create();
    $this->project = Project::factory()->for($this->user)->create(['status' => ProjectStatus::Working]);

    /** Jev picks $cause with probability $probability. */
    $this->jev = function (string $cause, float $probability = 0.9): void {
        Http::fake([Jev::URL => Http::response(['answers' => [
            'cause' => ['type' => 'choice', 'choice' => $cause, 'confidence' => $probability, 'probabilities' => [$cause => $probability]],
        ]])]);
    };

    $this->reply = fn () => $this->project->messages()->where('role', MessageRole::Assistant)->latest('id')->value('content');
});

test('an error no known text matches gets the message for the cause Jev finds', function () {
    ($this->jev)('context_too_long');

    app(OpenCodeEvents::class)->apply($this->project, ['type' => 'error', 'error' => ['data' => ['message' => 'prompt is 214000 tokens, max 200000']]]);

    expect(($this->reply)())->toBe('The conversation got too long for the AI model. Start a new task for your next change.')
        ->and($this->project->messages()->where('role', MessageRole::Assistant)->count())->toBe(1);

    Http::assertSent(fn (Request $request) => $request->url() === Jev::URL
        && $request->hasHeader('Authorization', 'Bearer sk-or-system')
        && $request['state'] === ['error' => 'prompt is 214000 tokens, max 200000']
        && $request['questions']['cause']['type'] === 'choice'
        && array_key_exists('bad_key', $request['questions']['cause']['criteria']));
})->group('AGT-010');

test('each agent words the cause its own way', function (string $events, array $event, string $cause, string $expected) {
    ($this->jev)($cause);

    app($events)->apply($this->project, $event);

    expect(($this->reply)())->toBe($expected);
})->with([
    'Claude Code, rate limited' => [ClaudeCodeEvents::class, ['type' => 'result', 'is_error' => true, 'result' => 'API Error: 429 slow down'], 'rate_limited', 'Claude is overloaded right now. Try again in a minute.'],
    'Claude Code, bad key' => [ClaudeCodeEvents::class, ['type' => 'result', 'is_error' => true, 'result' => 'x-api-key header is invalid'], 'bad_key', 'Claude rejected your API key. Reconnect Claude in Settings → AI.'],
    'Codex, out of credits' => [CodexEvents::class, ['type' => 'turn.failed', 'error' => ['message' => 'billing_hard_limit_reached']], 'out_of_credits', 'Your OpenAI account is out of credits. Add credits at platform.openai.com, then try again.'],
    'OpenCode, out of credits' => [OpenCodeEvents::class, ['type' => 'error', 'error' => ['data' => ['message' => 'Payment Required']]], 'out_of_credits', 'Your AI provider says your balance is too low for this request. Try again in a minute, or add credits (for OpenRouter: openrouter.ai/settings/credits).'],
    'OpenCode, network' => [OpenCodeEvents::class, ['type' => 'error', 'error' => ['data' => ['message' => 'getaddrinfo EAI_AGAIN openrouter.ai']]], 'network', "The agent couldn't reach your AI provider. Try again in a minute."],
])->group('AGT-010');

test('a Claude subscription or ChatGPT sign-in gets its own wording', function () {
    AgentConnection::factory()->for($this->user)->provider(AgentProvider::Claude)->create(['credential_type' => CredentialType::ClaudeLogin]);
    AgentConnection::factory()->for($this->user)->provider(AgentProvider::Codex)->create(['credential_type' => CredentialType::ChatGpt]);
    $project = $this->project->fresh();

    expect(app(ClaudeCodeEvents::class)->failureMessage($project, AgentFailure::OutOfCredits))->toStartWith("Your Claude plan's usage limit is used up")
        ->and(app(CodexEvents::class)->failureMessage($project, AgentFailure::BadKey))->toBe('OpenAI turned down your ChatGPT sign-in. Sign in with ChatGPT again in Settings → AI.');
})->group('AGT-010');

test('a crash with an unmatched stderr is looked at too', function () {
    Queue::fake();
    $this->project->messages()->create(['role' => MessageRole::User, 'content' => 'build it']);

    app(OpenCodeEvents::class)->apply($this->project, ['type' => 'onedrop.exit', 'code' => 1, 'stderr' => "Error: socket hang up\n"]);

    expect(($this->reply)())->toBe('The agent stopped unexpectedly. Error: Error: socket hang up');
    Queue::assertPushed(ExplainAgentFailure::class, fn (ExplainAgentFailure $job) => $job->error === "Error: socket hang up\n"
        && $job->events === OpenCodeEvents::class
        && $job->conversation->is($this->project));
})->group('AGT-010');

test('known errors keep their message and Jev isn\'t asked', function () {
    Queue::fake();

    app(OpenCodeEvents::class)->apply($this->project, ['type' => 'error', 'error' => ['data' => ['message' => 'Insufficient balance']]]);
    app(CodexEvents::class)->apply($this->project, ['type' => 'turn.failed', 'error' => ['message' => 'Too Many Requests']]);

    Queue::assertNotPushed(ExplainAgentFailure::class);
})->group('AGT-010');

test('the generic message stays when Jev is unsure, says other, fails, or there\'s no key', function (Closure $setUp) {
    $setUp->call($this);

    app(OpenCodeEvents::class)->apply($this->project, ['type' => 'error', 'error' => ['data' => ['message' => 'boom']]]);

    expect(($this->reply)())->toBe('Something went wrong: boom');
})->with([
    'unsure' => [fn () => ($this->jev)('network', 0.6)],
    'other' => [fn () => ($this->jev)('other', 0.99)],
    'jev fails' => [fn () => Http::fake([Jev::URL => Http::response(['error' => 'down'], 500)])],
    'no key' => [function () {
        ($this->jev)('network');
        config(['services.openrouter.key' => null]);
    }],
])->group('AGT-010');

test('a message changed in the meantime is left alone', function () {
    ($this->jev)('network');
    $message = $this->project->messages()->create(['role' => MessageRole::Assistant, 'content' => 'Something went wrong: boom']);
    $job = new ExplainAgentFailure($this->project, $message->id, 'Something went wrong: boom', 'boom', OpenCodeEvents::class);
    $message->update(['content' => 'Fixed it.']);

    $job->handle(app(Jev::class));

    expect($message->fresh()->content)->toBe('Fixed it.');
})->group('AGT-010');
