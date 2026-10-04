<?php

use App\Enums\PublishStatus;
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
use App\Sandbox\WorkspaceDemo;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->provider = new FakeSandboxProvider;
    app()->instance(SandboxProvider::class, $this->provider);

    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->project = Project::factory()->for($this->user)->create(['name' => 'Todo List']);
    Sandbox::factory()->for($this->project)->create(['external_id' => 'ctr-1']);
});

/** A storyboard as the panel sends it. */
function storyboard(array $changes = []): array
{
    return [
        'title' => 'Todos',
        'tagline' => 'The simplest todo list',
        'accent' => '#4338ca',
        'url' => null,
        'scenes' => [
            ['file' => 'tests/e2e/todo.spec.ts', 'title' => 'Todos › User should be able to add a todo', 'caption' => 'Add a todo'],
        ],
        ...$changes,
    ];
}

/** The storyboard the sandbox was asked to write (WorkspaceFiles sends it in chunks, through the environment). */
function writtenStoryboard(FakeSandboxProvider $provider): array
{
    $content = collect($provider->executed)
        ->map(fn (array $exec) => $exec['env']['APP_CONTENT'] ?? null)
        ->filter()
        ->implode('');

    return json_decode($content, true);
}

test('shows the storyboard, the latest video and the render going', function () {
    $this->provider->execUsing = fn () => new ExecResult(0, json_encode([
        'running' => true, 'phase' => 'rendering', 'progress' => 0.42, 'started_at' => '2026-10-03T18:00:00Z', 'finished_at' => null, 'error' => null,
        'video' => ['path' => WorkspaceDemo::VIDEO, 'size' => 1234, 'rendered_at' => '2026-10-03T17:00:00Z', 'duration_ms' => 12000, 'scenes' => 1],
        'storyboard' => storyboard(), 'storyboard_error' => null,
    ]));

    $this->actingAs($this->user)
        ->getJson(route('projects.demo.show', $this->project))
        ->assertOk()
        ->assertJsonPath('running', true)
        ->assertJsonPath('phase', 'rendering')
        ->assertJsonPath('progress', 0.42)
        ->assertJsonPath('video.duration_ms', 12000)
        ->assertJsonPath('storyboard.scenes.0.caption', 'Add a todo');

    expect($this->provider->executed[0]['command'])->toBe(['node', '/opt/onedrop/demo.mjs', 'status']);
})->group('DEMO-001');

test('an older sandbox without the demo script says it will update', function () {
    $this->provider->execUsing = fn () => new ExecResult(1, '', "Error: Cannot find module '/opt/onedrop/demo.mjs'");

    $this->actingAs($this->user)
        ->getJson(route('projects.demo.show', $this->project))
        ->assertStatus(502)
        ->assertJson(['message' => "This sandbox can't make demos yet. It updates itself the next time the agent runs."]);
})->group('DEMO-001');

test('says when the sandbox isn\'t running', function () {
    $this->project->sandbox->update(['status' => SandboxStatus::Paused]);

    $this->actingAs($this->user)
        ->getJson(route('projects.demo.show', $this->project))
        ->assertConflict()
        ->assertJson(['message' => "The project's sandbox isn't running."]);
})->group('DEMO-001');

test('saves the storyboard to .onedrop/demo.json', function () {
    $this->actingAs($this->user)
        ->putJson(route('projects.demo.update', $this->project), storyboard())
        ->assertOk();

    expect(writtenStoryboard($this->provider))->toBe([
        'title' => 'Todos',
        'tagline' => 'The simplest todo list',
        'accent' => '#4338ca',
        'scenes' => [
            ['file' => 'tests/e2e/todo.spec.ts', 'title' => 'Todos › User should be able to add a todo', 'caption' => 'Add a todo'],
        ],
    ])->and(collect($this->provider->executed)->pluck('command')->flatten()->contains('/workspace/.onedrop/demo.json'))->toBeTrue();
})->group('DEMO-001');

