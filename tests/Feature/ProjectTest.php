<?php

use App\Enums\AgentProvider;
use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Enums\SandboxStatus;
use App\Jobs\CreateSandbox;
use App\Jobs\RunAgentTask;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->user = User::factory()->has(AgentConnection::factory())->create(['name' => 'Jeff Loiselle']);
});

test('the new-project prompt shows the default AI', function () {
    $this->actingAs($this->user)
        ->followingRedirects()->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->component('projects/create')
            ->where('defaultAi', 'Claude'));
})->group('PRJ-001');

test('submitting a description creates a named project and starts the agent', function () {
    Queue::fake();

    $response = $this->actingAs($this->user)->post(route('projects.store', $this->user->currentOrganization()), [
        'prompt' => 'time-off tracker for my team, with approvals and a calendar',
    ]);

    $project = $this->user->projects()->sole();

    $response->assertRedirect(route('projects.show', $project));
    expect($project->name)->toBe('Time-Off Tracker For My Team, With')
        ->and($project->prompt)->toBe('time-off tracker for my team, with approvals and a calendar')
        ->and($project->messages()->sole()->role)->toBe(MessageRole::User)
        ->and($project->status)->toBe(ProjectStatus::Working);

    Queue::assertPushedWithChain(CreateSandbox::class, [RunAgentTask::class]);
    expect($project->sandbox->status)->toBe(SandboxStatus::Creating);
})->group('PRJ-001');

test('creating a project starts its sandbox', function () {
    $this->actingAs($this->user)->post(route('projects.store', $this->user->currentOrganization()), ['prompt' => 'a todo app']);

    $sandbox = $this->user->projects()->sole()->sandbox;

    expect($sandbox->status)->toBe(SandboxStatus::Running)
        ->and($sandbox->provider)->toBe('fake')
        ->and($sandbox->external_id)->toStartWith('fake-');
})->group('SBX-001');

test('a description is required', function () {
    $this->actingAs($this->user)
        ->post(route('projects.store', $this->user->currentOrganization()), ['prompt' => ''])
        ->assertSessionHasErrors(['prompt' => 'Describe what you want to build.']);

    expect(Project::count())->toBe(0);
})->group('PRJ-001');

test('users without an AI cannot create projects', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('projects.store', $this->user->currentOrganization()), ['prompt' => 'anything'])
        ->assertRedirect(route('onboarding.ai'));

    expect(Project::count())->toBe(0);
})->group('PRJ-001');

test('the placeholder agent replies, names the AI, and finishes', function () {
    $this->actingAs($this->user)->post(route('projects.store', $this->user->currentOrganization()), ['prompt' => 'a vacation calendar']);

    $project = $this->user->projects()->sole();
    $messages = $project->messages;

    expect($messages->pluck('role')->all())->toBe([
        MessageRole::User, MessageRole::Activity, MessageRole::Assistant, MessageRole::Activity, MessageRole::Assistant,
    ])
        ->and($messages[2]->content)->toContain('a vacation calendar')->toContain('using Claude')
        ->and($project->fresh()->status)->toBe(ProjectStatus::Idle);
})->group('PRJ-002');

test('the placeholder agent uses the default connection', function () {
    AgentConnection::factory()->for($this->user)->provider(AgentProvider::Codex)->create(['is_default' => false]);
    $this->user->agentConnections()->update(['is_default' => false]);
    $this->user->agentConnections()->where('provider', 'codex')->update(['is_default' => true]);

    $this->actingAs($this->user)->post(route('projects.store', $this->user->currentOrganization()), ['prompt' => 'a CRM']);

    expect($this->user->projects()->sole()->messages[2]->content)->toContain('using Codex');
})->group('PRJ-002');

test('the workspace shows the project and its chat', function () {
    $project = Project::factory()->for($this->user)->create();
    Sandbox::factory()->for($project)->create(['preview_url' => 'https://preview.test']);
    $project->messages()->create(['role' => MessageRole::User, 'content' => 'hello']);

    $this->actingAs($this->user)
        ->get(route('projects.show', $project))
        ->assertInertia(fn ($page) => $page
            ->component('projects/show')
            ->where('project.name', $project->name)
            ->where('project.status', 'idle')
            ->where('sandbox.status', 'running')
            ->where('sandbox.preview_url', 'https://preview.test')
            ->has('messages', 1)
            ->where('messages.0.content', 'hello'));
})->group('PRJ-002');

test('users can send follow-up messages', function () {
    Queue::fake();
    $project = Project::factory()->for($this->user)->create();

    $this->actingAs($this->user)
        ->post(route('projects.messages.store', $project), ['content' => 'add a dark mode'])
        ->assertRedirect(route('projects.show', $project));

    expect($project->messages()->sole()->content)->toBe('add a dark mode')
        ->and($project->fresh()->status)->toBe(ProjectStatus::Working);
    Queue::assertPushed(RunAgentTask::class);
})->group('PRJ-002');

test('a crashed agent job un-sticks the chat', function () {
    $project = Project::factory()->for($this->user)->create(['status' => ProjectStatus::Working]);
    $message = $project->messages()->create(['role' => MessageRole::User, 'content' => 'hi']);

    (new RunAgentTask($project, $message))->failed(new RuntimeException('boom'));

    expect($project->fresh()->status)->toBe(ProjectStatus::Idle)
        ->and($project->messages()->reorder()->latest('id')->first()->content)->toBe("The agent couldn't start. Please try again.");
})->group('AGT-001');

test('an empty follow-up is rejected', function () {
    $project = Project::factory()->for($this->user)->create();

    $this->actingAs($this->user)
        ->post(route('projects.messages.store', $project), ['content' => ''])
        ->assertSessionHasErrors('content');
})->group('PRJ-002');

test('users cannot see or message another user\'s project', function () {
    $project = Project::factory()->create();

    $this->actingAs($this->user)->get(route('projects.show', $project))->assertForbidden();
    $this->actingAs($this->user)
        ->post(route('projects.messages.store', $project), ['content' => 'hi'])
        ->assertForbidden();

    expect($project->messages()->count())->toBe(0);
})->group('PRJ-002');

test('admins can see any project', function () {
    $admin = User::factory()->admin()->has(AgentConnection::factory())->create();

    $this->actingAs($admin)
        ->get(route('projects.show', Project::factory()->create()))
        ->assertOk();
})->group('PRJ-002');

test('recent projects are shared with the sidebar', function () {
    Project::factory()->for($this->user)->create(['name' => 'Mine']);
    Project::factory()->create(['name' => 'Someone else']);

    $this->actingAs($this->user)
        ->followingRedirects()->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->has('sidebarProjects.recent', 1)
            ->where('sidebarProjects.recent.0.name', 'Mine'));
})->group('PRJ-002');
