<?php

use App\Enums\ProjectStatus;
use App\Enums\TaskStage;
use App\Jobs\RunAgentTask;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    // Tasks sharing Main's sandbox (TASK-001); their own copies are covered in TaskCopyTest.
    config(['sandbox.task_copies' => false]);
    Queue::fake();

    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->project = Project::factory()->for($this->user)->create();
    $this->actingAs($this->user);
});

test('the board lists every task with its column and what its agent is doing', function () {
    $working = Task::factory()->for($this->project)->working()->create(['title' => 'Dark mode']);
    $working->messages()->create(['role' => 'user', 'content' => 'Add dark mode']);
    $working->messages()->create(['role' => 'activity', 'content' => 'Editing app.css']);
    Task::factory()->for($this->project)->create(['title' => 'Shipped', 'stage' => TaskStage::Done]);
    Task::factory()->create(['title' => 'Not mine']);

    $this->get(route('projects.board', $this->project))
        ->assertInertia(fn ($page) => $page->component('projects/board')
            ->where('project.id', $this->project->id)
            ->where('stages', [
                ['value' => 'todo', 'label' => 'To do'],
                ['value' => 'in_progress', 'label' => 'In progress'],
                ['value' => 'review', 'label' => 'Review'],
                ['value' => 'done', 'label' => 'Done'],
            ])
            ->has('tasks', 2)
            ->where('tasks.0.title', 'Dark mode')
            ->where('tasks.0.stage', 'in_progress')
            ->where('tasks.0.activity', 'Editing app.css')
            ->where('tasks.1.stage', 'done'));
})->group('TASK-002');

test('a card with just a title goes to To do without starting an agent', function () {
    $this->from(route('projects.board', $this->project))
        ->post(route('projects.tasks.store', $this->project), ['title' => 'Add a footer', 'description' => 'With links to the socials'])
        ->assertRedirect(route('projects.board', $this->project));

    $task = Task::sole();
    expect($task->title)->toBe('Add a footer')
        ->and($task->description)->toBe('With links to the socials')
        ->and($task->stage)->toBe(TaskStage::Todo)
        ->and($task->status)->toBe(ProjectStatus::Idle)
        ->and($task->messages()->count())->toBe(0);
    Queue::assertNothingPushed();
})->group('TASK-002');

test('a card needs a title', function () {
    $this->post(route('projects.tasks.store', $this->project), ['title' => ''])->assertSessionHasErrors('title');

    expect(Task::count())->toBe(0);
})->group('TASK-002');

test('starting a To do card moves it to In progress', function () {
    $task = Task::factory()->for($this->project)->create(['title' => 'Add a footer']);

    $this->post(route('projects.tasks.messages.store', [$this->project, $task]), ['content' => "Add a footer\n\nWith links"]);

    expect($task->fresh()->stage)->toBe(TaskStage::InProgress)
        ->and($task->fresh()->status)->toBe(ProjectStatus::Working);
    Queue::assertPushed(RunAgentTask::class);
})->group('TASK-002');

test('a card can be moved to another column, landing at its end', function () {
    Task::factory()->for($this->project)->create(['stage' => TaskStage::Done, 'position' => 4]);
    $task = Task::factory()->for($this->project)->create(['stage' => TaskStage::Review]);

    $this->patch(route('projects.tasks.update', [$this->project, $task]), ['stage' => 'done'])->assertRedirect();

    expect($task->fresh()->stage)->toBe(TaskStage::Done)
        ->and($task->fresh()->position)->toBe(5);

    $this->patch(route('projects.tasks.update', [$this->project, $task]), ['stage' => 'nowhere'])->assertSessionHasErrors('stage');
})->group('TASK-002');

test('a failed run leaves the card In progress', function () {
    $task = Task::factory()->for($this->project)->working()->create();
    $sandbox = $this->project->sandbox()->create(['provider' => 'fake', 'status' => 'running']);

    $this->withToken($task->issueEventsToken())
        ->postJson(route('sandbox-events.tasks.store', [$sandbox, $task]), ['events' => [['type' => 'onedrop.exit', 'code' => 1, 'stderr' => 'boom']]])
        ->assertOk();

    expect($task->fresh()->stage)->toBe(TaskStage::InProgress)
        ->and($task->fresh()->status)->toBe(ProjectStatus::Idle);
})->group('TASK-002');
