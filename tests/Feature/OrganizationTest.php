<?php

use App\Enums\OrganizationRole;
use App\Models\AgentConnection;
use App\Models\AgentUsage;
use App\Models\Group;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
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
                ->where('currentOrganization.slug', 'globex')
                ->where('userOrganizations', [['id' => $this->acme->id, 'name' => 'Acme', 'slug' => 'acme', 'logo_url' => null], ['id' => $this->globex->id, 'name' => 'Globex', 'slug' => 'globex', 'logo_url' => null]])
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

describe('managing an organization', function () {
    beforeEach(function () {
        config(['app.multi_tenant' => true]);

        $this->acme = Organization::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
        $this->owner = organizationMember($this->acme, OrganizationRole::Owner);
        $this->admin = organizationMember($this->acme, OrganizationRole::Admin);
        $this->member = organizationMember($this->acme);
    });

    test('everyone sees the members; only owners and admins can change them', function () {
        $this->actingAs($this->member)->get(route('organizations.edit', $this->acme))
            ->assertInertia(fn ($page) => $page
                ->component('organizations/edit')
                ->has('members', 3)
                ->where('can', ['update' => false, 'manage_owners' => false, 'remove' => true]));

        $this->actingAs($this->member)
            ->patch(route('organizations.members.update', [$this->acme, $this->admin]), ['role' => 'member'])
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->patch(route('organizations.members.update', [$this->acme, $this->member]), ['role' => 'admin'])
            ->assertRedirect(route('organizations.edit', $this->acme));

        expect($this->member->fresh()->organizationRole($this->acme))->toBe(OrganizationRole::Admin);
    })->group('ORG-004');

    test('only owners make, change or remove owners', function () {
        $this->actingAs($this->admin)
            ->patch(route('organizations.members.update', [$this->acme, $this->member]), ['role' => 'owner'])
            ->assertForbidden();
        $this->actingAs($this->admin)
            ->patch(route('organizations.members.update', [$this->acme, $this->owner]), ['role' => 'member'])
            ->assertForbidden();
        $this->actingAs($this->admin)->delete(route('organizations.members.destroy', [$this->acme, $this->owner]))->assertForbidden();

        $this->actingAs($this->owner)
            ->patch(route('organizations.members.update', [$this->acme, $this->admin]), ['role' => 'owner'])
            ->assertSessionHasNoErrors();

        expect($this->admin->fresh()->organizationRole($this->acme))->toBe(OrganizationRole::Owner);
    })->group('ORG-004');

    test('the last owner can\'t step down or leave', function () {
        $this->actingAs($this->owner)
            ->patch(route('organizations.members.update', [$this->acme, $this->owner]), ['role' => 'admin'])
            ->assertSessionHasErrors(['member' => 'An organization must have at least one owner.']);
        $this->actingAs($this->owner)
            ->delete(route('organizations.members.destroy', [$this->acme, $this->owner]))
            ->assertSessionHasErrors(['member' => 'An organization must have at least one owner.']);

        expect($this->owner->fresh()->organizationRole($this->acme))->toBe(OrganizationRole::Owner);
    })->group('ORG-004');

    test('a removed member leaves its groups and loses its projects, which stay', function () {
        $group = Group::factory()->ownedBy($this->member)->create(['organization_id' => $this->acme->id]);
        $project = Project::factory()->for($this->member)->create(['organization_id' => $this->acme->id]);

        $this->actingAs($this->admin)
            ->delete(route('organizations.members.destroy', [$this->acme, $this->member]))
            ->assertRedirect(route('organizations.edit', $this->acme));

        $member = $this->member->fresh();

        expect($member->belongsToOrganization($this->acme))->toBeFalse()
            ->and($group->members()->whereKey($member->id)->exists())->toBeFalse()
            ->and($project->fresh()->organization_id)->toBe($this->acme->id);
        $this->actingAs($member)->get(route('projects.show', $project))->assertNotFound();
        $this->actingAs($this->admin)->get(route('projects.show', $project))->assertOk();
    })->group('ORG-004');

    test('a member can leave', function () {
        $this->actingAs($this->member)
            ->delete(route('organizations.members.destroy', [$this->acme, $this->member]))
            ->assertRedirect(route('dashboard'));

        expect($this->member->fresh()->belongsToOrganization($this->acme))->toBeFalse();
    })->group('ORG-004');

    test('owners and admins rename it and move its address', function () {
        $this->actingAs($this->admin)
            ->patch(route('organizations.update', $this->acme), ['name' => 'Acme Corp', 'slug' => 'acme-corp'])
            ->assertRedirect('/o/acme-corp/settings');

        expect($this->acme->fresh()->only('name', 'slug'))->toBe(['name' => 'Acme Corp', 'slug' => 'acme-corp']);
        $this->actingAs($this->admin)->get('/o/acme/settings')->assertNotFound();

        $this->actingAs($this->member)
            ->patch(route('organizations.update', ['organization' => 'acme-corp']), ['name' => 'Mine', 'slug' => 'mine'])
            ->assertForbidden();
    })->group('ORG-005');

    test('an address must be lowercase words and not taken', function (string $slug, string $error) {
        Organization::factory()->create(['slug' => 'globex']);

        $this->actingAs($this->owner)
            ->patch(route('organizations.update', $this->acme), ['name' => 'Acme', 'slug' => $slug])
            ->assertSessionHasErrors(['slug' => $error]);
    })->with([
        'capitals' => ['Acme', 'Use lowercase letters, numbers and dashes.'],
        'spaces' => ['acme corp', 'Use lowercase letters, numbers and dashes.'],
        'taken' => ['globex', 'Another organization has that address.'],
    ])->group('ORG-005');

    test('owners and admins see the organization\'s usage by person, deleted projects included', function () {
        $project = Project::factory()->for($this->member)->create(['organization_id' => $this->acme->id]);
        AgentUsage::factory()->for($this->member)->create(['project_id' => $project->id, 'cost' => 2]);
        $gone = Project::factory()->for($this->owner)->create(['organization_id' => $this->acme->id]);
        AgentUsage::factory()->for($this->owner)->create(['project_id' => $gone->id, 'cost' => 3]);
        $gone->delete();
        AgentUsage::factory()->for($this->member)->create(['cost' => 50, 'organization_id' => Organization::factory()->create()->id]);

        $this->actingAs($this->admin)->get(route('organizations.usage', $this->acme))
            ->assertInertia(fn ($page) => $page
                ->component('usage/index')
                ->where('title', 'Acme')
                ->where('totals.cost', 5)
                ->where('people.0.name', $this->owner->name)
                ->where('people.0.cost', 3)
                ->where('people.1.name', $this->member->name));

        $this->actingAs($this->member)->get(route('organizations.usage', $this->acme))->assertForbidden();
    })->group('ORG-005');
});

