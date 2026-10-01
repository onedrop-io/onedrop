<?php

use App\Enums\AgentProvider;
use App\Enums\CredentialType;
use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Enums\TurnOutcome;
use App\Jobs\CheckTurnOutcome;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\User;
use App\Sandbox\Agents\Jev;
use App\Sandbox\Agents\OllamaServer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    config(['services.openrouter.key' => 'sk-or-system', 'sandbox.task_copies' => false]);

    $this->user = User::factory()->create();
    $this->server = AgentConnection::factory()->for($this->user)->provider(AgentProvider::Ollama)->create([
        'credential_type' => CredentialType::OllamaServer,
        'credential' => json_encode(['url' => 'https://8.8.8.8', 'key' => 'server-key']),
        'hint' => '8.8.8.8',
    ]);
    $this->project = Project::factory()->for($this->user)->create(['status' => ProjectStatus::Idle, 'read_at' => now()]);
    $this->turn = $this->project->messages()->create(['role' => MessageRole::User, 'content' => 'add a contact form']);
    $this->project->messages()->create(['role' => MessageRole::Assistant, 'content' => 'Should the form email you, or save to the database?']);

    $this->answer = ['answers' => ['outcome' => ['type' => 'choice', 'choice' => 'question', 'confidence' => 0.9, 'probabilities' => ['question' => 0.9]]]];
    $this->check = fn () => (new CheckTurnOutcome($this->project, $this->turn->id))->handle(app(Jev::class));
});

test('a project whose owner has Nimble on their Ollama server is checked there, not on OpenRouter', function () {
    AgentConnection::factory()->for($this->user)->provider(AgentProvider::OpenRouter)->create(['credential' => 'sk-or-owner']);
    Http::fake([
        '8.8.8.8/api/tags' => Http::response(['models' => [['name' => 'qwen3-coder:30b'], ['name' => 'nimble:latest']]]),
        '8.8.8.8/v1/systemone' => Http::response($this->answer),
    ]);

    ($this->check)();

    expect($this->project->fresh()->turn_outcome)->toBe(TurnOutcome::Question);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://8.8.8.8/v1/systemone'
        && $request->hasHeader('Authorization', 'Bearer server-key')
        && $request['model'] === 'nimble:latest'
        && $request['questions']['outcome']['type'] === 'choice');
    Http::assertNotSent(fn (Request $request) => $request->url() === Jev::URL);
})->group('AI-007');

test('a server without Nimble leaves the checks on OpenRouter', function () {
    Http::fake([
        '8.8.8.8/api/tags' => Http::response(['models' => [['name' => 'qwen3-coder:30b']]]),
        Jev::URL => Http::response($this->answer),
    ]);

    ($this->check)();

    expect($this->project->fresh()->turn_outcome)->toBe(TurnOutcome::Question);
    Http::assertSent(fn (Request $request) => $request->url() === Jev::URL && $request->hasHeader('Authorization', 'Bearer sk-or-system'));
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/v1/systemone'));
})->group('AI-007');

test('Nimble failing isn\'t retried on OpenRouter', function () {
    Http::fake([
        '8.8.8.8/api/tags' => Http::response(['models' => [['name' => 'nimble:9b']]]),
        '8.8.8.8/v1/systemone' => Http::response(['error' => 'model is loading'], 500),
        Jev::URL => Http::response($this->answer),
    ]);

    ($this->check)();

    expect($this->project->fresh()->turn_outcome)->toBeNull();
    Http::assertNotSent(fn (Request $request) => $request->url() === Jev::URL);
})->group('AI-007');

test('Nimble is never asked on a private address', function () {
    Http::fake();
    $this->server->update(['credential' => json_encode(['url' => 'http://169.254.169.254', 'key' => ''])]);

    expect(fn () => app(OllamaServer::class)->decide($this->server, ['model' => 'nimble'], 3))->toThrow(ValidationException::class);
    Http::assertNothingSent();
})->group('AI-007');
