<?php

use App\Models\AgentConnection;
use App\Models\Impersonation;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('admins can sign in as a user and back as themselves, and it is recorded', function () {
    $admin = User::factory()->admin()->create(['name' => 'Ada Admin']);
    $user = User::factory()->has(AgentConnection::factory())->create();

    $this->actingAs($admin)->post(route('users.impersonate', $user))->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
    $impersonation = Impersonation::sole();
    expect($impersonation)
        ->admin_id->toBe($admin->id)
        ->user_id->toBe($user->id)
        ->ended_at->toBeNull()
        ->and($user->fresh()->last_login_at)->toBeNull();

    $this->followingRedirects()->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('impersonator', ['id' => $admin->id, 'name' => 'Ada Admin']));

    $this->delete(route('impersonation.destroy'))->assertRedirect(route('users.show', $user));

    $this->assertAuthenticatedAs($admin);
    expect($impersonation->fresh()->ended_at)->not->toBeNull();

    $this->actingAs($admin)->get(route('users.show', $user))
        ->assertInertia(fn (Assert $page) => $page->where('impersonator', null)->where('impersonations.0.admin', 'Ada Admin'));
})->group('USR-003');

test('admins cannot impersonate themselves or another admin', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post(route('users.impersonate', $admin))->assertForbidden();
    $this->actingAs($admin)->post(route('users.impersonate', User::factory()->admin()->create()))->assertForbidden();

    expect(Impersonation::count())->toBe(0);
})->group('USR-003');

test('non-admins cannot impersonate', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('users.impersonate', User::factory()->create()))
        ->assertForbidden();

    expect(Impersonation::count())->toBe(0);
})->group('USR-003');

test('while impersonating, the user\'s sign-in settings and account cannot be changed', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    $this->actingAs($admin)->post(route('users.impersonate', $user));

    $this->put(route('user-password.update'), ['current_password' => 'password', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])->assertForbidden();
    $this->patch(route('profile.update'), ['name' => 'Hacked', 'email' => 'hacked@example.com'])->assertForbidden();
    $this->delete(route('profile.destroy'), ['password' => 'password'])->assertForbidden();
    $this->post(route('two-factor.enable'))->assertForbidden();

    expect($user->fresh())->name->not->toBe('Hacked')->email->not->toBe('hacked@example.com');
})->group('USR-003');

test('signing out while impersonating ends it', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    $this->actingAs($admin)->post(route('users.impersonate', $user));

    $this->post(route('logout'));

    $this->assertGuest();
    expect(Impersonation::sole()->ended_at)->not->toBeNull();
})->group('USR-003');

test('stopping without impersonating is not found', function () {
    $this->actingAs(User::factory()->create())->delete(route('impersonation.destroy'))->assertNotFound();
})->group('USR-003');
