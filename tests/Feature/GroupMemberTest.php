<?php

use App\Enums\GroupRole;
use App\Models\Group;
use App\Models\Organization;
use App\Models\User;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->group = Group::factory()->ownedBy($this->owner)->create();
});

test('owners can add an existing user by email', function () {
    $newcomer = User::factory()->create();

    $this->actingAs($this->owner)
        ->post(route('groups.members.store', [$this->group->organization, $this->group]), ['email' => $newcomer->email, 'role' => 'member'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('groups.show', [$this->group->organization, $this->group]));

    expect($this->group->roleOf($newcomer))->toBe(GroupRole::Member);
})->group('GRP-002');

test('adding an unknown email shows an error', function () {
    $this->actingAs($this->owner)
        ->post(route('groups.members.store', [$this->group->organization, $this->group]), ['email' => 'nobody@example.com', 'role' => 'member'])
        ->assertSessionHasErrors(['email' => 'No one in this organization has that email address.']);
})->group('GRP-002');

test('adding an existing member shows an error', function () {
    $this->actingAs($this->owner)
        ->post(route('groups.members.store', [$this->group->organization, $this->group]), ['email' => $this->owner->email, 'role' => 'member'])
        ->assertSessionHasErrors(['email' => 'That user is already a member of this group.']);
})->group('GRP-002');

test('adding with an invalid role is rejected', function () {
    $this->actingAs($this->owner)
        ->post(route('groups.members.store', [$this->group->organization, $this->group]), ['email' => User::factory()->create()->email, 'role' => 'emperor'])
        ->assertSessionHasErrors('role');
})->group('GRP-002');

test('members who are not owners cannot manage members', function () {
    $member = User::factory()->create();
    $other = User::factory()->create();
    $this->group->members()->attach($member, ['role' => 'member']);
    $this->group->members()->attach($other, ['role' => 'member']);

    $this->actingAs($member)
        ->post(route('groups.members.store', [$this->group->organization, $this->group]), ['email' => User::factory()->create()->email, 'role' => 'member'])
        ->assertForbidden();
    $this->actingAs($member)
        ->patch(route('groups.members.update', [$this->group->organization, $this->group, $other]), ['role' => 'owner'])
        ->assertForbidden();
    $this->actingAs($member)
        ->delete(route('groups.members.destroy', [$this->group->organization, $this->group, $other]))
        ->assertForbidden();

    expect($this->group->roleOf($other))->toBe(GroupRole::Member);
})->group('GRP-002');

test('owners can change a member role', function () {
    $member = User::factory()->create();
    $this->group->members()->attach($member, ['role' => 'member']);

    $this->actingAs($this->owner)
        ->patch(route('groups.members.update', [$this->group->organization, $this->group, $member]), ['role' => 'owner'])
        ->assertRedirect(route('groups.show', [$this->group->organization, $this->group]));

    expect($this->group->roleOf($member))->toBe(GroupRole::Owner);
})->group('GRP-002');

test('owners can remove a member', function () {
    $member = User::factory()->create();
    $this->group->members()->attach($member, ['role' => 'member']);

    $this->actingAs($this->owner)
        ->delete(route('groups.members.destroy', [$this->group->organization, $this->group, $member]))
        ->assertRedirect(route('groups.show', [$this->group->organization, $this->group]));

    expect($this->group->roleOf($member))->toBeNull();
})->group('GRP-002');

test('a member can leave a group', function () {
    $member = User::factory()->create();
    $this->group->members()->attach($member, ['role' => 'member']);

    $this->actingAs($member)
        ->delete(route('groups.members.destroy', [$this->group->organization, $this->group, $member]))
        ->assertRedirect(route('groups.index', Organization::install()));

    expect($this->group->roleOf($member))->toBeNull();
})->group('GRP-002');

test('the last owner cannot leave or be demoted', function () {
    $this->actingAs($this->owner)
        ->delete(route('groups.members.destroy', [$this->group->organization, $this->group, $this->owner]))
        ->assertSessionHasErrors(['member' => 'A group must have at least one owner.']);

    $this->actingAs($this->owner)
        ->patch(route('groups.members.update', [$this->group->organization, $this->group, $this->owner]), ['role' => 'member'])
        ->assertSessionHasErrors('member');

    expect($this->group->isOwnedBy($this->owner))->toBeTrue();
})->group('GRP-002');

test('an owner can leave when another owner remains', function () {
    $coOwner = User::factory()->create();
    $this->group->members()->attach($coOwner, ['role' => 'owner']);

    $this->actingAs($this->owner)
        ->delete(route('groups.members.destroy', [$this->group->organization, $this->group, $this->owner]))
        ->assertSessionHasNoErrors();

    expect($this->group->roleOf($this->owner))->toBeNull();
})->group('GRP-002');

test('removing a non-member returns not found', function () {
    $this->actingAs($this->owner)
        ->delete(route('groups.members.destroy', [$this->group->organization, $this->group, User::factory()->create()]))
        ->assertNotFound();
})->group('GRP-002');
