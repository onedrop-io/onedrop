<?php

use App\Enums\AgentProvider;
use App\Enums\CredentialType;
use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Enums\SandboxStatus;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Agents\OpenCodeRunner;
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
            // Only the forwarder's: the skills sync (SandboxSkills) runs first.
            if ($command !== ['php', '/opt/onedrop/skills.php']) {
                $this->envs[] = $env;
            }

            return parent::exec($id, $command, $env, $detach);
        }
    };
    app()->instance(SandboxProvider::class, $this->provider);

    $this->user = User::factory()->has(AgentConnection::factory()->provider(AgentProvider::OpenRouter)->state(['credential' => 'sk-or-key']))->create();
    $this->project = Project::factory()->for($this->user)->create(['status' => ProjectStatus::Working]);
    $this->sandbox = Sandbox::factory()->for($this->project)->create(['external_id' => 'ctr-1']);
});

test('starts the forwarder detached with the prompt, model, key and a callback', function () {
    config(['sandbox.models.openrouter' => 'openrouter/anthropic/claude-sonnet-5']);
    $message = $this->project->messages()->create(['role' => MessageRole::User, 'content' => 'build a timer']);

    app(OpenCodeRunner::class)->start($this->project, $message);

    $call = collect($this->provider->executed)->last();
    $env = $this->provider->envs[0];

    expect($call['id'])->toBe('ctr-1')
        ->and($call['command'])->toBe(['node', '/opt/onedrop/forwarder.mjs'])
        ->and($call['detach'])->toBeTrue()
        ->and($env['APP_PROMPT'])->toBe('build a timer')
        ->and($env['APP_MODEL'])->toBe('openrouter/anthropic/claude-sonnet-5')
        ->and($env['OPENROUTER_API_KEY'])->toBe('sk-or-key')
        ->and($env['APP_EVENTS_URL'])->toBe("http://host.docker.internal:8000/sandbox-events/{$this->sandbox->id}")
        ->and($env['APP_SESSION_ID'])->toBe('')
        ->and($this->sandbox->fresh()->acceptsEventsToken($env['APP_EVENTS_TOKEN']))->toBeTrue();
})->group('AGT-001');

test('resumes the previous agent session', function () {
    $this->project->update(['agent_session_id' => 'ses_abc']);
    $message = $this->project->messages()->create(['role' => MessageRole::User, 'content' => 'now add tags']);

    app(OpenCodeRunner::class)->start($this->project, $message);

    expect($this->provider->envs[0]['APP_SESSION_ID'])->toBe('ses_abc');
})->group('AGT-001');

test('explains instead of running when the agent cannot start', function (Closure $setup, string $reason) {
    $setup($this);
    $message = $this->project->messages()->create(['role' => MessageRole::User, 'content' => 'hi']);

    app(OpenCodeRunner::class)->start($this->project->fresh(), $message);

    expect($this->provider->executed)->toBe([])
        ->and($this->project->messages()->reorder()->latest('id')->first()->content)->toContain($reason)
        ->and($this->project->fresh()->status)->toBe(ProjectStatus::Idle);
})->with([
    'sandbox not running' => [fn ($test) => $test->sandbox->update(['status' => SandboxStatus::Failed]), "sandbox isn't running"],
    'claude subscription' => [fn ($test) => $test->user->agentConnections()->update(['provider' => 'claude', 'credential_type' => CredentialType::ClaudeLogin]), "can't use a Claude subscription"],
    'no AI' => [fn ($test) => $test->user->agentConnections()->delete(), 'Connect an AI'],
])->group('AGT-001');

test('runs on a ChatGPT sign-in with only the access token in the sandbox', function () {
    $this->freezeTime();
    $this->user->agentConnections()->delete();
    AgentConnection::factory()->for($this->user)->chatGpt(['expires' => now()->addDays(5)->getTimestamp()])->create();
    $message = $this->project->messages()->create(['role' => MessageRole::User, 'content' => 'build a timer']);

    app(OpenCodeRunner::class)->start($this->project->fresh(), $message);

    $auth = json_decode($this->provider->envs[0]['OPENCODE_AUTH_CONTENT'], true);
    expect($this->provider->envs[0]['APP_MODEL'])->toStartWith('openai/')
        ->and($auth['openai'])->toBe([
            'type' => 'oauth',
            'refresh' => '',
            'access' => 'chatgpt-access-token',
            'expires' => now()->addDays(5)->getTimestamp() * 1000,
            'accountId' => 'acct-1234',
        ]);
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'auth.openai.com'));
})->group('AI-003');

test('refreshes a ChatGPT sign-in that is about to expire before running', function () {
    Http::fake(['auth.openai.com/oauth/token' => Http::response(['access_token' => 'access-2', 'refresh_token' => 'refresh-2', 'expires_in' => 864000])]);
    $this->user->agentConnections()->delete();
    $connection = AgentConnection::factory()->for($this->user)->chatGpt(['expires' => now()->addMinutes(30)->getTimestamp()])->create();
    $message = $this->project->messages()->create(['role' => MessageRole::User, 'content' => 'build a timer']);

    app(OpenCodeRunner::class)->start($this->project->fresh(), $message);

    $tokens = $connection->fresh()->chatGptTokens();
    expect(json_decode($this->provider->envs[0]['OPENCODE_AUTH_CONTENT'], true)['openai']['access'])->toBe('access-2')
        ->and($tokens)->toMatchArray(['access' => 'access-2', 'refresh' => 'refresh-2', 'account_id' => 'acct-1234', 'email' => 'dev@example.com']);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://auth.openai.com/oauth/token' && $request['grant_type'] === 'refresh_token' && $request['refresh_token'] === 'chatgpt-refresh-token');
})->group('AI-003');

test('asks the user to sign in again when a ChatGPT sign-in can no longer be refreshed', function () {
    Http::fake(['auth.openai.com/oauth/token' => Http::response(['error' => 'invalid_grant'], 401)]);
    $this->user->agentConnections()->delete();
    $connection = AgentConnection::factory()->for($this->user)->chatGpt(['expires' => now()->subMinute()->getTimestamp()])->create();
    $message = $this->project->messages()->create(['role' => MessageRole::User, 'content' => 'build a timer']);

    app(OpenCodeRunner::class)->start($this->project->fresh(), $message);

    expect($this->provider->executed)->toBe([])
        ->and($this->project->messages()->reorder()->latest('id')->first()->content)->toContain('Sign in with ChatGPT again')
        ->and($connection->fresh()->verified_at)->toBeNull()
        ->and($this->project->fresh()->status)->toBe(ProjectStatus::Idle);
})->group('AI-003');
