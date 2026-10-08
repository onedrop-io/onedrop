<?php

use App\Enums\OrganizationRole;
use App\Enums\ProjectKind;
use App\Models\AgentConnection;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Sandbox\ProjectIcons;
use Illuminate\Support\Facades\Storage;

/**
 * A project of $owner's that's live on Your domain (or wherever $attributes say), as the Apps page lists them.
 *
 * @param  array<string, mixed>  $attributes
 */
function publishedApp(User $owner, array $attributes = []): Project
{
    return Project::factory()->for($owner)->create([
        'publish_status' => 'live',
        'publish_target' => 'domain',
        'publish_visibility' => 'private',
        'published_url' => 'https://app-'.fake()->unique()->numberBetween(1, 99999).'.example.com',
        'published_at' => now(),
        ...$attributes,
    ]);
}

beforeEach(function () {
    $this->owner = User::factory()->has(AgentConnection::factory())->create(['name' => 'Olive Owner']);
    $this->member = User::factory()->create();
    $this->organization = Organization::install();
});

test('members see the organization\'s apps everyone can open, without having set up an AI', function () {
    $app = publishedApp($this->owner, ['name' => 'Expenses']);
    publishedApp($this->owner, ['name' => 'Hosted', 'publish_target' => 'hosting', 'publish_visibility' => 'public']);
    publishedApp($this->owner, ['name' => 'Public tailnet', 'publish_target' => 'tailscale', 'publish_visibility' => 'public']);
    publishedApp($this->owner, ['name' => 'Private tailnet', 'publish_target' => 'tailscale']);
    publishedApp($this->owner, ['name' => 'Held', 'publish_status' => 'review']);
    publishedApp($this->owner, ['name' => 'Broken', 'publish_status' => 'failed']);
    publishedApp($this->owner, ['name' => 'Computer', 'kind' => ProjectKind::Computer]);
    Project::factory()->for($this->owner)->create(['name' => 'Unpublished']);

    $this->actingAs($this->member)
        ->get(route('apps.index', $this->organization))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('apps/index')
            ->where('apps', fn ($apps) => collect($apps)->pluck('name')->all() === ['Expenses', 'Hosted', 'Public tailnet'])
            ->where('apps.0.url', $app->published_url)
            ->where('apps.0.owner.name', 'Olive Owner')
            ->where('apps.0.visibility', 'private')
            ->where('apps.0.can', ['open_project' => false, 'edit' => false, 'feature' => false]));
})->group('APPS-001');

test('featured apps come first, then the rest by name', function () {
    publishedApp($this->owner, ['name' => 'beta']);
    publishedApp($this->owner, ['name' => 'Alpha']);
    publishedApp($this->owner, ['name' => 'Zulu', 'apps_featured_at' => now()]);

    $this->actingAs($this->member)
        ->get(route('apps.index', $this->organization))
        ->assertInertia(fn ($page) => $page
            ->where('apps', fn ($apps) => collect($apps)->pluck('name')->all() === ['Zulu', 'Alpha', 'beta'])
            ->where('apps.0.featured', true));
})->group('APPS-001');

test('the owner can open the project from its tile', function () {
    publishedApp($this->owner);

    $this->actingAs($this->owner)
        ->get(route('apps.index', $this->organization))
        ->assertInertia(fn ($page) => $page
            ->where('apps.0.can', ['open_project' => true, 'edit' => true, 'feature' => false]));
})->group('APPS-001');

test('another organization\'s Apps page is a 404', function () {
    config(['app.multi_tenant' => true]);
    $globex = Organization::factory()->create(['slug' => 'globex']);

    $this->actingAs($this->member)->get(route('apps.index', $globex))->assertNotFound();
})->group('APPS-001');

test('guests are sent to sign in', function () {
    $this->get(route('apps.index', $this->organization))->assertRedirect(route('login'));
})->group('APPS-001');

test('pins are each person\'s own', function () {
    $app = publishedApp($this->owner);

    $this->actingAs($this->member)->put(route('apps.pin', [$this->organization, $app]))->assertRedirect();

    $this->actingAs($this->member)->get(route('apps.index', $this->organization))
        ->assertInertia(fn ($page) => $page->where('apps.0.pinned', true));
    $this->actingAs($this->owner)->get(route('apps.index', $this->organization))
        ->assertInertia(fn ($page) => $page->where('apps.0.pinned', false));

    $this->actingAs($this->member)->delete(route('apps.unpin', [$this->organization, $app]))->assertRedirect();

    expect($this->member->pinnedApps()->count())->toBe(0);
})->group('APPS-002');

test('nobody can pin an app they can\'t see', function () {
    $hidden = publishedApp($this->owner, ['apps_listed' => false]);

    $this->actingAs($this->member)->put(route('apps.pin', [$this->organization, $hidden]))->assertForbidden();
})->group('APPS-002');

