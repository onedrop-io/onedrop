<?php

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

/**
 * A project with two requirements in .onedrop/REQ.md and tests for them: REQ-001's passed, REQ-002's failed until
 * it's run again, when everything passes. Commands the sandbox was asked to run go in $runs.
 *
 * @param  list<list<string>>  $runs
 */
function testedProject(array &$runs): Project
{
    $requirements = "# Requirements\n\n## Home\n\n### REQ-001: Press Go\n- User should be able to press Go.\n\n### REQ-002: Greeting\n- User should see a greeting.\n";
    $result = fn (string $status, string $slug, ?string $error = null) => [
        'status' => $status, 'duration' => 1200, 'error' => $error,
        'video' => ".onedrop/tests/runs/20260930180000/{$slug}/video.webm", 'trace' => ".onedrop/tests/runs/20260930180000/{$slug}/trace.zip",
        'run' => '20260930180000', 'ran_at' => now()->subMinutes(5)->toIso8601String(),
    ];
    $state = fn (bool $fixed) => [
        'running' => false, 'started_at' => null, 'finished_at' => null, 'error' => null,
        'tests' => [
            ['id' => 't1', 'file' => 'tests/e2e/home.spec.ts', 'line' => 3, 'title' => 'User should be able to press Go', 'tags' => ['REQ-001'], 'result' => $result('passed', 'go')],
            ['id' => 't2', 'file' => 'tests/e2e/home.spec.ts', 'line' => 9, 'title' => 'User should see a greeting', 'tags' => ['REQ-002'], 'result' => $fixed ? $result('passed', 'greeting') : $result('failed', 'greeting', 'Expected "Hello", got "Goodbye"')],
        ],
    ];
    $fixed = false;

    $provider = new FakeSandboxProvider;
    $provider->execUsing = function (array $command) use (&$runs, &$fixed, $state, $requirements) {
        return match (true) {
            $command[0] === 'test' => new ExecResult(0, ''),
            $command[0] === 'head' => new ExecResult(0, $requirements),
            $command[0] === 'base64' => new ExecResult(0, base64_encode('not really a video')),
            ($command[2] ?? null) === 'status' => new ExecResult(0, json_encode($state($fixed))),
            ($command[2] ?? null) === 'run' => (function () use (&$runs, &$fixed, $command) {
                $runs[] = array_slice($command, 4);
                $fixed = true;

                return new ExecResult(0, '');
            })(),
            default => new ExecResult(0, ''),
        };
    };
    app()->instance(SandboxProvider::class, $provider);

    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['preview_url' => null, 'shell_url' => null]);
    test()->actingAs($user);

    return $project;
}

test('each requirement shows how its tests did, and opens them in the Tests tab to watch and run again', function () {
    $runs = [];
    $project = testedProject($runs);

    visit("/projects/{$project->id}?tab=requirements")
        ->assertSeeIn('@requirement-tests-REQ-001', 'Test passes')
        ->assertSeeIn('@requirement-tests-REQ-002', 'Test failing')
        ->click('@requirement-tests-REQ-002')
        ->assertVisible('@tab-tests')
        ->assertSeeIn('@tests-summary', '1 passed · 1 failed')
        ->assertSeeIn('@tests-group-REQ-002', 'REQ-002 · Greeting')
        ->assertSeeIn('@test-detail', 'User should see a greeting')
        ->assertSeeIn('@test-error', 'Expected "Hello", got "Goodbye"')
        ->assertVisible('@test-video')
        ->click('@test-run')
        ->assertSeeIn('@tests-summary', '2 passed')
        ->assertDontSee('Expected "Hello"')
        ->click('@tests-run-all')
        ->assertNoJavaScriptErrors();

    expect($runs)->toBe([['tests/e2e/home.spec.ts:9'], []]);
})->group('TEST-001', 'TEST-003');

test('the Tests tab explains itself before the agent has written any', function () {
    $provider = new FakeSandboxProvider;
    $provider->execUsing = fn (array $command) => ($command[2] ?? null) === 'status'
        ? new ExecResult(0, json_encode(['running' => false, 'started_at' => null, 'finished_at' => null, 'error' => null, 'tests' => []]))
        : new ExecResult(1, '');
    app()->instance(SandboxProvider::class, $provider);

    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['preview_url' => null, 'shell_url' => null]);
    $this->actingAs($user);

    visit("/projects/{$project->id}")
        ->click('@add-tab')
        ->click('@add-tab-tests')
        ->assertSeeIn('@tests-empty', 'No tests yet')
        ->assertNoJavaScriptErrors();
})->group('TEST-001');

