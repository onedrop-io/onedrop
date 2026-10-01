<?php

use App\Models\Group;
use App\Models\Organization;
use App\Models\User;

test('guests are redirected to login', function () {
    $this->get(route('groups.index', Organization::install()))->assertRedirect(route('login'));
})->group('GRP-001');

test('a user can create a group and becomes its owner', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('groups.store', Organization::install()), [
        'name' => 'Design',
        'description' => 'Pixels.',
    ]);

    $group = Group::where('name', 'Design')->firstOrFail();

    $response->assertSessionHasNoErrors()->assertRedirect(route('groups.show', [$group->organization, $group]));
    expect($group->description)->toBe('Pixels.')
        ->and($group->isOwnedBy($user))->toBeTrue();
})->group('GRP-001');

test('a group needs a name', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('groups.store', Organization::install()), ['name' => ''])
        ->assertSessionHasErrors(['name' => 'The name field is required.']);

    expect(Group::count())->toBe(0);
})->group('GRP-001');

test('users only see groups they belong to', function () {
    $user = User::factory()->create();
    Group::factory()->ownedBy($user)->create(['name' => 'Mine']);
    Group::factory()->ownedBy(User::factory()->create())->create(['name' => 'Theirs']);

    $this->actingAs($user)
        ->get(route('groups.index', Organization::install()))
        ->assertInertia(fn ($page) => $page
            ->component('groups/index')
            ->has('groups', 1)
            ->where('groups.0.name', 'Mine')
            ->where('groups.0.role', 'owner'));
})->group('GRP-001');

test('admins see every group', function () {
    Group::factory()->ownedBy(User::factory()->create())->count(2)->create();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('groups.index', Organization::install()))
        ->assertInertia(fn ($page) => $page->has('groups', 2));
})->group('GRP-001');

test('users cannot view a group they do not belong to', function () {
    $group = Group::factory()->ownedBy(User::factory()->create())->create();

    $this->actingAs(User::factory()->create())
        ->get(route('groups.show', [$group->organization, $group]))
        ->assertForbidden();
})->group('GRP-001');

test('members can view their group', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $group = Group::factory()->ownedBy($owner)->hasAttached($member, ['role' => 'member'], 'members')->create();

    $this->actingAs($member)
        ->get(route('groups.show', [$group->organization, $group]))
        ->assertInertia(fn ($page) => $page
            ->component('groups/show')
            ->has('members', 2)
            ->where('can.update', false)
            ->where('can.delete', false));
})->group('GRP-001');

test('owners can rename a group', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->ownedBy($owner)->create();

    $this->actingAs($owner)
        ->patch(route('groups.update', [$group->organization, $group]), ['name' => 'Renamed', 'description' => null])
        ->assertRedirect(route('groups.show', [$group->organization, $group]));

    expect($group->fresh()->name)->toBe('Renamed');
})->group('GRP-003');

test('members cannot edit or delete a group', function () {
    $member = User::factory()->create();
    $group = Group::factory()
        ->ownedBy(User::factory()->create())
        ->hasAttached($member, ['role' => 'member'], 'members')
        ->create(['name' => 'Original']);

    $this->actingAs($member)->patch(route('groups.update', [$group->organization, $group]), ['name' => 'Hacked'])->assertForbidden();
    $this->actingAs($member)->delete(route('groups.destroy', [$group->organization, $group]))->assertForbidden();

    expect($group->fresh()->name)->toBe('Original');
})->group('GRP-003');

test('owners can delete a group', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->ownedBy($owner)->create();

    $this->actingAs($owner)
        ->delete(route('groups.destroy', [$group->organization, $group]))
        ->assertRedirect(route('groups.index', Organization::install()));

    expect($group->fresh())->toBeNull();
})->group('GRP-003');

test('admins can manage groups they do not belong to', function () {
    $group = Group::factory()->ownedBy(User::factory()->create())->create();

    $this->actingAs(User::factory()->admin()->create())
        ->patch(route('groups.update', [$group->organization, $group]), ['name' => 'Admin edit'])
        ->assertRedirect(route('groups.show', [$group->organization, $group]));

    expect($group->fresh()->name)->toBe('Admin edit');
})->group('GRP-003');
