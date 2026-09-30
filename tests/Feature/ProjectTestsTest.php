<?php

use App\Enums\SandboxStatus;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Agents\AgentRunner;
use App\Sandbox\Agents\FakeAgentRunner;
use App\Sandbox\ExecResult;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use App\Sandbox\WorkspaceTests;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->provider = new FakeSandboxProvider;
    app()->instance(SandboxProvider::class, $this->provider);

    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->project = Project::factory()->for($this->user)->create();
    Sandbox::factory()->for($this->project)->create(['external_id' => 'ctr-1']);
});

/** What tests.mjs keeps: one test that passed with a recording, one not run yet. */
function testsState(bool $running = false): array
{
    return [
        'running' => $running,
        'pid' => $running ? 42 : null,
        'target' => [],
        'started_at' => '2026-09-30T18:00:00.000Z',
        'finished_at' => $running ? null : '2026-09-30T18:00:05.000Z',
        'error' => null,
        'tests' => [
            [
                'id' => 'abc-1', 'file' => 'tests/e2e/home.spec.ts', 'line' => 3, 'title' => 'Home › User should be able to press Go', 'tags' => ['REQ-001'],
                'result' => ['status' => 'passed', 'duration' => 562, 'error' => null, 'video' => '.onedrop/tests/runs/20260930180000/home-Go/video.webm', 'trace' => '.onedrop/tests/runs/20260930180000/home-Go/trace.zip', 'run' => '20260930180000', 'ran_at' => '2026-09-30T18:00:01.000Z'],
            ],
            ['id' => 'abc-2', 'file' => 'tests/e2e/home.spec.ts', 'line' => 9, 'title' => 'User should see a greeting', 'tags' => ['REQ-002'], 'result' => null],
        ],
    ];
}

test('lists the app\'s tests with their latest results', function () {
    $this->provider->execUsing = fn () => new ExecResult(0, json_encode(testsState()));

    $this->actingAs($this->user)
        ->getJson(route('projects.tests.index', $this->project))
        ->assertOk()
        ->assertJsonPath('running', false)
        ->assertJsonPath('tests.0.tags', ['REQ-001'])
        ->assertJsonPath('tests.0.result.status', 'passed')
        ->assertJsonPath('tests.1.result', null)
        ->assertJsonMissingPath('pid');

    expect($this->provider->executed[0]['command'])->toBe(['node', '/opt/onedrop/tests.mjs', 'status']);
})->group('TEST-001');

test('polling a run reads the kept state without looking for tests again', function () {
    $this->provider->execUsing = fn () => new ExecResult(0, json_encode(testsState(running: true)));

    $this->actingAs($this->user)
        ->getJson(route('projects.tests.index', [$this->project, 'cached' => 1]))
        ->assertOk()
        ->assertJsonPath('running', true);

    expect($this->provider->executed[0]['command'])->toBe(['node', '/opt/onedrop/tests.mjs', 'status', '--cached']);
})->group('TEST-001');

test('an older sandbox without the test runner says it will update', function () {
    $this->provider->execUsing = fn () => new ExecResult(1, '', "Error: Cannot find module '/opt/onedrop/tests.mjs'");

    $this->actingAs($this->user)
        ->getJson(route('projects.tests.index', $this->project))
        ->assertStatus(502)
        ->assertJson(['message' => "This sandbox can't run tests yet. It updates itself the next time the agent runs."]);
})->group('TEST-001');

test('runs all tests, a file, a test at a line, or a requirement\'s tests in the background', function (array $targets) {
    $this->actingAs($this->user)
        ->postJson(route('projects.tests.run', $this->project), ['targets' => $targets])
        ->assertStatus(202);

    expect($this->provider->executed[0]['command'])->toBe(['node', '/opt/onedrop/tests.mjs', 'run', '--background', ...$targets]);
})->with([
    'all' => [[]],
    'a file' => [['tests/e2e/sign-in.spec.ts']],
    'a test' => [['tests/e2e/admin/orders.spec.ts:12']],
    'a requirement' => [['@REQ-003']],
])->group('TEST-001');

test('refuses run targets that are not test files or tags', function (string $target) {
    $this->actingAs($this->user)
        ->postJson(route('projects.tests.run', $this->project), ['targets' => [$target]])
        ->assertUnprocessable();

    expect($this->provider->executed)->toBe([]);
})->with([
    'an option' => ['--reporter=html'],
    'outside tests/e2e' => ['../etc/passwd.spec.ts'],
    'climbing out' => ['tests/e2e/../../x.spec.ts'],
    'not a test file' => ['tests/e2e/helpers.ts'],
])->group('TEST-001');

test('says when the tests are already running', function () {
    $this->provider->execUsing = fn () => new ExecResult(4, '', 'Tests are already running.');

    $this->actingAs($this->user)
        ->postJson(route('projects.tests.run', $this->project))
        ->assertConflict()
        ->assertJson(['message' => 'The tests are already running.']);
})->group('TEST-001');

test('serves a test\'s video and trace', function () {
    $this->provider->execUsing = fn () => new ExecResult(0, base64_encode('webm-bytes'));
    $video = '.onedrop/tests/runs/20260930180000/home-Go/video.webm';

    $this->actingAs($this->user)
        ->get(route('projects.tests.recording', [$this->project, 'path' => $video]))
        ->assertOk()
        ->assertHeader('Content-Type', 'video/webm');

    expect($this->provider->executed[0]['command'])->toBe(['base64', '-w0', '--', "/workspace/{$video}"]);

    $this->actingAs($this->user)
        ->get(route('projects.tests.recording', [$this->project, 'path' => '.onedrop/tests/runs/20260930180000/home-Go/trace.zip']))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="trace.zip"');
})->group('TEST-001');

