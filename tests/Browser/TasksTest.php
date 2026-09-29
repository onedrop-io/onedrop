<?php

use App\Enums\TaskStage;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\Task;
use App\Models\User;
use App\Sandbox\Agents\AgentRunner;
use App\Sandbox\Agents\FakeAgentRunner;
use App\Sandbox\ExecResult;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;

beforeEach(function () {
    // Tasks sharing Main's sandbox (TASK-001); their own copies are covered in TaskCopyTest.
    config(['sandbox.task_copies' => false]);
    app()->instance(SandboxProvider::class, new FakeSandboxProvider);
    app()->instance(AgentRunner::class, new FakeAgentRunner);

    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->project = Project::factory()->for($this->user)->create(['name' => 'Time Tracker']);
    Sandbox::factory()->for($this->project)->create(['preview_url' => null]);
    $this->actingAs($this->user);
});

test('open a project, start a task with a fresh agent, and work it on the board', function () {
    $page = visit("/projects/{$this->project->id}")
        ->click('@project-menu')
        ->click('@project-menu-open')
        ->assertVisible('@open-project-back')
        ->assertVisible('@open-project-board')
        ->click('@open-project-new-task')
        ->assertSee('Plan a new task')
        ->fill('#composer-content', 'Add a dark mode toggle')
        ->keys('#composer-content', 'Enter')
        ->assertSeeIn('@task-title', 'Add a dark mode toggle')
        ->assertSee('placeholder agent')
        ->assertSeeIn('@open-project-task', 'Add a dark mode toggle');

    $task = Task::sole();
    expect($task->stage)->toBe(TaskStage::Review)
        ->and($this->project->messages()->count())->toBe(0);

    $page->click('@open-project-board')
        ->assertSeeIn('@board-column-review', 'Add a dark mode toggle')
        ->click('@board-add-card')
        ->fill('@board-card-title', 'Add a footer')
        ->fill('@board-card-notes', 'With links to the socials')
        ->click('@board-card-save')
        ->assertSeeIn('@board-column-todo', 'Add a footer')
        ->click('@board-card-start')
        ->assertDontSeeIn('@board-column-todo', 'Add a footer')
        ->assertSeeIn('@board-column-review', 'Add a footer');

    $footer = Task::query()->where('title', 'Add a footer')->sole();
    expect($footer->messages()->first()->content)->toBe("Add a footer\n\nWith links to the socials");

    $page->click('@board-card-menu')
        ->click('@board-card-move-done')
        ->assertSeeIn('@board-column-done', 'Add a dark mode toggle')
        ->click('@open-project-back')
        ->assertMissing('@open-project-back')
        ->assertSee('Time Tracker')
        ->assertNoJavaScriptErrors();

    expect($task->fresh()->stage)->toBe(TaskStage::Done);
})->group('TASK-001', 'TASK-002');

test("a project's open tasks show under it in the sidebar", function () {
    Task::factory()->for($this->project)->create(['title' => 'Write the docs', 'stage' => TaskStage::InProgress]);

    visit("/projects/{$this->project->id}")
        ->assertSeeIn('@sidebar-tasks', 'Write the docs')
        ->click('@sidebar-task')
        ->assertSeeIn('@task-title', 'Write the docs')
        ->assertSee('Start task')
        ->assertNoJavaScriptErrors();
})->group('TASK-001');

test("a task with its own copy shows it, and brings in Main's work", function () {
    config(['sandbox.task_copies' => true]);
    $provider = new FakeSandboxProvider;
    $provider->execUsing = fn (array $command) => new ExecResult(0, '');
    app()->instance(SandboxProvider::class, $provider);
    $this->project->sandbox->update(['external_id' => 'main-1']);
    $task = Task::factory()->for($this->project)->create(['title' => 'Dark mode', 'stage' => TaskStage::Review]);
    $task->sandbox()->create(['provider' => 'fake', 'external_id' => 'copy-1', 'status' => 'running', 'preview_url' => null]);

    visit("/projects/{$this->project->id}/tasks/{$task->id}")
        ->assertSeeIn('@task-copy-status', 'Working in its own copy of the app')
        ->assertVisible('@task-apply')
        ->click('@task-update-from-main')
        ->assertSee('Brought in the latest from Main')
        ->assertNoJavaScriptErrors();

    expect(collect($provider->executed)->where('id', 'copy-1')->pluck('command.1')->all())->toContain('merge');
})->group('TASK-003');

test('switching between Main and tasks keeps the workspace on the same tool', function () {
    $task = Task::factory()->for($this->project)->create(['title' => 'Write the docs', 'stage' => TaskStage::InProgress]);

    $page = visit("/projects/{$this->project->id}")
        ->click('@tab-tools')
        ->click('@tool-database')
        ->assertAttribute('@tool-database', 'aria-current', 'page')
        ->click('@sidebar-task')
        ->assertSeeIn('@task-title', 'Write the docs')
        ->assertAttribute('@tool-database', 'aria-current', 'page')
        ->assertNoJavaScriptErrors();

    expect($page->url())->toContain("/projects/{$this->project->id}/tasks/{$task->id}?tab=tools&tool=database");

    $page->navigate("/projects/{$this->project->id}/tasks/{$task->id}?tab=tools&tool=secrets")
        ->assertAttribute('@tool-secrets', 'aria-current', 'page');
})->group('TASK-004');
