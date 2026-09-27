<?php

use App\Models\User;

test('admins can list all users', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->count(2)->create();

    $this->actingAs($admin)
        ->get(route('users.index'))
        ->assertInertia(fn ($page) => $page->component('users/index')->has('users', 3));
})->group('USR-001');

test('non-admins cannot see the users list', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('users.index'))
        ->assertForbidden();
})->group('USR-001');

test('admins can grant and revoke admin access', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    $this->actingAs($admin)->patch(route('users.update', $user), ['is_admin' => true])->assertRedirect(route('users.index'));
    expect($user->fresh()->is_admin)->toBeTrue();

    $this->actingAs($admin)->patch(route('users.update', $user), ['is_admin' => false]);
    expect($user->fresh()->is_admin)->toBeFalse();
})->group('USR-001');

test('admins cannot change their own admin access', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->patch(route('users.update', $admin), ['is_admin' => false])
        ->assertForbidden();

    expect($admin->fresh()->is_admin)->toBeTrue();
})->group('USR-001');

test('non-admins cannot grant admin access', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('users.update', $user), ['is_admin' => true])
        ->assertForbidden();

    expect($user->fresh()->is_admin)->toBeFalse();
})->group('USR-001');

test('is_admin cannot be set through profile updates', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patch(route('profile.update'), [
        'name' => $user->name,
        'email' => $user->email,
        'is_admin' => true,
    ]);

    expect($user->fresh()->is_admin)->toBeFalse();
})->group('USR-001');