test('only serves recordings, nothing else in the project', function (string $path) {
    $this->actingAs($this->user)
        ->getJson(route('projects.tests.recording', [$this->project, 'path' => $path]))
        ->assertUnprocessable();

    expect($this->provider->executed)->toBe([]);
})->with([
    'a secret' => ['.env'],
    'climbing out' => ['.onedrop/tests/runs/1/../../../.env'],
    'another file in a run' => ['.onedrop/tests/runs/1/home-Go/error-context.md'],
])->group('TEST-001');

test('other users cannot see, run or watch the tests', function () {
    $other = User::factory()->has(AgentConnection::factory())->create();

    $this->actingAs($other)->getJson(route('projects.tests.index', $this->project))->assertForbidden();
    $this->actingAs($other)->postJson(route('projects.tests.run', $this->project))->assertForbidden();
    $this->actingAs($other)->getJson(route('projects.tests.recording', [$this->project, 'path' => '.onedrop/tests/runs/1/a/video.webm']))->assertForbidden();

    expect($this->provider->executed)->toBe([]);
})->group('TEST-001');

test('a stopped sandbox says why the tests are unavailable', function () {
    $this->project->sandbox->update(['status' => SandboxStatus::Paused]);

    $this->actingAs($this->user)
        ->getJson(route('projects.tests.index', $this->project))
        ->assertStatus(409)
        ->assertJson(['message' => "The project's sandbox isn't running."]);
})->group('TEST-001');

test('asks the agent to write the tests the requirements don\'t have yet, queued while it works', function () {
    Queue::fake();
    app()->instance(AgentRunner::class, new FakeAgentRunner);

    $this->actingAs($this->user)
        ->postJson(route('projects.tests.write', $this->project))
        ->assertOk()
        ->assertJsonPath('queued', false);

    expect($this->project->messages()->latest('id')->value('content'))->toBe(WorkspaceTests::WRITE_REQUEST)
        ->and(WorkspaceTests::WRITE_REQUEST)->toContain('/opt/onedrop/guides/tests.md');

    $this->actingAs($this->user)
        ->postJson(route('projects.tests.write', $this->project))
        ->assertOk()
        ->assertJsonPath('queued', true);

    $this->actingAs(User::factory()->has(AgentConnection::factory())->create())
        ->postJson(route('projects.tests.write', $this->project))
        ->assertForbidden();
})->group('TEST-002');

test('opens the test runner on the preview with its token, and closes it', function () {
    $token = str_repeat('ab', 32);
    $this->provider->execUsing = fn (array $command) => new ExecResult(0, $command[3] === 'start' ? json_encode(['token' => $token]) : '');
    $this->project->sandbox->update(['preview_url' => 'http://127.0.0.1:49152']);

    $this->actingAs($this->user)
        ->postJson(route('projects.tests.runner.open', $this->project))
        ->assertOk()
        ->assertExactJson(['url' => "http://127.0.0.1:49152/__onedrop/tests-ui/?onedrop_tests_ui={$token}"]);

    expect($this->provider->executed[0]['command'])->toBe(['node', '/opt/onedrop/tests.mjs', 'ui', 'start']);

    $this->actingAs($this->user)
        ->deleteJson(route('projects.tests.runner.close', $this->project))
        ->assertOk();

    expect($this->provider->executed[1]['command'])->toBe(['node', '/opt/onedrop/tests.mjs', 'ui', 'stop']);
})->group('TEST-004');

test('on a server the test runner opens through the gateway, like the preview', function () {
    config(['sandbox.gateway_domain' => 'apps.example.com']);
    $token = str_repeat('cd', 32);
    $this->provider->execUsing = fn () => new ExecResult(0, json_encode(['token' => $token]));
    $this->project->sandbox->update(['preview_url' => 'http://10.0.0.5:8000']);

    $url = $this->actingAs($this->user)
        ->postJson(route('projects.tests.runner.open', $this->project))
        ->assertOk()
        ->json('url');

    expect($url)->toBe(route('projects.gateway.open', [$this->project, 'preview', 'path' => "/__onedrop/tests-ui/?onedrop_tests_ui={$token}"]));
})->group('TEST-004');

test('says why the test runner could not open', function (int $exit, string $output, string $message) {
    $this->provider->execUsing = fn () => new ExecResult($exit, $output, 'The test runner didn\'t start: Error: port in use');
    $this->project->sandbox->update(['preview_url' => 'http://127.0.0.1:49152']);

    $this->actingAs($this->user)
        ->postJson(route('projects.tests.runner.open', $this->project))
        ->assertStatus(502)
        ->assertJson(['message' => $message]);
})->with([
    'no tests' => [2, '', 'There are no tests to open yet.'],
    'no Playwright' => [3, '', "The tests need Playwright: ask the agent to add @playwright/test to the app's dev dependencies."],
    'a bad token' => [0, json_encode(['token' => 'short']), "The test runner didn't start. The test runner didn't start: Error: port in use"],
])->group('TEST-004');

test('only people who can change the project open or close the test runner', function () {
    $viewer = User::factory()->has(AgentConnection::factory())->create();

    $this->actingAs($viewer)->postJson(route('projects.tests.runner.open', $this->project))->assertForbidden();
    $this->actingAs($viewer)->deleteJson(route('projects.tests.runner.close', $this->project))->assertForbidden();

    expect($this->provider->executed)->toBe([]);
})->group('TEST-004');
