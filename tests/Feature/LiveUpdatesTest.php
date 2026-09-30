<?php

use App\Enums\SandboxStatus;
use App\Events\ProjectFilesChanged;
use App\Events\ProjectUpdated;
use App\Models\AgentConnection;
use App\Models\Message;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;

/**
 * Broadcast through Reverb (nothing is sent: channel authorization is signed locally).
 */
function useReverb(array $browser = []): void
{
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
        'broadcasting.connections.reverb.browser' => [
            'host' => 'localhost',
            'port' => 8080,
            'scheme' => 'http',
            ...$browser,
        ],
    ]);

    // Channels are registered on the broadcaster that was the default when the app booted.
    require base_path('routes/channels.php');
}

test('changes to what the project page shows tell its open pages', function () {
    $project = Project::factory()->create();
    Event::fake([ProjectUpdated::class]);

    Message::factory()->for($project)->create();
    $sandbox = Sandbox::factory()->for($project)->create();
    $sandbox->update(['status' => SandboxStatus::Failed]);
    $project->update(['name' => 'Renamed']);

    Event::assertDispatchedTimes(ProjectUpdated::class, 4);
    Event::assertDispatched(ProjectUpdated::class, fn (ProjectUpdated $event) => $event->projectId === $project->id
        && $event->broadcastOn()[0]->name === "private-project.{$project->id}");
})->group('LIVE-001');

test('a task\'s changes are its project\'s', function () {
    $task = Task::factory()->create();
    Event::fake([ProjectUpdated::class]);

    $task->update(['title' => 'Something else']);

    Event::assertDispatched(ProjectUpdated::class, fn (ProjectUpdated $event) => $event->projectId === $task->project_id);
})->group('LIVE-001');

test('changes the page doesn\'t show don\'t tell it', function () {
    $project = Project::factory()->create();
    $sandbox = Sandbox::factory()->for($project)->create();
    Event::fake([ProjectUpdated::class]);

    $project->update(['read_at' => now()]);
    $sandbox->increment('files_version');
    $sandbox->issueEventsToken();

    Event::assertNotDispatched(ProjectUpdated::class);
})->group('LIVE-001');

test('reported file changes are pushed to the project with the sandbox they happened in', function () {
    $task = Task::factory()->create();
    $copy = Sandbox::factory()->for($task->project)->create(['task_id' => $task->id, 'files_version' => 4]);
    Event::fake([ProjectFilesChanged::class]);

    $this->postJson(URL::signedRoute('sandbox-events.files', $copy, absolute: false))->assertOk();

    Event::assertDispatched(ProjectFilesChanged::class, fn (ProjectFilesChanged $event) => $event->broadcastOn()[0]->name === "private-project.{$task->project_id}"
        && $event->broadcastWith() === ['task' => $task->id, 'version' => 5]);
})->group('LIVE-001', 'FILE-004');

test('people who can see the project can listen on its channel', function () {
    useReverb();
    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();

    $this->actingAs($user)
        ->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-project.{$project->id}"])
        ->assertOk()
        ->assertJsonStructure(['auth']);
})->group('LIVE-001');

test('others can\'t listen on a project\'s channel', function () {
    useReverb();
    $project = Project::factory()->create();

    $this->actingAs(User::factory()->has(AgentConnection::factory())->create())
        ->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-project.{$project->id}"])
        ->assertForbidden();
})->group('LIVE-001');

test('pages learn where to connect at runtime, or that there are no live updates', function () {
    $user = User::factory()->has(AgentConnection::factory())->create();

    $this->actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('realtime', null));

    // Servers: browsers come in on the app's own address.
    useReverb(['host' => '', 'port' => 443, 'scheme' => 'https']);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('realtime', ['key' => 'test-key', 'host' => null, 'port' => 443, 'scheme' => 'https']));
})->group('LIVE-001');

test('an unreachable Reverb doesn\'t break the change that broadcast', function () {
    useReverb();
    config(['broadcasting.connections.reverb.options' => ['host' => '127.0.0.1', 'port' => 1, 'scheme' => 'http', 'useTLS' => false]]);
    $project = Project::factory()->create();

    $message = Message::factory()->for($project)->create();

    expect($message->exists)->toBeTrue();
})->group('LIVE-001');
