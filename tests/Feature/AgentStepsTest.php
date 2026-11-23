<?php

use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Events\AgentStreamed;
use App\Models\AgentConnection;
use App\Models\Message;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Agents\AgentRunner;
use App\Sandbox\Agents\AgentStream;
use App\Sandbox\Agents\FakeAgentRunner;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->project = Project::factory()->for($this->user)->create(['status' => ProjectStatus::Working]);
    $this->sandbox = Sandbox::factory()->for($this->project)->create();
    $this->token = $this->sandbox->issueEventsToken();
    $this->project->messages()->create(['role' => MessageRole::User, 'content' => 'Build it']);
});

/**
 * @param  list<array<string, mixed>>  $events
 */
function agentEvents(string $agent, array $events): TestResponse
{
    return test()->withToken(test()->token)->postJson(route('sandbox-events.store', test()->sandbox), ['agent' => $agent, 'events' => $events]);
}

/**
 * The project's agent steps: [line, status, detail].
 *
 * @return list<array{string, string|null, array<string, string>|null}>
 */
function steps(Project $project): array
{
    return $project->messages()->where('role', MessageRole::Activity)->get()
        ->map(fn (Message $message) => [$message->content, $message->meta['step']['status'] ?? null, $message->detail])
        ->all();
}

test('a Claude Code tool call shows as running, then done with its command, output and diff', function () {
    agentEvents('claude_code', [
        ['type' => 'assistant', 'message' => ['content' => [
            ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'Bash', 'input' => ['command' => 'npm test', 'description' => 'Run the tests']],
            ['type' => 'tool_use', 'id' => 'toolu_2', 'name' => 'Edit', 'input' => ['file_path' => '/workspace/src/App.tsx']],
        ]]],
    ])->assertOk();

    expect(steps($this->project))->toBe([
        ['Running Run the tests', 'running', ['command' => 'npm test']],
        ['Editing src/App.tsx', 'running', ['path' => 'src/App.tsx']],
    ]);

    agentEvents('claude_code', [
        ['type' => 'user', 'message' => ['content' => [['type' => 'tool_result', 'tool_use_id' => 'toolu_1', 'is_error' => false, 'content' => '3 passed']]]],
        ['type' => 'user', 'message' => ['content' => [['type' => 'tool_result', 'tool_use_id' => 'toolu_2', 'is_error' => false, 'content' => 'Updated', 'diff' => "@@ -1 +1 @@\n-a\n+b"]]]],
    ])->assertOk();

    expect(steps($this->project))->toBe([
        ['Running Run the tests', 'done', ['command' => 'npm test', 'output' => '3 passed']],
        ['Editing src/App.tsx', 'done', ['path' => 'src/App.tsx', 'output' => 'Updated', 'diff' => "@@ -1 +1 @@\n-a\n+b"]],
    ]);
})->group('AGT-017');