test('requirements without tests can be handed to the agent to write them', function () {
    Queue::fake();
    app()->instance(AgentRunner::class, new FakeAgentRunner);
    $provider = new FakeSandboxProvider;
    $provider->execUsing = fn (array $command) => match (true) {
        $command[0] === 'test' => new ExecResult(0, ''),
        $command[0] === 'head' => new ExecResult(0, "# Requirements\n\n### REQ-001: Click counter\n- User should see the count go up.\n\n### REQ-002: Reset\n- User should be able to reset it.\n"),
        ($command[2] ?? null) === 'status' => new ExecResult(0, json_encode(['running' => false, 'started_at' => null, 'finished_at' => null, 'error' => null, 'tests' => []])),
        default => new ExecResult(0, ''),
    };
    app()->instance(SandboxProvider::class, $provider);

    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['preview_url' => null, 'shell_url' => null]);
    $this->actingAs($user);

    visit("/projects/{$project->id}?tab=tests")
        ->assertSeeIn('@tests-untested', '2 requirements have no tests yet.')
        ->click('@tests-write')
        ->assertSeeIn('@tests-untested', 'Follow along in the chat.')
        ->assertSee("Write browser tests for the requirements in .onedrop/REQ.md that don't have any yet")
        ->assertNoJavaScriptErrors();

    expect($project->messages()->where('role', 'user')->value('content'))->toBe(WorkspaceTests::WRITE_REQUEST);
})->group('TEST-002');

test('the test runner opens in its own window from the Tests tab', function () {
    $runs = [];
    $project = testedProject($runs);
    $project->sandbox->update(['preview_url' => 'http://127.0.0.1:9']);
    $provider = app(SandboxProvider::class);
    $status = $provider->execUsing;
    $provider->execUsing = fn (array $command, array $env) => ($command[2] ?? null) === 'ui'
        ? new ExecResult(0, json_encode(['token' => str_repeat('ef', 32)]))
        : $status($command, $env);

    visit("/projects/{$project->id}?tab=tests")
        ->assertSeeIn('@tests-summary', '1 passed · 1 failed')
        ->click('@tests-open-runner')
        ->wait(1)
        ->assertMissing('@tests-runner-error')
        ->assertNoJavaScriptErrors();

    expect(collect($provider->executed)->pluck('command'))->toContain(['node', '/opt/onedrop/tests.mjs', 'ui', 'start']);
})->group('TEST-004');

test('the user takes over a test after one of its steps and uses its page in the Browser tab', function () {
    $runs = [];
    $project = testedProject($runs);
    $project->sandbox->update(['preview_url' => 'http://127.0.0.1:9']);
    $provider = app(SandboxProvider::class);
    $status = $provider->execUsing;
    $checks = 0;
    $provider->execUsing = function (array $command, array $env) use ($status, &$checks) {
        if (($command[1] ?? null) === '/opt/onedrop/browser.mjs') {
            return match ($command[2]) {
                'start' => new ExecResult(0, json_encode(['token' => str_repeat('ab', 32)])),
                // Starting on the first check, then open.
                'status' => new ExecResult(0, json_encode(++$checks === 1
                    ? ['open' => false, 'starting' => true, 'error' => null]
                    : ['open' => true, 'starting' => false, 'error' => null, 'url' => 'http://127.0.0.1:8000/', 'title' => 'Home'])),
                default => new ExecResult(0, ''),
            };
        }

        if (($command[2] ?? null) === 'status') {
            $state = json_decode($status($command, $env)->output, true);
            $state['tests'][1]['result']['steps'] = [
                ['title' => 'Navigate', 'subtitle' => '/', 'line' => 10],
                ['title' => 'Fill "Ada"', 'subtitle' => "getByLabel('Name')", 'line' => 11],
                ['title' => 'Expect "toHaveText"', 'subtitle' => "getByRole('heading')", 'line' => 12],
            ];

            return new ExecResult(0, json_encode($state));
        }

        return $status($command, $env);
    };

    visit("/projects/{$project->id}?tab=tests")
        ->assertSeeIn('@test-steps', 'Fill "Ada"')
        ->click('[data-test="test-step"]:nth-child(2) [data-test="take-over"]')
        ->assertVisible('@browser-view')
        ->assertSeeIn('@browser-where', '"User should see a greeting", stopped after "Fill "Ada" getByLabel(\'Name\')"')
        ->assertAttributeContains('@browser-frame', 'src', 'http://127.0.0.1:9/__onedrop/browser/?token=')
        ->click('@browser-close')
        ->assertMissing('@browser-view')
        ->assertNoJavaScriptErrors();

    $commands = collect($provider->executed)->pluck('command');
    expect($commands)->toContain(['node', '/opt/onedrop/browser.mjs', 'start', 'tests/e2e/home.spec.ts:9', '2'])
        ->and($commands)->toContain(['node', '/opt/onedrop/browser.mjs', 'stop']);
})->group('TEST-005');
