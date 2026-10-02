<?php

use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Enums\PublishStatus;
use App\Enums\PublishVisibility;
use App\Enums\SandboxStatus;
use App\Jobs\DestroySandbox;
use App\Jobs\RegenerateProjectName;
use App\Models\AgentConnection;
use App\Models\Attachment;
use App\Models\Message;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Agents\ProjectNamer;
use App\Sandbox\ExecResult;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\Publishing\FakePublisher;
use App\Sandbox\Publishing\Publisher;
use App\Sandbox\SandboxProvider;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

beforeEach(function () {
    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->project = Project::factory()->for($this->user)->create(['name' => 'Todo App', 'read_at' => now()]);
});

/**
 * @return array{pinned: list<array<string, mixed>>, recent: list<array<string, mixed>>, archived: list<array<string, mixed>>}
 */
function sidebarProjects(User $user): array
{
    return test()->actingAs($user)->followingRedirects()->get(route('dashboard'))->inertiaProps('sidebarProjects');
}

test('renaming a project changes its name without moving it in recent', function () {
    $this->travel(1)->hour();

    $this->actingAs($this->user)
        ->patch(route('projects.update', $this->project), ['name' => '  Team   Todos '])
        ->assertRedirect();

    $this->project->refresh();
    expect($this->project->name)->toBe('Team Todos')
        ->and($this->project->updated_at->lessThan(now()->subMinutes(30)))->toBeTrue();
})->group('PRJ-003');

test('a project needs a name', function () {
    $this->actingAs($this->user)
        ->patch(route('projects.update', $this->project), ['name' => ''])
        ->assertSessionHasErrors(['name' => 'Give the project a name.']);
})->group('PRJ-003');

test('pinned projects are listed apart from recent ones', function () {
    Project::factory()->for($this->user)->create(['name' => 'Other']);

    $this->actingAs($this->user)->patch(route('projects.update', $this->project), ['pinned' => true]);

    $sidebar = sidebarProjects($this->user);
    expect(array_column($sidebar['pinned'], 'name'))->toBe(['Todo App'])
        ->and(array_column($sidebar['recent'], 'name'))->toBe(['Other']);

    $this->actingAs($this->user)->patch(route('projects.update', $this->project), ['pinned' => false]);

    expect(sidebarProjects($this->user)['pinned'])->toBe([]);
})->group('PRJ-003');

test('archived projects leave recent and can be restored', function () {
    $this->project->update(['pinned_at' => now()]);

    $this->actingAs($this->user)->patch(route('projects.update', $this->project), ['archived' => true]);

    $sidebar = sidebarProjects($this->user);
    expect($sidebar['pinned'])->toBe([])
        ->and($sidebar['recent'])->toBe([])
        ->and($sidebar['archived'][0])->toMatchArray(['name' => 'Todo App', 'archived' => true]);

    $this->actingAs($this->user)->patch(route('projects.update', $this->project), ['archived' => false]);

    expect(array_column(sidebarProjects($this->user)['pinned'], 'name'))->toBe(['Todo App']);
})->group('PRJ-003');

test('a project is unread when the agent replied since it was opened', function () {
    expect(sidebarProjects($this->user)['recent'][0]['unread'])->toBeFalse();

    $this->travel(1)->minute();
    Message::factory()->for($this->project)->create(['role' => MessageRole::Assistant]);

    expect(sidebarProjects($this->user)['recent'][0]['unread'])->toBeTrue();

    $this->travel(1)->minute();
    $this->actingAs($this->user)->get(route('projects.show', $this->project))->assertOk();

    expect(sidebarProjects($this->user)['recent'][0]['unread'])->toBeFalse();
})->group('PRJ-003');

test('an admin opening a project does not mark it read for its owner', function () {
    $this->project->update(['read_at' => null]);

    $this->actingAs(User::factory()->admin()->has(AgentConnection::factory())->create())
        ->get(route('projects.show', $this->project))
        ->assertOk();

    expect($this->project->fresh()->read_at)->toBeNull();
})->group('PRJ-003');

