<?php

use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\Sandbox;
use App\Sandbox\Agents\OpenCodeEvents;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->project = Project::factory()->create(['status' => ProjectStatus::Working]);
    $this->sandbox = Sandbox::factory()->for($this->project)->create();
    $this->token = $this->sandbox->issueEventsToken();
});

/**
 * @param  list<array<string, mixed>>  $events
 */
function sendEvents(Sandbox $sandbox, ?string $token, array $events): TestResponse
{
    return test()->withToken((string) $token)->postJson(route('sandbox-events.store', $sandbox), ['events' => $events]);
}

test('events without the sandbox token are rejected', function (?string $token) {
    sendEvents($this->sandbox, $token, [['type' => 'text', 'part' => ['text' => 'hi']]])->assertUnauthorized();

    expect($this->project->messages()->count())->toBe(0);
})->with(['missing' => [null], 'wrong' => ['nope'], 'another sandbox' => [fn () => Sandbox::factory()->create()->issueEventsToken()]])->group('AGT-001');

test('a full agent run becomes chat messages in order', function () {
    sendEvents($this->sandbox, $this->token, [
        ['type' => 'onedrop.start'],
        ['type' => 'step_start', 'sessionID' => 'ses_1', 'part' => ['type' => 'step-start']],
        ['type' => 'reasoning', 'sessionID' => 'ses_1', 'part' => ['type' => 'reasoning', 'text' => 'hmm']],
        ['type' => 'text', 'sessionID' => 'ses_1', 'part' => ['type' => 'text', 'text' => "I'll build a timer."]],
        ['type' => 'tool_use', 'sessionID' => 'ses_1', 'part' => ['type' => 'tool', 'tool' => 'write', 'state' => ['status' => 'completed', 'input' => ['filePath' => '/workspace/src/App.tsx']]]],
        ['type' => 'tool_use', 'sessionID' => 'ses_1', 'part' => ['type' => 'tool', 'tool' => 'bash', 'state' => ['status' => 'completed', 'input' => ['command' => 'npm install', 'description' => 'Install dependencies']]]],
        ['type' => 'step_finish', 'sessionID' => 'ses_1', 'part' => ['type' => 'step-finish']],
        ['type' => 'text', 'sessionID' => 'ses_1', 'part' => ['type' => 'text', 'text' => 'Done! Press Start to begin timing.']],
        ['type' => 'onedrop.exit', 'code' => 0, 'stderr' => ''],
    ])->assertOk();

    $this->project->refresh();

    expect($this->project->messages->map(fn ($m) => [$m->role, $m->content])->all())->toBe([
        [MessageRole::Activity, 'Thinking'],
        [MessageRole::Assistant, "I'll build a timer."],
        [MessageRole::Activity, 'Creating src/App.tsx'],
        [MessageRole::Activity, 'Running Install dependencies'],
        [MessageRole::Assistant, 'Done! Press Start to begin timing.'],
    ])
        ->and($this->project->agent_session_id)->toBe('ses_1')
        ->and($this->project->status)->toBe(ProjectStatus::Idle);
})->group('AGT-001');

test('the agent shows as working as soon as it starts', function () {
    $this->project->update(['status' => ProjectStatus::Idle]);

    sendEvents($this->sandbox, $this->token, [['type' => 'onedrop.start']]);

    expect($this->project->fresh()->status)->toBe(ProjectStatus::Working);
})->group('AGT-001');

test('repeated thinking lines collapse into one', function () {
    sendEvents($this->sandbox, $this->token, [
        ['type' => 'reasoning', 'part' => ['text' => 'a']],
        ['type' => 'reasoning', 'part' => ['text' => 'b']],
    ]);

    expect($this->project->messages()->count())->toBe(1);
})->group('AGT-001');

test('agent errors and failed exits are explained', function (array $event, string $expected) {
    sendEvents($this->sandbox, $this->token, [$event, ['type' => 'onedrop.exit', 'code' => 0]]);

    expect($this->project->messages()->sole()->content)->toContain($expected)
        ->and($this->project->fresh()->status)->toBe(ProjectStatus::Idle);
})->with([
    'api error' => [['type' => 'error', 'error' => ['name' => 'APIError', 'data' => ['message' => 'Rate limited']]], 'Something went wrong: Rate limited'],
    'low balance' => [['type' => 'error', 'error' => ['data' => ['message' => 'This request would exceed your available credits given your current in-flight requests.']]], 'Your AI provider says your balance is too low'],
    'bad key' => [['type' => 'onedrop.exit', 'code' => 1, 'stderr' => "boom\nError: 401 Unauthorized"], 'rejected the key'],
    'no credits' => [['type' => 'onedrop.exit', 'code' => 1, 'stderr' => '402 insufficient credits'], 'out of credits'],
    'other crash' => [['type' => 'onedrop.exit', 'code' => 1, 'stderr' => "trace\nSegfault"], 'Error: Segfault'],
    'crash banner skipped' => [['type' => 'onedrop.exit', 'code' => 1, 'stderr' => "EACCES: permission denied, mkdir '/home/sandbox/.local/state'\n    code: \"EACCES\"\n\nBun v1.3.14 (Linux arm64)"], "Error: EACCES: permission denied, mkdir '/home/sandbox/.local/state'"],
])->group('AGT-001');

test('tool lines read naturally', function (array $part, string $line) {
    expect(app(OpenCodeEvents::class)->describeTool($part))->toBe($line);
})->with([
    'edit' => [['tool' => 'edit', 'state' => ['status' => 'completed', 'input' => ['filePath' => '/workspace/src/main.tsx']]], 'Editing src/main.tsx'],
    'read' => [['tool' => 'read', 'state' => ['input' => ['filePath' => '/workspace/package.json']]], 'Reading package.json'],
    'bash without description' => [['tool' => 'bash', 'state' => ['input' => ['command' => 'npm run build']]], 'Running `npm run build`'],
    'failed tool' => [['tool' => 'write', 'state' => ['status' => 'error', 'input' => ['filePath' => '/workspace/a.txt']]], 'Creating a.txt (failed)'],
    'search' => [['tool' => 'grep', 'state' => []], 'Looking through the code'],
    'unknown' => [['tool' => 'mystery', 'state' => []], 'Using mystery'],
])->group('AGT-001');

test('agent events count as use of the sandbox, so it isn\'t suspended as idle', function () {
    sendEvents($this->sandbox, $this->token, [['type' => 'text', 'sessionID' => 'ses_1', 'part' => ['type' => 'text', 'text' => 'hi']]])->assertOk();

    expect($this->sandbox->fresh()->last_active_at?->isAfter(now()->subMinute()))->toBeTrue();
})->group('SBX-007');