test('on a self-hosted install nobody leaves or is removed from its organization', function () {
    $admin = User::factory()->admin()->has(AgentConnection::factory())->create();
    $member = User::factory()->has(AgentConnection::factory())->create();
    $organization = Organization::install();

    $this->actingAs($admin)
        ->delete(route('organizations.members.destroy', [$organization, $member]))
        ->assertSessionHasErrors(['member' => 'Everyone on this install is in its organization. Delete the account instead.']);
    $this->actingAs($member)
        ->delete(route('organizations.members.destroy', [$organization, $member]))
        ->assertSessionHasErrors('member');

    expect($member->fresh()->belongsToOrganization($organization))->toBeTrue();
})->group('ORG-004');

describe('more than one organization', function () {
    beforeEach(function () {
        config(['app.multi_tenant' => true]);
        $this->acme = Organization::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
        $this->ann = organizationMember($this->acme, OrganizationRole::Owner);
    });

    test('on the hosted install anyone can create another organization, which they own', function () {
        $this->actingAs($this->ann)
            ->post(route('organizations.store'), ['name' => 'Acme Labs'])
            ->assertRedirect('/o/acme-labs');

        $labs = Organization::firstWhere('slug', 'acme-labs');

        expect($labs->name)->toBe('Acme Labs')
            ->and($this->ann->fresh()->organizationRole($labs))->toBe(OrganizationRole::Owner);

        $this->actingAs($this->ann)->post(route('organizations.store'), ['name' => ''])->assertSessionHasErrors('name');
    })->group('ORG-003');

    test('a self-hosted install has just one organization', function () {
        config(['app.multi_tenant' => false]);

        $this->actingAs($this->ann)->post(route('organizations.store'), ['name' => 'Another'])->assertNotFound();

        expect(Organization::count())->toBe(1);
    })->group('ORG-003');

    test('the switcher lists the user\'s organizations with their logos', function () {
        Storage::fake('local');
        $this->actingAs($this->ann)
            ->post(route('organizations.logo.store', $this->acme), ['logo' => UploadedFile::fake()->image('logo.png', 64, 64)])
            ->assertRedirect(route('organizations.edit', $this->acme));
        $globex = Organization::factory()->create(['name' => 'Globex', 'slug' => 'globex']);
        $globex->addMember($this->ann);
        Organization::factory()->create(['name' => 'Initech']);

        $logo = $this->acme->fresh()->logoUrl();

        $this->actingAs($this->ann)->get(route('organizations.home', $this->acme))
            ->assertInertia(fn ($page) => $page
                ->where('currentOrganization.logo_url', $logo)
                ->where('userOrganizations', [
                    ['id' => $this->acme->id, 'name' => 'Acme', 'slug' => 'acme', 'logo_url' => $logo],
                    ['id' => $globex->id, 'name' => 'Globex', 'slug' => 'globex', 'logo_url' => null],
                ]));
    })->group('ORG-002', 'ORG-005');

    test('only owners and admins change the logo, and only members can load it', function () {
        Storage::fake('local');
        $member = organizationMember($this->acme);
        $outsider = organizationMember(Organization::factory()->create());

        $this->actingAs($member)
            ->post(route('organizations.logo.store', $this->acme), ['logo' => UploadedFile::fake()->image('logo.png')])
            ->assertForbidden();
        $this->actingAs($this->ann)
            ->post(route('organizations.logo.store', $this->acme), ['logo' => UploadedFile::fake()->create('logo.gif', 10, 'image/gif')])
            ->assertSessionHasErrors('logo');
        $this->actingAs($this->ann)
            ->post(route('organizations.logo.store', $this->acme), ['logo' => UploadedFile::fake()->image('logo.png')]);

        $path = $this->acme->fresh()->logo_path;

        $this->actingAs($member)->get(route('organizations.logo', $this->acme))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($outsider)->get(route('organizations.logo', $this->acme))->assertNotFound();

        $this->actingAs($this->ann)->delete(route('organizations.logo.destroy', $this->acme));

        expect($this->acme->fresh()->logo_path)->toBeNull();
        Storage::disk('local')->assertMissing($path);
    })->group('ORG-005');

    test('platform admins see every organization\'s counts and owners, but not its projects', function () {
        $admin = organizationMember(Organization::factory()->create(['slug' => 'operator']), OrganizationRole::Owner, ['is_admin' => true]);
        Organization::factory()->create(['name' => 'Globex', 'slug' => 'globex']);
        $project = Project::factory()->for($this->ann)->create(['organization_id' => $this->acme->id]);

        $this->actingAs($admin)->get(route('admin.organizations.index'))
            ->assertInertia(fn ($page) => $page
                ->component('admin/organizations')
                ->has('organizations', 3)
                ->where('organizations.0.slug', 'globex')
                ->where('organizations.2.slug', 'acme')
                ->where('organizations.2.owners.0.name', $this->ann->name)
                ->where('organizations.2.members_count', 1)
                ->where('organizations.2.projects_count', 1)
                // The page's own list doesn't replace the admin's organizations in the switcher.
                ->where('userOrganizations.0.slug', 'operator')
                ->has('userOrganizations', 1));

        $this->actingAs($admin)->get(route('projects.show', $project))->assertNotFound();
        $this->actingAs($this->ann)->get(route('admin.organizations.index'))->assertForbidden();
    })->group('ORG-006');
});
