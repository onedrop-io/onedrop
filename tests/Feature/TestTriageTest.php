<?php

use App\Enums\MessageRole;
use App\Jobs\TriageFailingTests;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Agents\Jev;
use App\Sandbox\ExecResult;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use App\Sandbox\WorkspaceFiles;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['services.openrouter.key' => 'sk-or-system']);
    Http::preventStrayRequests();

    $this->provider = new FakeSandboxProvider;
    app()->instance(SandboxProvider::class, $this->provider);

    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->project = Project::factory()->for($this->user)->create();
    Sandbox::factory()->for($this->project)->create(['external_id' => 'ctr-1']);
    $this->project->messages()->create(['role' => MessageRole::User, 'content' => 'Rename the Add button to Create']);
    $this->project->messages()->create(['role' => MessageRole::Activity, 'content' => 'Editing src/TodoForm.jsx']);

    /** tests.mjs's state: one passing test and one that failed in the run at $ranAt. */
    $this->ranAt = '2026-09-30T18:00:01.000Z';
    $this->provider->execUsing = fn (array $command) => match ($command[0]) {
        'node' => new ExecResult(0, json_encode(['running' => false, 'tests' => [
            ['id' => 'ok-1', 'file' => 'tests/e2e/todo.spec.ts', 'line' => 1, 'title' => 'User should see the list', 'tags' => [], 'result' => ['status' => 'passed', 'duration' => 100, 'error' => null, 'ran_at' => $this->ranAt]],
            ['id' => 'bad-1', 'file' => 'tests/e2e/todo.spec.ts', 'line' => 4, 'title' => 'User should be able to add a todo', 'tags' => ['REQ-001'], 'result' => [
                'status' => 'failed', 'duration' => 30000, 'error' => "locator.click: Test timeout of 30000ms exceeded.\n  - waiting for getByRole('button', { name: 'Add' })",
                'ran_at' => $this->ranAt, 'steps' => [['title' => 'Click', 'subtitle' => "getByRole('button', { name: 'Add' })", 'line' => 7]],
            ]],
        ]])),
        'head' => new ExecResult(0, "test('User should see the list', async ({ page }) => {\n});\n\ntest('User should be able to add a todo', async ({ page }) => {\n  await page.goto('/');\n  await page.getByRole('button', { name: 'Add' }).click();\n});\n\ntest('another', async () => {});\n"),
        default => new ExecResult(0, ''),
    };

    $this->jev = fn (string $choice, float $probability) => Http::fake([Jev::URL => Http::response(['answers' => [
        'test_0' => ['type' => 'choice', 'choice' => $choice, 'confidence' => $probability, 'probabilities' => ['app' => 0, 'test' => 0, 'flaky' => 0, $choice => $probability]],
    ]])]);

    $this->tests = fn () => $this->actingAs($this->user)->getJson(route('projects.tests.index', $this->project))->assertOk();
});

test('a failing test gets Jev\'s verdict once, worked out in the background', function () {
    Queue::fake([TriageFailingTests::class]);
    ($this->jev)('test', 0.99);

    ($this->tests)()
        ->assertJsonPath('triage_pending', true)
        ->assertJsonPath('tests.1.result.triage', null)
        ->assertJsonMissingPath('tests.0.result.triage');

    // Asked once, however often the tab looks meanwhile; the status never waits on Jev.
    ($this->tests)();
    Queue::assertPushed(TriageFailingTests::class, 1);
    Http::assertNothingSent();

    Queue::pushed(TriageFailingTests::class)->first()->handle(app(Jev::class), app(WorkspaceFiles::class));

    Http::assertSent(fn (Request $request) => $request->url() === Jev::URL
        && $request->hasHeader('Authorization', 'Bearer sk-or-system')
        && $request['questions']['test_0']['type'] === 'choice'
        && array_keys($request['questions']['test_0']['criteria']) === ['app', 'test', 'flaky']
        && $request['state']['recent_user_requests'] === ['Rename the Add button to Create']
        && $request['state']['recent_agent_actions'] === ['Editing src/TodoForm.jsx']
        && $request['state']['tests']['test_0']['title'] === 'User should be able to add a todo'
        && str_contains($request['state']['tests']['test_0']['error'], "name: 'Add'")
        && $request['state']['tests']['test_0']['steps_run'] === ["Click getByRole('button', { name: 'Add' })"]
        && str_starts_with($request['state']['tests']['test_0']['source'], "test('User should be able to add a todo'")
        && ! str_contains($request['state']['tests']['test_0']['source'], 'another'));

    ($this->tests)()
        ->assertJsonPath('triage_pending', false)
        ->assertJsonPath('tests.1.result.triage', ['verdict' => 'test', 'probability' => 0.99]);
    Queue::assertPushed(TriageFailingTests::class, 1);
    Http::assertSentCount(1);

    // The test's next run is a new result, asked about again.
    $this->ranAt = '2026-09-30T19:00:00.000Z';
    ($this->tests)()->assertJsonPath('triage_pending', true);
    Queue::assertPushed(TriageFailingTests::class, 2);
})->group('TEST-008');

test('no verdict is shown when Jev isn\'t sure, fails, or leaves the test out, and it isn\'t asked again right away', function (Closure $jev) {
    $jev->call($this);

    ($this->tests)()->assertJsonPath('triage_pending', false);
    ($this->tests)()
        ->assertJsonPath('triage_pending', false)
        ->assertJsonPath('tests.1.result.triage', null);

    Http::assertSentCount(1);
})->with([
    'not sure' => [fn () => ($this->jev)('flaky', 0.4)],
    'jev fails' => [fn () => Http::fake([Jev::URL => Http::response(['error' => 'down'], 500)])],
    'left out' => [fn () => Http::fake([Jev::URL => Http::response(['answers' => []])])],
])->group('TEST-008');

test('without an OpenRouter key, failures get no verdict and Jev isn\'t asked', function () {
    Queue::fake([TriageFailingTests::class]);
    config(['services.openrouter.key' => null]);

    ($this->tests)()
        ->assertJsonMissingPath('triage_pending')
        ->assertJsonMissingPath('tests.1.result.triage');

    Queue::assertNothingPushed();
})->group('TEST-008');

test('a test\'s source is its own lines, up to the next test', function () {
    $file = "import { test } from '@playwright/test';\n\ntest('one', async () => {\n  a();\n});\n\ntest.skip('two', async () => {});\n";

    expect(TriageFailingTests::testSource($file, 3))->toBe("test('one', async () => {\n  a();\n});");
})->group('TEST-008');
