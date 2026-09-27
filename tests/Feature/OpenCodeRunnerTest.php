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

    $this->user = User::factory()->has(AgentConnection::factory()->provider(AgentProvider::OpenRouter)->state(['credential' => 'sk-or-key']))->create();
    $this->project = Project::factory()->for($this->user)->create(['status' => ProjectStatus::Working]);
    $this->sandbox = Sandbox::factory()->for($this->project)->create(['external_id' => 'ctr-1']);
});

test('starts the forwarder detached with the prompt, model, key and a callback', function () {
    config(['sandbox.models.openrouter' => 'openrouter/anthropic/claude-sonnet-5']);
    $message = $this->project->messages()->create(['role' => MessageRole::User, 'content' => 'build a timer']);

    app(OpenCodeRunner::class)->start($this->project, $message);

    $call = $this->provider->executed[0];
    $env = $this->provider->envs[0];

    expect($call['id'])->toBe('ctr-1')
        ->and($call['command'])->toBe(['node', '/opt/zap/forwarder.mjs'])
        ->and($call['detach'])->toBeTrue()
        ->and($env['ZAP_PROMPT'])->toBe('build a timer')
        ->and($env['ZAP_MODEL'])->toBe('openrouter/anthropic/claude-sonnet-5')
        ->and($env['OPENROUTER_API_KEY'])->toBe('sk-or-key')
        ->and($env['ZAP_EVENTS_URL'])->toBe("http://host.docker.internal:8000/sandbox-events/{$this->sandbox->id}")
        ->and($env['ZAP_SESSION_ID'])->toBe('')
        ->and($this->sandbox->fresh()->acceptsEventsToken($env['ZAP_EVENTS_TOKEN']))->toBeTrue();
})->group('AGT-001');

test('resumes the previous agent session', function () {
    $this->project->update(['agent_session_id' => 'ses_abc']);
    $message = $this->project->messages()->create(['role' => MessageRole::User, 'content' => 'now add tags']);

    app(OpenCodeRunner::class)->start($this->project, $message);

    expect($this->provider->envs[0]['ZAP_SESSION_ID'])->toBe('ses_abc');
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
    'claude subscription token' => [fn ($test) => $test->user->agentConnections()->update(['provider' => 'claude', 'credential_type' => CredentialType::OAuthToken]), "can't use a Claude subscription token"],
    'no AI' => [fn ($test) => $test->user->agentConnections()->delete(), 'Connect an AI'],
])->group('AGT-001');
