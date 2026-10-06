<?php

use App\Enums\MessageRole;
use App\Enums\OrganizationRole;
use App\Enums\ProjectKind;
use App\Enums\ProjectStatus;
use App\Enums\SandboxStatus;
use App\Http\Controllers\ComputerController;
use App\Jobs\BackupProject;
use App\Jobs\CreateSandbox;
use App\Jobs\SuspendComputers;
use App\Jobs\UpdateProjectIcon;
use App\Models\AgentConnection;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Agents\ClaudeCodeEvents;
use App\Sandbox\Agents\HarnessRunner;
use App\Sandbox\Drive\DriveSync;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use App\Sandbox\WorkspaceSsh;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->provider = new FakeSandboxProvider;
    app()->instance(SandboxProvider::class, $this->provider);

    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->organization = $this->user->currentOrganization();
});

/** Someone else in the organization, with an AI connection and the role given. */
function colleague(Organization $organization, OrganizationRole $role = OrganizationRole::Member): User
{
    $user = User::factory()->has(AgentConnection::factory())->create();
    $organization->addMember($user);
    $organization->members()->updateExistingPivot($user->id, ['role' => $role->value]);
    $user->forgetOrganizationRoles();

    return $user;
}

/** The user's computer, made with a running sandbox. */
function computerFor(User $user): Project
{
    $computer = Project::factory()->for($user)->create([
        'organization_id' => $user->currentOrganization()->id,
        'kind' => ProjectKind::Computer,
        'name' => 'Computer',
    ]);
    Sandbox::factory()->for($computer)->create(['external_id' => 'computer-1', 'preview_url' => 'http://127.0.0.1:32900']);

    return $computer;
}

test('opening the computer the first time makes it, with its sandbox on the way', function () {
    Queue::fake();

    $this->actingAs($this->user)
        ->get(route('computers.show', $this->organization))
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/show')
            ->where('project.computer.organization', $this->organization->slug)
            ->where('sandbox.status', 'creating'));

    $computer = $this->user->computerIn($this->organization);

    expect($computer)->not->toBeNull()
        ->and($computer->kind)->toBe(ProjectKind::Computer)
        ->and($computer->sandbox->status)->toBe(SandboxStatus::Creating);
    Queue::assertPushed(CreateSandbox::class, fn (CreateSandbox $job) => $job->project->is($computer));

    // Opening it again is the same computer.
    $this->actingAs($this->user)->get(route('computers.show', $this->organization))->assertOk();
    expect($this->user->computers()->count())->toBe(1);
})->group('CMP-001');

test('the computer shows its desktop where an app shows its preview', function () {
    computerFor($this->user);

    $this->actingAs($this->user)
        ->get(route('computers.show', $this->organization))
        ->assertInertia(fn (Assert $page) => $page
            ->where('sandbox.desktop_url', 'http://127.0.0.1:32900/__onedrop/desktop/'));
})->group('CMP-001');

test("a computer's sandbox runs a desktop and keeps Drive in step", function () {
    $computer = Project::factory()->for($this->user)->create(['kind' => ProjectKind::Computer]);
    $app = Project::factory()->for($this->user)->create();

    (new CreateSandbox($computer))->handle($this->provider, app(WorkspaceSsh::class));
    (new CreateSandbox($app))->handle($this->provider, app(WorkspaceSsh::class));

    [$computerSpec, $appSpec] = array_values($this->provider->created);

    expect($computerSpec->name)->toStartWith("onedrop-computer-{$computer->id}-")
        ->and($computerSpec->env['ONEDROP_DESKTOP'])->toBe('1')
        ->and($computerSpec->env['ONEDROP_DRIVE_DIR'])->toBe(DriveSync::COMPUTER_DIR)
        ->and($computerSpec->env['ONEDROP_DRIVE_TOKEN'])->toBe(DriveSync::token($computer->sandbox))
        ->and($appSpec->env)->not->toHaveKey('ONEDROP_DESKTOP')
        ->and($appSpec->env['ONEDROP_DRIVE_DIR'])->toBe(DriveSync::PROJECT_DIR);
})->group('CMP-001', 'DRIVE-003', 'DRIVE-004');

test('a computer is not a project in lists, search or the sidebar', function () {
    $computer = computerFor($this->user);
    $app = Project::factory()->for($this->user)->create(['name' => 'Computer shop']);

    expect($this->user->projects()->pluck('id')->all())->toBe([$app->id])
        ->and($this->organization->projects()->pluck('id')->all())->toBe([$app->id]);

    $this->actingAs($this->user)
        ->getJson(route('projects.search', [$this->organization, 'q' => 'Computer']))
        ->assertJsonMissing(['id' => $computer->id]);

    $this->actingAs($this->user)
        ->get(route('computers.show', $this->organization))
        ->assertInertia(fn (Assert $page) => $page
            ->where('currentOrganization.computers', true)
            ->has('sidebarProjects.recent', 1)
            ->where('sidebarProjects.recent.0.id', $app->id));
})->group('CMP-001');

test("a computer's project address opens the computer page", function () {
    $computer = computerFor($this->user);

    $this->actingAs($this->user)
        ->get(route('projects.show', $computer))
        ->assertRedirect(route('computers.show', $this->organization));
})->group('CMP-001');