test('marking a project unread and read again', function () {
    $this->actingAs($this->user)
        ->from(route('dashboard'))
        ->patch(route('projects.update', $this->project), ['unread' => true])
        ->assertRedirect(route('dashboard'));

    expect(sidebarProjects($this->user)['recent'][0]['unread'])->toBeTrue();

    $this->actingAs($this->user)->patch(route('projects.update', $this->project), ['unread' => false]);

    expect(sidebarProjects($this->user)['recent'][0]['unread'])->toBeFalse();
})->group('PRJ-003');

test('marking the open project unread leaves it so opening it does not read it again', function () {
    $this->actingAs($this->user)
        ->from(route('projects.show', $this->project))
        ->patch(route('projects.update', $this->project), ['unread' => true])
        ->assertRedirect(route('dashboard'));

    expect($this->project->fresh()->read_at)->toBeNull();
})->group('PRJ-003');

test('the sidebar has the live app link for sharing', function () {
    $this->project->update(['publish_status' => PublishStatus::Live, 'published_url' => 'https://todo.example.ts.net']);
    Project::factory()->for($this->user)->create(['publish_status' => PublishStatus::Failed, 'published_url' => 'https://old.example.ts.net']);

    expect(array_column(sidebarProjects($this->user)['recent'], 'published_url', 'name'))
        ->toContain('https://todo.example.ts.net')
        ->not->toContain('https://old.example.ts.net');
})->group('PRJ-003');

test('deleting a project takes its app offline and removes its chat, attachments, and sandbox', function () {
    Queue::fake();
    Storage::fake(Attachment::disk());
    $publisher = new FakePublisher;
    $publisher->published[$this->project->id] = PublishVisibility::Private;
    app()->instance(Publisher::class, $publisher);

    $this->project->update(['publish_status' => PublishStatus::Live]);
    $sandbox = Sandbox::factory()->for($this->project)->create(['external_id' => 'sbx-1']);
    $message = Message::factory()->for($this->project)->create();
    $attachment = Attachment::store($message, UploadedFile::fake()->image('shot.png'));

    $this->actingAs($this->user)
        ->from(route('projects.show', $this->project))
        ->delete(route('projects.destroy', $this->project))
        ->assertRedirect(route('dashboard'));

    expect(Project::find($this->project->id))->toBeNull()
        ->and(Sandbox::find($sandbox->id))->toBeNull()
        ->and(Message::find($message->id))->toBeNull()
        ->and($publisher->published)->toBe([]);
    Storage::disk(Attachment::disk())->assertMissing($attachment->path);
    Queue::assertPushed(DestroySandbox::class, fn (DestroySandbox $job) => $job->externalId === 'sbx-1' && $job->provider === 'fake');
})->group('PRJ-003');

test('deleting a project from another page stays there', function () {
    Queue::fake();

    $this->actingAs($this->user)
        ->from(route('dashboard'))
        ->delete(route('projects.destroy', $this->project))
        ->assertRedirect(route('dashboard'));

    $other = Project::factory()->for($this->user)->create();
    $current = Project::factory()->for($this->user)->create();

    $this->actingAs($this->user)
        ->from(route('projects.show', $current))
        ->delete(route('projects.destroy', $other))
        ->assertRedirect(route('projects.show', $current));
})->group('PRJ-003');

test('a page still open on a deleted project leaves for the dashboard on its next background reload', function () {
    Queue::fake();
    $url = route('projects.show', $this->project);

    $this->actingAs($this->user)->delete(route('projects.destroy', $this->project));

    $this->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) Inertia::getVersion(),
        'X-Inertia-Partial-Component' => 'projects/show',
        'X-Inertia-Partial-Data' => 'project,messages',
    ])->get($url)->assertRedirect(route('dashboard'));

    // Opening it isn't a background reload: that's still a 404.
    $this->flushHeaders()->get($url)->assertNotFound();
})->group('PRJ-003');

test('users cannot change or delete someone else\'s project', function () {
    $stranger = User::factory()->has(AgentConnection::factory())->create();

    $this->actingAs($stranger)
        ->patch(route('projects.update', $this->project), ['name' => 'Mine now'])
        ->assertForbidden();

    $this->actingAs($stranger)
        ->delete(route('projects.destroy', $this->project))
        ->assertForbidden();

    expect($this->project->fresh()->name)->toBe('Todo App');
})->group('PRJ-003');