test('refuses scenes that aren\'t the app\'s test files', function (array $scene) {
    $this->actingAs($this->user)
        ->putJson(route('projects.demo.update', $this->project), storyboard(['scenes' => [$scene]]))
        ->assertUnprocessable();

    expect($this->provider->executed)->toBe([]);
})->with([
    'outside tests/e2e' => [['file' => '../.env', 'title' => 'x']],
    'climbing out' => [['file' => 'tests/e2e/../../x.spec.ts', 'title' => 'x']],
    'not a test file' => [['file' => 'tests/e2e/helpers.ts', 'title' => 'x']],
    'no title' => [['file' => 'tests/e2e/todo.spec.ts']],
])->group('DEMO-001');

test('other people can\'t see or change the demo', function () {
    $stranger = User::factory()->has(AgentConnection::factory())->create();

    $this->actingAs($stranger)->getJson(route('projects.demo.show', $this->project))->assertForbidden();
    $this->actingAs($stranger)->getJson(route('projects.demo.video', $this->project))->assertForbidden();
    $this->actingAs($stranger)->putJson(route('projects.demo.update', $this->project), storyboard())->assertForbidden();
    $this->actingAs($stranger)->postJson(route('projects.demo.render', $this->project))->assertForbidden();
    $this->actingAs($stranger)->postJson(route('projects.demo.write', $this->project))->assertForbidden();

    expect($this->provider->executed)->toBe([]);
})->group('DEMO-001', 'DEMO-002');

test('saves the storyboard, then renders in the background with the published address', function () {
    $this->project->update(['publish_status' => PublishStatus::Live, 'published_url' => 'https://todos.example.com']);

    $this->actingAs($this->user)
        ->postJson(route('projects.demo.render', $this->project), ['storyboard' => storyboard()])
        ->assertStatus(202);

    expect(writtenStoryboard($this->provider)['title'])->toBe('Todos')
        ->and(collect($this->provider->executed)->last()['command'])
        ->toBe(['node', '/opt/onedrop/demo.mjs', 'render', '--background', '--url', 'https://todos.example.com']);
})->group('DEMO-002');

test('renders without an address when the app isn\'t published', function () {
    $this->actingAs($this->user)
        ->postJson(route('projects.demo.render', $this->project))
        ->assertStatus(202);

    expect($this->provider->executed)->toHaveCount(1)
        ->and($this->provider->executed[0]['command'])->toBe(['node', '/opt/onedrop/demo.mjs', 'render', '--background']);
})->group('DEMO-002');

test('says when a demo is already rendering', function () {
    $this->provider->execUsing = fn () => new ExecResult(4, '', 'A demo is already rendering.');

    $this->actingAs($this->user)
        ->postJson(route('projects.demo.render', $this->project))
        ->assertConflict()
        ->assertJson(['message' => 'A demo is already rendering.']);
})->group('DEMO-002');

test('serves the latest video to watch and to download', function () {
    $this->provider->execUsing = fn () => new ExecResult(0, base64_encode('mp4-bytes'));

    $this->actingAs($this->user)
        ->get(route('projects.demo.video', $this->project))
        ->assertOk()
        ->assertHeader('Content-Type', 'video/mp4')
        ->assertHeaderMissing('Content-Disposition');

    expect($this->provider->executed[0]['command'])->toBe(['base64', '-w0', '--', '/workspace/.onedrop/demo/demo.mp4']);

    $this->actingAs($this->user)
        ->get(route('projects.demo.video', [$this->project, 'download' => 1]))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="todo-list-demo.mp4"');
})->group('DEMO-002');

test('asks the agent to write the storyboard and render it', function () {
    Queue::fake();
    app()->instance(AgentRunner::class, new FakeAgentRunner);

    $this->actingAs($this->user)
        ->postJson(route('projects.demo.write', $this->project))
        ->assertOk();

    expect($this->project->messages()->latest('id')->value('content'))->toBe(WorkspaceDemo::WRITE_REQUEST)
        ->and(WorkspaceDemo::WRITE_REQUEST)->toContain('/opt/onedrop/guides/demo.md');
})->group('DEMO-003');
