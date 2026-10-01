<?php

use App\Models\AgentConnection;
use App\Models\AgentUsage;
use App\Models\GitHubAuthorization;
use App\Models\Invitation;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\Skill;
use App\Models\SocialAccount;
use App\Models\SshKey;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

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

test('admins can see everything about a user, without their secrets', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create(['name' => 'Recipe box']);
    Sandbox::factory()->for($project)->create();
    AgentConnection::factory()->for($user)->create(['credential' => 'sk-secret-key']);
    AgentUsage::factory()->for($user)->for($project)->create(['input_tokens' => 100, 'output_tokens' => 50, 'cost' => 1.25]);
    SocialAccount::factory()->for($user)->create();
    SshKey::factory()->for($user)->create();
    Skill::factory()->for($user)->create();
    GitHubAuthorization::factory()->for($user)->create(['github_login' => 'octocat']);

    $response = $this->actingAs($admin)->get(route('users.show', $user));

    $response->assertInertia(fn ($page) => $page->component('users/show')
        ->where('user.email', $user->email)
        ->has('organizations', 1)
        // The user's organizations don't replace the admin's own in the switcher.
        ->where('userOrganizations.0.id', $admin->currentOrganization()->id)
        ->has('projects', 1)
        ->where('projects.0.name', 'Recipe box')
        ->where('projects.0.sandbox.status', 'running')
        ->has('aiConnections', 1)
        ->has('signInMethods', 1)
        ->has('sshKeys', 1)
        ->has('skills', 1)
        ->where('github.login', 'octocat')
        ->where('lifetimeUsage.runs', 1)
        ->where('lifetimeUsage.input', 100)
        ->where('lifetimeUsage.cost', 1.25)
        ->where('usage.totals.cost', 1.25)
        ->where('usage.models.0.name', 'claude-sonnet-5')
        ->where('usage.projects.0.name', 'Recipe box')
    );

    expect($response->content())->not->toContain('sk-secret-key');
})->group('USR-002');

test('non-admins cannot see a user\'s details', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('users.show', User::factory()->create()))
        ->assertForbidden();
})->group('USR-002');

test('a user\'s page shows who invited them, the invites they sent, and their projects\' errors', function () {
    $admin = User::factory()->admin()->create();
    $inviter = User::factory()->create(['name' => 'Ines Inviter']);
    $user = User::factory()->create();
    Invitation::factory()->for($inviter, 'inviter')->create(['accepted_by' => $user->id, 'accepted_at' => now()]);
    Invitation::factory()->for($user, 'inviter')->create(['email' => 'friend@example.com']);
    $project = Project::factory()->for($user)->create(['publish_error' => 'Certificate failed', 'git_sync_error' => 'Push rejected']);
    Sandbox::factory()->for($project)->create(['error' => 'Out of memory']);

    $this->actingAs($admin)->get(route('users.show', $user))->assertInertia(fn (Assert $page) => $page
        ->where('invitedBy.name', 'Ines Inviter')
        ->has('invitationsSent', 1)
        ->where('invitationsSent.0.email', 'friend@example.com')
        ->where('invitationsSent.0.status', 'waiting')
        ->where('projects.0.publish_error', 'Certificate failed')
        ->where('projects.0.git_sync_error', 'Push rejected')
        ->where('projects.0.sandbox.error', 'Out of memory')
    );
})->group('USR-002');

test('signing in with a password records when and how', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    expect($user->fresh())
        ->last_login_at->not->toBeNull()
        ->last_login_method->toBe('password');
})->group('USR-002');

test('admins can sign a user out everywhere', function () {
    config(['session.driver' => 'database']);
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create(['remember_token' => 'old-token']);
    DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->getTimestamp()]);

    $this->actingAs($admin)->post(route('users.sign-out', $user))->assertRedirect(route('users.show', $user));

    expect(DB::table('sessions')->where('user_id', $user->id)->exists())->toBeFalse()
        ->and($user->fresh()->remember_token)->not->toBe('old-token');
})->group('USR-002');

test('admins can reset a user\'s two-factor sign-in', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('secret'),
        'two_factor_recovery_codes' => encrypt('[]'),
        'two_factor_confirmed_at' => now(),
    ]);

    $this->actingAs($admin)->delete(route('users.two-factor.destroy', $user))->assertRedirect(route('users.show', $user));

    expect($user->fresh())
        ->two_factor_secret->toBeNull()
        ->two_factor_recovery_codes->toBeNull()
        ->two_factor_confirmed_at->toBeNull();
})->group('USR-002');

test('admins cannot use the support actions on themselves', function () {
    $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);

    $this->actingAs($admin)->post(route('users.sign-out', $admin))->assertForbidden();
    $this->actingAs($admin)->delete(route('users.two-factor.destroy', $admin))->assertForbidden();

    expect($admin->fresh()->two_factor_confirmed_at)->not->toBeNull();
})->group('USR-002');

test('non-admins cannot use the support actions', function () {
    $user = User::factory()->create(['two_factor_confirmed_at' => now()]);

    $this->actingAs(User::factory()->create())->post(route('users.sign-out', $user))->assertForbidden();
    $this->actingAs(User::factory()->create())->delete(route('users.two-factor.destroy', $user))->assertForbidden();

    expect($user->fresh()->two_factor_confirmed_at)->not->toBeNull();
})->group('USR-002');
