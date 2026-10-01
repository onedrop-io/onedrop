<?php

use App\Enums\OrganizationRole;
use App\Models\AgentConnection;
use App\Models\Group;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

/**
 * Someone with an AI connection in the given organization.
 */
function organizationMember(Organization $organization, OrganizationRole $role = OrganizationRole::Member, array $attributes = []): User
{
    $user = User::factory()->has(AgentConnection::factory())->create($attributes);
    $organization->addMember($user, $role);

    return $user;
}

function registerAccount(string $email): TestResponse
{
    return test()->post(route('register.store'), [
        'name' => 'Ada Lovelace',
        'email' => $email,
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);
}

describe('on a self-hosted install', function () {
    test('every account joins its one organization, and the first account owns it', function () {
        registerAccount('first@example.com')->assertSessionHasNoErrors();
        auth()->logout();
        registerAccount('second@example.com')->assertSessionHasNoErrors();

        $organization = Organization::sole();
        $first = User::firstWhere('email', 'first@example.com');
        $second = User::firstWhere('email', 'second@example.com');

        expect($first->organizationRole($organization))->toBe(OrganizationRole::Owner)
            ->and($second->organizationRole($organization))->toBe(OrganizationRole::Member)
            ->and($second->currentOrganization()->is($organization))->toBeTrue();
    })->group('ORG-001');

    test('a platform admin runs the organization', function () {
        $admin = User::factory()->admin()->has(AgentConnection::factory())->create();
        $project = Project::factory()->create();

        $this->actingAs($admin)->get(route('projects.show', $project))->assertOk();
    })->group('ORG-001');
});

describe('on the hosted install', function () {
    beforeEach(function () {
        config(['app.multi_tenant' => true]);
        Queue::fake();

        $this->acme = Organization::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
        $this->globex = Organization::factory()->create(['name' => 'Globex', 'slug' => 'globex']);
    });

    test('signing up makes an organization owned by the new account', function () {
        registerAccount('ada@example.com')->assertSessionHasNoErrors();

        $user = User::firstWhere('email', 'ada@example.com');
        $organization = $user->currentOrganization();

        expect($organization->name)->toBe("Ada's organization")
            ->and($user->organizationRole($organization))->toBe(OrganizationRole::Owner)
            ->and($organization->is($this->acme))->toBeFalse();
    })->group('ORG-003');

    test('signing up through an invite joins the inviter\'s organization instead', function () {
        $invitation = Invitation::issue(organizationMember($this->acme), null, $this->acme);

        $this->get($invitation->url());
        registerAccount('ada@example.com')->assertSessionHasNoErrors();

        $user = User::firstWhere('email', 'ada@example.com');

        expect($user->organizations()->pluck('slug')->all())->toBe(['acme'])
            ->and($user->organizationRole($this->acme))->toBe(OrganizationRole::Member);
    })->group('ORG-003', 'ORG-004');

    test('another organization\'s projects are not found, and leaving takes away your own', function () {
        $ann = organizationMember($this->acme);
        $bob = organizationMember($this->globex);
        $project = Project::factory()->for($bob)->create(['organization_id' => $this->globex->id]);

        $this->actingAs($ann)->get(route('projects.show', $project))->assertNotFound();
        $this->actingAs($ann)->post(route('projects.messages.store', $project), ['content' => 'hi'])->assertNotFound();
        $this->actingAs($bob)->get(route('projects.show', $project))->assertOk();

        $this->globex->members()->detach($bob);
        $bob->forgetOrganizationRoles();

        $this->actingAs($bob)->get(route('projects.show', $project))->assertNotFound();
    })->group('ORG-001', 'ORG-004');

    test('organization admins open their members\' projects; members and platform admins don\'t', function () {
        $project = Project::factory()->for(organizationMember($this->acme))->create(['organization_id' => $this->acme->id]);

        $this->actingAs(organizationMember($this->acme, OrganizationRole::Admin))->get(route('projects.show', $project))->assertOk();
        $this->actingAs(organizationMember($this->acme))->get(route('projects.show', $project))->assertForbidden();
        $this->actingAs(User::factory()->admin()->has(AgentConnection::factory())->create())->get(route('projects.show', $project))->assertNotFound();
    })->group('ORG-005', 'ORG-006');

    test('an organization\'s pages are not found for people outside it', function () {
        $ann = organizationMember($this->acme);

        $this->actingAs($ann)->get(route('organizations.home', $this->globex))->assertNotFound();
        $this->actingAs($ann)->get(route('groups.index', $this->globex))->assertNotFound();
        $this->actingAs($ann)->post(route('projects.store', $this->globex), ['prompt' => 'a CRM'])->assertNotFound();

        expect(Project::count())->toBe(0);
    })->group('ORG-001');

    test('a group or invite is not found under another organization\'s address, even by someone in both', function () {
        $owner = organizationMember($this->acme, OrganizationRole::Owner);
        $this->globex->addMember($owner, OrganizationRole::Owner);
        $group = Group::factory()->ownedBy($owner)->create(['organization_id' => $this->globex->id]);
        $invitation = Invitation::issue($owner, null, $this->globex);

        $this->actingAs($owner)->get(route('groups.show', [$this->acme, $group]))->assertNotFound();
        $this->actingAs($owner)->patch(route('groups.update', [$this->acme, $group]), ['name' => 'Moved'])->assertNotFound();
        $this->actingAs($owner)->delete(route('invitations.destroy', [$this->acme, $invitation]))->assertNotFound();
        $this->actingAs($owner)->get(route('groups.show', [$this->globex, $group]))->assertOk();
    })->group('ORG-001');

    test('the organization in the address decides the sidebar, new projects and search; signing in lands in the last one', function () {
        $ann = organizationMember($this->acme);
        $this->globex->addMember($ann);
        $mine = Project::factory()->for($ann)->create(['name' => 'Acme CRM', 'organization_id' => $this->acme->id]);
        Project::factory()->for($ann)->create(['name' => 'Globex CRM', 'organization_id' => $this->globex->id]);

        $this->actingAs($ann)->get(route('organizations.home', $this->globex))
            ->assertInertia(fn ($page) => $page
                ->where('organization.slug', 'globex')
                ->where('organizations', [['id' => $this->acme->id, 'name' => 'Acme', 'slug' => 'acme'], ['id' => $this->globex->id, 'name' => 'Globex', 'slug' => 'globex']])
                ->has('sidebarProjects.recent', 1)
                ->where('sidebarProjects.recent.0.name', 'Globex CRM'));
        $this->get(route('dashboard'))->assertRedirect(route('organizations.home', $this->globex));

        $this->getJson(route('projects.search', [$this->acme, 'q' => 'CRM']))->assertJsonPath('projects.*.name', ['Acme CRM']);

        $this->post(route('projects.store', $this->acme), ['prompt' => 'an inventory app']);
        expect(Project::latest('id')->first()->organization_id)->toBe($this->acme->id);

        // Opening a project moves to its organization too.
        $this->get(route('organizations.home', $this->globex));
        $this->get(route('projects.show', $mine))->assertOk();
        $this->get(route('dashboard'))->assertRedirect(route('organizations.home', $this->acme));
    })->group('ORG-002');

    test('groups only take people from their organization', function () {
        $owner = organizationMember($this->acme, OrganizationRole::Owner);
        $group = Group::factory()->ownedBy($owner)->create(['organization_id' => $this->acme->id]);
        $outsider = organizationMember($this->globex, attributes: ['email' => 'outsider@example.com']);

        $this->actingAs($owner)
            ->post(route('groups.members.store', [$this->acme, $group]), ['email' => $outsider->email, 'role' => 'member'])
            ->assertSessionHasErrors(['email' => 'No one in this organization has that email address.']);

        expect($group->members()->whereKey($outsider->id)->exists())->toBeFalse();
    })->group('ORG-005');

    test('shared skills are only seen in their organization', function () {
        $ann = organizationMember($this->acme);
        $this->globex->addMember($ann);
        $project = Project::factory()->for($ann)->create(['organization_id' => $this->acme->id]);
        $theirs = Skill::factory()->for(organizationMember($this->globex))->shared()->create(['organization_id' => $this->globex->id, 'name' => 'globex-deploy']);
        $outsider = organizationMember($this->acme);

        $this->actingAs($ann)->getJson(route('projects.skills.index', $project))
            ->assertOk()
            ->assertJsonMissing(['name' => 'globex-deploy']);
        $this->actingAs($ann)->putJson(route('projects.skills.toggle', [$project, $theirs]), ['enabled' => true])->assertNotFound();
        $this->actingAs($outsider)->getJson(route('skills.show', $theirs))->assertNotFound();
    })->group('ORG-007');
});