test('only its owner can open a computer, not other members or the organization\'s admins', function () {
    $computer = computerFor($this->user);
    $admin = colleague($this->organization, OrganizationRole::Admin);
    $member = colleague($this->organization);

    foreach ([$admin, $member] as $other) {
        $this->actingAs($other)->get(route('projects.show', $computer))->assertNotFound();
        $this->actingAs($other)->post(route('projects.messages.store', $computer), ['content' => 'hi'])->assertNotFound();
        expect($other->can('view', $computer))->toBeFalse();
    }

    expect($this->user->can('view', $computer))->toBeTrue();
})->group('CMP-001');

test('restarting the computer restarts its desktop', function () {
    computerFor($this->user);

    $this->actingAs($this->user)
        ->post(route('computers.restart', $this->organization))
        ->assertRedirect();

    expect(collect($this->provider->executed)->pluck('command'))->toContain(ComputerController::RESTART);
})->group('CMP-001');

test('resetting the computer throws it away; the next visit makes a new one', function () {
    Queue::fake();
    $computer = computerFor($this->user);

    $this->actingAs($this->user)
        ->delete(route('computers.destroy', $this->organization))
        ->assertRedirect(route('computers.show', $this->organization));

    expect(Project::query()->find($computer->id))->toBeNull()
        ->and($this->user->computers()->count())->toBe(0);
})->group('CMP-001');

test('a computer that failed to start can be tried again', function () {
    Queue::fake();
    $computer = computerFor($this->user);
    $computer->sandbox->update(['status' => SandboxStatus::Failed, 'error' => 'No room']);

    $this->actingAs($this->user)->post(route('computers.retry', $this->organization))->assertRedirect();

    expect($computer->sandbox->fresh()->status)->toBe(SandboxStatus::Creating);
    Queue::assertPushed(CreateSandbox::class);
})->group('CMP-001');

test('leaving an organization deletes the computer there', function () {
    config(['app.multi_tenant' => true]);
    $owner = User::factory()->create();
    $organization = Organization::createNamed('Acme');
    $organization->addMember($owner, OrganizationRole::Owner);
    $organization->addMember($this->user);
    $this->user->switchOrganization($organization);
    $computer = computerFor($this->user);

    $organization->removeMember($this->user);

    expect(Project::query()->find($computer->id))->toBeNull();
})->group('CMP-001');

test('owners and admins can turn computers off, which hides them and puts running ones to sleep', function () {
    Queue::fake();
    $computer = computerFor($this->user);
    $manager = colleague($this->organization, OrganizationRole::Admin);

    $this->actingAs($this->user)
        ->put(route('organizations.computers.update', $this->organization), ['enabled' => false])
        ->assertForbidden();

    $this->actingAs($manager)
        ->put(route('organizations.computers.update', $this->organization), ['enabled' => false])
        ->assertRedirect(route('organizations.edit', $this->organization));

    expect($this->organization->fresh()->computers_enabled)->toBeFalse();
    Queue::assertPushed(SuspendComputers::class);

    $this->actingAs($this->user)->get(route('computers.show', $this->organization))->assertNotFound();
    $this->actingAs($this->user)->get(route('projects.show', $computer))->assertNotFound();
    $this->actingAs($this->user)
        ->get(route('drive.show', [$this->organization, 'personal']))
        ->assertInertia(fn (Assert $page) => $page->where('currentOrganization.computers', false));
})->group('CMP-003');

test('turning computers off puts the running ones to sleep and keeps them', function () {
    $computer = computerFor($this->user);
    $app = Project::factory()->for($this->user)->create();
    Sandbox::factory()->for($app)->create(['external_id' => 'app-1']);

    (new SuspendComputers($this->organization))->handle($this->provider);

    expect($this->provider->suspended)->toBe(['computer-1'])
        ->and($computer->sandbox->fresh()->suspended_at)->not->toBeNull()
        ->and(Project::query()->find($computer->id))->not->toBeNull();
})->group('CMP-003');

test('the install can turn computers off for every organization', function () {
    config(['sandbox.computers.enabled' => false]);

    $this->actingAs($this->user)->get(route('computers.show', $this->organization))->assertNotFound();
})->group('CMP-003');

test("the AI works a computer's desktop with the computer guide", function () {
    $computer = computerFor($this->user);
    $computer->update(['status' => ProjectStatus::Working]);
    $message = $computer->messages()->create(['role' => MessageRole::User, 'content' => 'Find flights to Lisbon']);

    app(HarnessRunner::class)->start($computer, $message);

    $forwarder = collect($this->provider->executed)->firstWhere('command', ['node', '/opt/onedrop/forwarder.mjs']);

    expect($forwarder['env']['APP_MODE'])->toBe('computer')
        ->and($forwarder['env']['APP_REQUIREMENTS'])->toBe('');
})->group('CMP-002');

test("a finished turn on a computer doesn't back up, check or draw an icon for it", function () {
    Queue::fake();
    $computer = computerFor($this->user);
    $computer->update(['status' => ProjectStatus::Working]);

    app(ClaudeCodeEvents::class)->apply($computer, ['type' => 'result', 'subtype' => 'success', 'is_error' => false, 'result' => 'Done']);
    app(ClaudeCodeEvents::class)->apply($computer, ['type' => 'onedrop.exit', 'code' => 0]);

    expect($computer->fresh()->status)->toBe(ProjectStatus::Idle);
    Queue::assertNotPushed(BackupProject::class);
    Queue::assertNotPushed(UpdateProjectIcon::class);
})->group('CMP-002');
