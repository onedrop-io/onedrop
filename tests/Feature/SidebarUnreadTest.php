<?php

use App\Enums\MessageRole;
use App\Enums\TaskStage;
use App\Models\AgentConnection;
use App\Models\Message;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['sandbox.task_copies' => false]);
    Queue::fake();

    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->project = Project::factory()->for($this->user)->create(['read_at' => now()]);
    $this->task = Task::factory()->for($this->project)->create(['stage' => TaskStage::InProgress, 'read_at' => now()]);
    $this->actingAs($this->user);
    $this->travel(1)->minute();
});

/**
 * @return array<string, mixed>
 */
function sidebarProject(): array
{
    return test()->get(route('dashboard'))->inertiaProps('sidebarProjects.recent.0');
}

function reply(Project $project, ?Task $task = null): void
{
    Message::factory()->for($project)->create(['role' => MessageRole::Assistant, 'task_id' => $task?->id]);
    test()->travel(1)->minute();
}

test('a task with a reply its owner has not seen is unread, and so is its project', function () {
    reply($this->project, $this->task);

    expect(sidebarProject())
        ->unread->toBeTrue()
        ->tasks->{0}->unread->toBeTrue();

    $this->get(route('projects.tasks.show', [$this->project, $this->task]))->assertOk();

    expect(sidebarProject())
        ->unread->toBeFalse()
        ->tasks->{0}->unread->toBeFalse();
})->group('PRJ-008');

test('opening the main chat leaves an unread task unread', function () {
    reply($this->project, $this->task);
    reply($this->project);

    $this->get(route('projects.show', $this->project))->assertOk();

    expect(sidebarProject())
        ->unread->toBeTrue()
        ->tasks->{0}->unread->toBeTrue();
})->group('PRJ-008');

test('opening a task leaves an unread main chat unread', function () {
    reply($this->project);

    $this->withUnencryptedCookie('open_project', (string) $this->project->id)
        ->get(route('projects.tasks.show', [$this->project, $this->task]))
        ->assertInertia(fn ($page) => $page
            ->where('openProject.unread', true)
            ->where('openProject.tasks.0.unread', false));
})->group('PRJ-008');

test('a task nobody has replied in yet is not unread', function () {
    Task::factory()->for($this->project)->create(['stage' => TaskStage::Todo]);

    expect(collect(sidebarProject()['tasks'])->pluck('unread')->all())->toBe([false, false]);
})->group('PRJ-008');

test('reloads from a tab the owner is not looking at do not mark replies read', function () {
    reply($this->project, $this->task);

    $this->withHeader('X-Onedrop-Unseen', '1')
        ->get(route('projects.tasks.show', [$this->project, $this->task]))
        ->assertOk();

    expect(sidebarProject()['tasks'][0]['unread'])->toBeTrue();
})->group('PRJ-008');

test('marking a project read clears its tasks too', function () {
    reply($this->project, $this->task);

    $this->patch(route('projects.update', $this->project), ['unread' => false]);

    expect(sidebarProject())
        ->unread->toBeFalse()
        ->tasks->{0}->unread->toBeFalse();
})->group('PRJ-008');