test('regenerating the title asks the AI in the background and shows it is being named', function () {
    Queue::fake();

    $this->actingAs($this->user)
        ->from(route('dashboard'))
        ->post(route('projects.name.regenerate', $this->project))
        ->assertRedirect(route('dashboard'));

    Queue::assertPushed(RegenerateProjectName::class, fn (RegenerateProjectName $job) => $job->project->is($this->project));
    expect(sidebarProjects($this->user)['recent'][0]['naming'])->toBeTrue();
})->group('PRJ-003');

test('the AI names the project from its chat in a one-off run with no tools', function () {
    $provider = new FakeSandboxProvider;
    $provider->execUsing = fn () => new ExecResult(0, implode("\n", [
        json_encode(['type' => 'step_start']),
        json_encode(['type' => 'text', 'part' => ['text' => "Title: \"Team Todo Tracker.\"\n"]]),
    ]));
    app()->instance(SandboxProvider::class, $provider);
    Sandbox::factory()->for($this->project)->create(['external_id' => 'sbx-1']);
    Message::factory()->for($this->project)->create(['content' => 'a todo list for my team']);
    Message::factory()->for($this->project)->create(['role' => MessageRole::Assistant, 'content' => 'Built the list.']);
    ProjectNamer::markNaming($this->project);
    $this->travel(1)->hour();

    RegenerateProjectName::dispatchSync($this->project);

    $call = $provider->executed[0];
    $this->project->refresh();
    expect($this->project->name)->toBe('Team Todo Tracker')
        ->and($this->project->updated_at->lessThan(now()->subMinutes(30)))->toBeTrue()
        ->and(ProjectNamer::naming([$this->project->id]))->toBe([])
        ->and($call['id'])->toBe('sbx-1')
        ->and($call['command'][2])->toStartWith('cd /tmp && exec opencode run')
        ->and($call['env']['APP_PROMPT'])->toContain("User: a todo list for my team\nAgent: Built the list.")
        ->and($call['env']['ANTHROPIC_API_KEY'])->not->toBeEmpty()
        ->and(json_decode($call['env']['OPENCODE_CONFIG_CONTENT'], true)['permission'])->toBe(['edit' => 'deny', 'bash' => 'deny', 'webfetch' => 'deny']);
})->group('PRJ-003');

test('the name is kept when the AI can\'t be asked', function () {
    Sandbox::factory()->for($this->project)->create(['status' => SandboxStatus::Paused]);
    ProjectNamer::markNaming($this->project);

    RegenerateProjectName::dispatchSync($this->project);

    expect($this->project->fresh()->name)->toBe('Todo App')
        ->and(ProjectNamer::naming([$this->project->id]))->toBe([]);
})->group('PRJ-003');

test('the sidebar shows the agent\'s latest step in its current run', function () {
    $this->project->messages()->create(['role' => MessageRole::User, 'content' => 'Build a todo app']);
    $this->project->messages()->create(['role' => MessageRole::Activity, 'content' => 'Planning app development']);
    $this->project->messages()->create(['role' => MessageRole::Activity, 'content' => 'Editing routes/web.php']);
    $this->project->update(['status' => ProjectStatus::Working]);

    expect(sidebarProjects($this->user)['recent'][0])
        ->toMatchArray(['working' => true, 'activity' => 'Connecting the pages', 'failed' => false]);

    // A new prompt starts a new run, so the previous run's steps no longer apply.
    $this->project->messages()->create(['role' => MessageRole::User, 'content' => 'Add due dates']);

    expect(sidebarProjects($this->user)['recent'][0])->toMatchArray(['working' => true, 'activity' => null]);

    $this->project->update(['status' => ProjectStatus::Idle]);

    expect(sidebarProjects($this->user)['recent'][0])->toMatchArray(['working' => false, 'activity' => null]);
})->group('PRJ-006');

test('the sidebar shows when a project\'s sandbox failed', function () {
    expect(sidebarProjects($this->user)['recent'][0]['failed'])->toBeFalse();

    Sandbox::factory()->for($this->project)->create(['status' => SandboxStatus::Failed]);

    expect(sidebarProjects($this->user)['recent'][0]['failed'])->toBeTrue();
})->group('PRJ-006');
