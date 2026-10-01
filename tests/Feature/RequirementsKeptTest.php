<?php

use App\Enums\AgentProvider;
use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Jobs\CheckRequirementsKept;
use App\Jobs\RunAgentTask;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Sandbox\Agents\AgentQueue;
use App\Sandbox\Agents\Jev;
use App\Sandbox\ExecResult;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use App\Sandbox\WorkspaceTests;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['services.openrouter.key' => 'sk-or-system']);

    $this->provider = new FakeSandboxProvider;
    app()->instance(SandboxProvider::class, $this->provider);

    $this->project = Project::factory()->create(['status' => ProjectStatus::Idle]);
    Sandbox::factory()->for($this->project)->create(['external_id' => 'ctr-1']);
    $this->turn = $this->project->messages()->create(['role' => MessageRole::User, 'content' => 'vite counter']);
    $this->project->messages()->create(['role' => MessageRole::Activity, 'content' => 'Creating main.js']);
    $this->project->messages()->create(['role' => MessageRole::Assistant, 'content' => 'The preview shows a counter.']);

    /** The sandbox has REQ.md with $requirements (none when null) and tests tagged with each of $tags. */
    $this->workspace = function (?string $requirements, array $tags = []): void {
        $this->provider->execUsing = fn (array $command) => match ($command[0]) {
            'cat' => $requirements === null ? new ExecResult(1, '', 'No such file') : new ExecResult(0, $requirements),
            'node' => new ExecResult(0, json_encode(['running' => false, 'tests' => array_map(fn (string $tag) => [
                'id' => $tag, 'file' => 'tests/e2e/counter.spec.ts', 'line' => 3, 'title' => 'User should be able to count', 'tags' => [$tag], 'result' => null,
            ], $tags)])),
            default => new ExecResult(0, ''),
        };
    };

    /** Jev answers each question with this probability. */
    $this->jev = function (float $changed, float $recorded = 0.9, float $tested = 0.9): void {
        Http::fake([Jev::URL => Http::response(['answers' => [
            'changed_app' => ['type' => 'noul', 'noul' => $changed],
            'recorded' => ['type' => 'noul', 'noul' => $recorded],
            'tested' => ['type' => 'noul', 'noul' => $tested],
        ]])]);
    };

    $this->check = fn () => (new CheckRequirementsKept($this->project, $this->turn->id))
        ->handle(app(Jev::class), $this->provider, app(WorkspaceTests::class), app(AgentQueue::class));

    $this->latest = fn () => $this->project->messages()->reorder()->latest('id')->first();
});

test('a turn that changed the app without requirements or tests gets one request to add them', function () {
    Queue::fake([RunAgentTask::class]);
    ($this->workspace)(null);
    ($this->jev)(changed: 0.95, recorded: 0.02, tested: 0.01);

    ($this->check)();

    expect(($this->latest)()->role)->toBe(MessageRole::User)
        ->and(($this->latest)()->content)
        ->toContain("didn't record it in /workspace/.onedrop/REQ.md or write and run browser tests for it")
        ->toContain('/opt/onedrop/guides/requirements.md')
        ->toContain('/opt/onedrop/guides/tests.md');
    Queue::assertPushed(RunAgentTask::class);

    Http::assertSent(fn (Request $request) => $request->url() === Jev::URL
        && $request->hasHeader('Authorization', 'Bearer sk-or-system')
        && $request['model'] === 'typesafe/jev-1.13'
        && $request['state']['user_request'] === 'vite counter'
        && $request['state']['agent_actions'] === ['Creating main.js']
        && $request['state']['agent_reply'] === 'The preview shows a counter.'
        && $request['state']['requirements_file'] === '(missing)'
        && $request['questions']['changed_app']['type'] === 'noul');

    // The turn that request starts isn't checked again.
    Queue::fake([CheckRequirementsKept::class]);
    CheckRequirementsKept::afterTurn($this->project);
    Queue::assertNotPushed(CheckRequirementsKept::class);
})->group('TEST-007');

test('requirements without tests are named in the request', function () {
    Queue::fake([RunAgentTask::class]);
    ($this->workspace)("# Requirements\n\n### REQ-001: Count\n\n### REQ-002: Reset\n", ['REQ-001']);
    ($this->jev)(changed: 0.9);

    ($this->check)();

    expect(($this->latest)()->content)
        ->toContain("didn't write and run browser tests for it")
        ->not->toContain('record it')
        ->toContain('REQ-002 has no tests yet');
})->group('TEST-007');

test('nothing is asked when the turn kept them, didn\'t change the app, or Jev fails', function (Closure $setUp) {
    $setUp->call($this);
    $count = $this->project->messages()->count();

    ($this->check)();

    expect($this->project->messages()->count())->toBe($count);
})->with([
    'kept' => [function () {
        ($this->workspace)("### REQ-001: Count\n", ['REQ-001']);
        ($this->jev)(changed: 0.9);
    }],
    'no change' => [function () {
        ($this->workspace)(null);
        ($this->jev)(changed: 0.1, recorded: 0, tested: 0);
    }],
    'jev fails' => [function () {
        ($this->workspace)(null);
        Http::fake([Jev::URL => Http::response(['error' => 'down'], 500)]);
    }],
    'jev leaves a question out' => [function () {
        ($this->workspace)(null);
        Http::fake([Jev::URL => Http::response(['answers' => ['changed_app' => ['noul' => 0.9]]])]);
    }],
    'another turn since' => [function () {
        ($this->workspace)(null);
        ($this->jev)(changed: 0.9, recorded: 0, tested: 0);
        $this->project->messages()->create(['role' => MessageRole::User, 'content' => 'make it blue']);
    }],
])->group('TEST-007');

test('the owner\'s OpenRouter key is used before the platform\'s, and without either there\'s no check', function () {
    Queue::fake([CheckRequirementsKept::class]);
    $jev = app(Jev::class);

    expect($jev->endpointFor($this->project)->openRouterKey)->toBe('sk-or-system');

    AgentConnection::factory()->for($this->project->user)->provider(AgentProvider::OpenRouter)->create(['credential' => 'sk-or-owner']);
    expect($jev->endpointFor($this->project->fresh())->openRouterKey)->toBe('sk-or-owner');

    $this->project->user->agentConnections()->delete();
    config(['services.openrouter.key' => null]);
    CheckRequirementsKept::afterTurn($this->project->fresh());
    Queue::assertNotPushed(CheckRequirementsKept::class);

    config(['services.openrouter.key' => 'sk-or-system']);
    $this->project->update(['track_requirements' => false]);
    CheckRequirementsKept::afterTurn($this->project->fresh());
    Queue::assertNotPushed(CheckRequirementsKept::class);

    $this->project->update(['track_requirements' => true]);
    CheckRequirementsKept::afterTurn($this->project->fresh());
    Queue::assertPushed(CheckRequirementsKept::class);
})->group('TEST-007');