test('an owner puts their app in groups they belong to, and keeps groups an admin chose', function () {
    $app = publishedApp($this->owner);
    $theirs = Group::factory()->ownedBy($this->owner)->create(['name' => 'Finance']);
    $other = Group::factory()->ownedBy($this->member)->create(['name' => 'Legal']);
    $app->groups()->attach($other);

    $this->actingAs($this->owner)
        ->get(route('apps.index', $this->organization))
        ->assertInertia(fn ($page) => $page->where('assignableGroups', [['id' => $theirs->id, 'name' => 'Finance']]));

    $this->actingAs($this->owner)
        ->patch(route('apps.update', [$this->organization, $app]), ['group_ids' => [$other->id]])
        ->assertSessionHasErrors('group_ids.0');

    $this->actingAs($this->owner)
        ->patch(route('apps.update', [$this->organization, $app]), ['group_ids' => [$theirs->id]])
        ->assertSessionHasNoErrors();

    expect($app->groups()->pluck('name')->sort()->values()->all())->toBe(['Finance', 'Legal']);

    $this->actingAs($this->member)
        ->get(route('apps.index', $this->organization))
        ->assertInertia(fn ($page) => $page->where('apps.0.groups', fn ($groups) => collect($groups)->pluck('name')->all() === ['Finance', 'Legal']));
})->group('APPS-002');

test('organization admins can put any app in any group', function () {
    $app = publishedApp($this->owner);
    $group = Group::factory()->ownedBy($this->member)->create();
    $admin = User::factory()->create();
    $this->organization->members()->updateExistingPivot($admin->id, ['role' => OrganizationRole::Admin->value]);

    $this->actingAs($admin)
        ->patch(route('apps.update', [$this->organization, $app]), ['group_ids' => [$group->id]])
        ->assertSessionHasNoErrors();

    expect($app->groups()->pluck('groups.id')->all())->toBe([$group->id]);
})->group('APPS-002');

test('anyone in the organization can load a listed app\'s icon, but not a hidden one\'s', function () {
    Storage::fake(ProjectIcons::disk());
    Storage::disk(ProjectIcons::disk())->put('icons/app.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
    $app = publishedApp($this->owner, ['icon_path' => 'icons/app.svg', 'icon_mime' => 'image/svg+xml', 'icon_hash' => str_repeat('a', 40)]);

    $this->actingAs($this->member)->get(ProjectIcons::url($app))->assertOk();

    $app->update(['apps_listed' => false]);

    $this->actingAs($this->member)->get(ProjectIcons::url($app))->assertForbidden();
})->group('APPS-002');

test('the owner can hide their app and list it again; only they and admins still see it', function () {
    $app = publishedApp($this->owner);

    $this->actingAs($this->owner)
        ->patch(route('apps.update', [$this->organization, $app]), ['listed' => false])
        ->assertRedirect();

    expect($app->refresh()->apps_listed)->toBeFalse();

    $this->actingAs($this->member)->get(route('apps.index', $this->organization))
        ->assertInertia(fn ($page) => $page->has('apps', 0));
    $this->actingAs($this->owner)->get(route('apps.index', $this->organization))
        ->assertInertia(fn ($page) => $page->where('apps.0.listed', false));

    $this->actingAs($this->owner)->patch(route('apps.update', [$this->organization, $app]), ['listed' => true]);

    expect($app->refresh()->apps_listed)->toBeTrue();
})->group('APPS-003');

test('only organization admins can feature an app', function () {
    $app = publishedApp($this->owner);
    $admin = User::factory()->create();
    $this->organization->members()->updateExistingPivot($admin->id, ['role' => OrganizationRole::Admin->value]);

    $this->actingAs($this->owner)
        ->patch(route('apps.update', [$this->organization, $app]), ['featured' => true])
        ->assertForbidden();

    $this->actingAs($admin)
        ->patch(route('apps.update', [$this->organization, $app]), ['featured' => true])
        ->assertRedirect();

    expect($app->refresh()->apps_featured_at)->not->toBeNull();

    $this->actingAs($admin)->patch(route('apps.update', [$this->organization, $app]), ['featured' => false]);

    expect($app->refresh()->apps_featured_at)->toBeNull();
})->group('APPS-003');

test('members can\'t hide or group someone else\'s app', function () {
    $app = publishedApp($this->owner);

    $this->actingAs($this->member)
        ->patch(route('apps.update', [$this->organization, $app]), ['listed' => false])
        ->assertForbidden();
    $this->actingAs($this->member)
        ->patch(route('apps.update', [$this->organization, $app]), ['group_ids' => []])
        ->assertForbidden();

    expect($app->refresh()->apps_listed)->toBeTrue();
})->group('APPS-003');

test('the Publish panel says whether the app is listed, except for a private Tailscale app', function () {
    $app = publishedApp($this->owner);
    $tailnet = publishedApp($this->owner, ['publish_target' => 'tailscale']);

    $this->actingAs($this->owner)->get(route('projects.show', $app))
        ->assertInertia(fn ($page) => $page->where('publication.apps_listed', true));
    $this->actingAs($this->owner)->get(route('projects.show', $tailnet))
        ->assertInertia(fn ($page) => $page->where('publication.apps_listed', null));
})->group('APPS-003');
