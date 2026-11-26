<?php

use App\Enums\TaskStart;
use App\Jobs\ForkTaskSandbox;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\Task;
use App\Models\User;
use App\Sandbox\Agents\AgentRunner;
use App\Sandbox\Agents\FakeAgentRunner;
use App\Sandbox\GitRemote;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    // The copy itself is made by ForkTaskSandbox, covered in Feature/TaskStartTest; here only the choice is.
    Queue::fake();
    config(['sandbox.task_copies' => true]);
    app()->instance(SandboxProvider::class, new FakeSandboxProvider);
    app()->instance(AgentRunner::class, new FakeAgentRunner);

    $remote = Mockery::mock(GitRemote::class)->makePartial();
    $remote->shouldReceive('heads')->andReturn(['default' => '2.x', 'branches' => ['2.x', 'onedrop-dev', 'feature']]);
    app()->instance(GitRemote::class, $remote);

    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->project = Project::factory()->for($this->user)->create(['name' => 'Platform', 'git_remote_url' => 'https://github.com/acme/platform.git']);
    Sandbox::factory()->for($this->project)->create(['preview_url' => null]);
    $this->actingAs($this->user);
});

test('start a task fresh from a branch another task works on, and see it gets its own branch', function () {
    $busy = Task::factory()->for($this->project)->create(['title' => 'Dark mode', 'branch' => 'onedrop-dev']);

    visit("/projects/{$this->project->id}/tasks/new")
        ->assertSee('Plan a new task')
        ->assertSeeIn('@task-start', '2.x')
        ->assertSeeIn('@task-start-note', 'A fresh clone, on its own branch off it.')
        ->click('@task-start')
        ->assertSeeIn('@task-start-menu', 'In use by “Dark mode”')
        ->click('@task-start-branch-onedrop-dev')
        ->assertSeeIn('@task-start', 'onedrop-dev')
        ->assertSeeIn('@task-start-note', '“Dark mode” works on onedrop-dev, so this task gets its own branch off it.')
        ->fill('#composer-content', 'Add a CSV export')
        ->keys('#composer-content', 'Enter')
        ->assertSeeIn('@task-title', 'Add a CSV export')
        ->assertNoJavaScriptErrors();

    expect(Task::query()->whereKeyNot($busy->id)->sole()->only('start_from', 'start_branch'))
        ->toBe(['start_from' => TaskStart::Branch, 'start_branch' => 'onedrop-dev']);
})->group('TASK-005');

test("start a task as a copy of Main's sandbox", function () {
    visit("/projects/{$this->project->id}/tasks/new")
        ->click('@task-start')
        ->click('@task-start-main')
        ->assertSeeIn('@task-start', 'Main')
        ->assertMissing('@task-start-note')
        ->fill('#composer-content', 'Fix the footer')
        ->keys('#composer-content', 'Enter')
        ->assertSeeIn('@task-title', 'Fix the footer')
        ->assertNoJavaScriptErrors();

    expect(Task::sole()->start_from)->toBe(TaskStart::Main);
})->group('TASK-005');

test('check out a branch as a task without writing a message', function () {
    visit("/projects/{$this->project->id}/tasks/new")
        ->assertSeeIn('@sandbox-message', 'This task gets its own copy of the app when it starts')
        ->click('@task-start')
        ->click('@task-start-branch-onedrop-dev')
        ->assertSeeIn('@task-check-out', 'Check out onedrop-dev')
        ->click('@task-check-out')
        ->assertSeeIn('@task-title', 'onedrop-dev')
        ->assertNoJavaScriptErrors();

    expect(Task::sole()->only('title', 'start_from', 'start_branch'))
        ->toBe(['title' => 'onedrop-dev', 'start_from' => TaskStart::Branch, 'start_branch' => 'onedrop-dev']);
    Queue::assertPushed(ForkTaskSandbox::class, fn (ForkTaskSandbox $job) => $job->checkOutOnly);
})->group('TASK-006');