test('a failed tool call shows as failed with its error', function () {
    agentEvents('claude_code', [
        ['type' => 'assistant', 'message' => ['content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'Bash', 'input' => ['command' => 'npm run nope']]]]],
        ['type' => 'user', 'message' => ['content' => [['type' => 'tool_result', 'tool_use_id' => 'toolu_1', 'is_error' => true, 'content' => 'Missing script: nope']]]],
    ]);

    expect(steps($this->project))->toBe([['Running `npm run nope`', 'failed', ['command' => 'npm run nope', 'error' => 'Missing script: nope']]]);
})->group('AGT-017');

test('thinking keeps its text, and thinking right after thinking joins it', function () {
    agentEvents('claude_code', [
        ['type' => 'assistant', 'message' => ['content' => [
            ['type' => 'thinking', 'thinking' => 'First, the layout.', 'block' => 'r-1'],
            ['type' => 'thinking', 'thinking' => 'Then the colors.', 'block' => 'r-2'],
            ['type' => 'redacted_thinking'],
        ]]],
    ]);

    $thinking = $this->project->messages()->where('role', MessageRole::Activity)->sole();

    expect($thinking->content)->toBe('Thinking')
        ->and($thinking->detail)->toBe(['text' => "First, the layout.\n\nThen the colors."])
        ->and($thinking->meta)->toBe(['step' => ['kind' => 'thinking'], 'blocks' => ['r-1', 'r-2']]);
})->group('AGT-017');

test('a reply streams to open pages piece by piece, and its saved message ends the stream', function () {
    config(['broadcasting.default' => 'reverb']);
    Event::fake([AgentStreamed::class]);

    agentEvents('claude_code', [
        ['type' => 'onedrop.delta', 'block' => 'r-1', 'kind' => 'text', 'delta' => 'Hello'],
        ['type' => 'onedrop.delta', 'block' => 'r-1', 'kind' => 'text', 'delta' => ' there'],
    ]);

    expect(app(AgentStream::class)->current($this->project))->toBe(['block' => 'r-1', 'kind' => 'text', 'seq' => 2, 'text' => 'Hello there']);
    Event::assertDispatched(AgentStreamed::class, fn (AgentStreamed $event) => $event->broadcastWith() === ['chat' => 'main', 'block' => 'r-1', 'kind' => 'text', 'seq' => 2, 'delta' => ' there']
        && $event->broadcastOn()[0]->name === "private-project.{$this->project->id}");

    agentEvents('claude_code', [
        ['type' => 'assistant', 'message' => ['content' => [['type' => 'text', 'text' => 'Hello there!', 'block' => 'r-1']]]],
    ]);

    expect(app(AgentStream::class)->current($this->project))->toBeNull()
        ->and($this->project->messages()->where('role', MessageRole::Assistant)->sole()->meta)->toBe(['blocks' => ['r-1']]);
})->group('AGT-017');

test('without Reverb the reply so far is kept for polling pages but not broadcast', function () {
    Event::fake([AgentStreamed::class]);

    agentEvents('opencode', [['type' => 'onedrop.delta', 'block' => 'prt_1', 'kind' => 'thinking', 'delta' => 'Hmm']]);

    expect(app(AgentStream::class)->current($this->project)['text'])->toBe('Hmm');
    Event::assertNotDispatched(AgentStreamed::class);
})->group('AGT-017');

test('the chat shows the reply so far and each step without its details, which load on their own', function () {
    $step = $this->project->messages()->create([
        'role' => MessageRole::Activity,
        'content' => 'Running `ls`',
        'meta' => ['step' => ['kind' => 'tool', 'id' => 't-1', 'status' => 'running']],
        'detail' => ['command' => 'ls', 'output' => 'app'],
    ]);
    app(AgentStream::class)->append($this->project, 'r-9', 'text', 'Almost');

    $this->actingAs($this->user)->get(route('projects.show', $this->project))
        ->assertInertia(fn ($page) => $page
            ->where('stream', ['block' => 'r-9', 'kind' => 'text', 'seq' => 1, 'text' => 'Almost'])
            ->where('messages.1.step', ['kind' => 'tool', 'status' => 'running', 'expandable' => true])
            ->missing('messages.1.detail'));

    $this->getJson(route('projects.messages.detail', [$this->project, $step]))
        ->assertOk()
        ->assertExactJson(['command' => 'ls', 'output' => 'app']);
})->group('AGT-017');

test('only people who can see the project see a step\'s details', function () {
    $step = $this->project->messages()->create(['role' => MessageRole::Activity, 'content' => 'Running `ls`', 'detail' => ['output' => 'secret plans']]);
    $plain = $this->project->messages()->create(['role' => MessageRole::Activity, 'content' => 'Thinking']);
    $elsewhere = Project::factory()->for($this->user)->create();

    $this->actingAs(User::factory()->has(AgentConnection::factory())->create())->getJson(route('projects.messages.detail', [$this->project, $step]))->assertForbidden();
    $this->actingAs($this->user)->getJson(route('projects.messages.detail', [$elsewhere, $step]))->assertNotFound();
    $this->actingAs($this->user)->getJson(route('projects.messages.detail', [$this->project, $plain]))->assertNotFound();
})->group('AGT-017');

test('an OpenCode tool call reported as it starts and as it finishes is one step', function () {
    $tool = fn (string $status, array $state = []) => ['type' => 'tool_use', 'part' => ['id' => 'prt_1', 'callID' => 'call_1', 'tool' => 'edit', 'state' => [
        'status' => $status, 'input' => ['filePath' => '/workspace/app.js'], ...$state,
    ]]];

    agentEvents('opencode', [$tool('running')]);
    expect(steps($this->project))->toBe([['Editing app.js', 'running', ['path' => 'app.js']]]);

    agentEvents('opencode', [$tool('completed', ['output' => 'Edit applied', 'metadata' => ['diff' => "@@ -1 +1 @@\n-x\n+y"]])]);
    expect(steps($this->project))->toBe([['Editing app.js', 'done', ['path' => 'app.js', 'output' => 'Edit applied', 'diff' => "@@ -1 +1 @@\n-x\n+y"]]]);
})->group('AGT-017');

test('Codex commands and file changes show their output and diffs, matched within the turn', function () {
    // An earlier turn's step with the same id (older forwarders' Codex numbered items afresh each run).
    $earlier = $this->project->messages()->create(['role' => MessageRole::Activity, 'content' => 'Running `ls`', 'meta' => ['step' => ['kind' => 'tool', 'id' => 'item_1', 'status' => 'done']]]);
    $this->project->messages()->create(['role' => MessageRole::User, 'content' => 'Again']);

    agentEvents('codex', [
        ['type' => 'item.started', 'item' => ['id' => 'item_1', 'type' => 'command_execution', 'command' => "/bin/bash -lc 'npm test'", 'status' => 'in_progress']],
        ['type' => 'item.completed', 'item' => ['id' => 'item_1', 'type' => 'command_execution', 'command' => "/bin/bash -lc 'npm test'", 'aggregated_output' => '1 failed', 'exit_code' => 1, 'status' => 'failed']],
        ['type' => 'item.completed', 'item' => ['id' => 'item_2', 'type' => 'file_change', 'status' => 'completed', 'changes' => [
            ['path' => '/workspace/a.txt', 'kind' => 'update', 'diff' => "@@ -1 +1 @@\n-a\n+b"],
        ]]],
    ]);

    expect(array_slice(steps($this->project), 1))->toBe([
        ['Running `npm test`', 'failed', ['command' => 'npm test', 'output' => '1 failed']],
        ['Editing a.txt', 'done', ['path' => 'a.txt', 'diff' => "@@ -1 +1 @@\n-a\n+b"]],
    ])
        ->and($earlier->fresh()->detail)->toBeNull();
})->group('AGT-017');

test('steps still running when the run ends stop showing as running', function (Closure $end, string $status) {
    app()->instance(AgentRunner::class, new FakeAgentRunner);
    agentEvents('claude_code', [
        ['type' => 'assistant', 'message' => ['content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'Bash', 'input' => ['command' => 'npm run dev']]]]],
        ['type' => 'onedrop.delta', 'block' => 'r-1', 'kind' => 'text', 'delta' => 'Starting'],
    ]);

    $end();

    expect(steps($this->project)[0][1])->toBe($status)
        ->and(app(AgentStream::class)->current($this->project))->toBeNull();
})->with([
    'finished' => [fn () => agentEvents('claude_code', [['type' => 'onedrop.exit', 'code' => 0]]), 'done'],
    'stopped' => [fn () => test()->actingAs(test()->user)->post(route('projects.agent.stop', test()->project)), 'stopped'],
])->group('AGT-017');
