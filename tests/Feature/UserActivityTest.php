<?php

use App\Http\Middleware\RecordUserActivity;
use App\Models\Impersonation;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('opening a page records when the user was last active', function () {
    $user = User::factory()->create(['last_active_at' => null]);

    $this->actingAs($user)->get(route('profile.edit'))->assertSuccessful();

    expect($user->fresh()->last_active_at)->not->toBeNull();
})->group('USR-004');

test('activity is written at most every five minutes', function () {
    $user = User::factory()->create(['last_active_at' => now()->subMinutes(2)]);
    $before = $user->last_active_at;

    $this->actingAs($user)->get(route('profile.edit'));
    expect($user->fresh()->last_active_at->timestamp)->toBe($before->timestamp);

    $this->travel(RecordUserActivity::EVERY_SECONDS)->seconds();
    $this->actingAs($user)->get(route('profile.edit'));
    expect($user->fresh()->last_active_at->gt($before))->toBeTrue();
})->group('USR-004');

test('background refreshes do not count as activity', function (array $headers) {
    $user = User::factory()->create(['last_active_at' => null]);

    $this->actingAs($user)->get(route('profile.edit'), $headers);

    expect($user->fresh()->last_active_at)->toBeNull();
})->with([
    'partial reload' => [['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'settings/profile', 'X-Inertia-Partial-Data' => 'user']],
    'prefetch' => [['X-Inertia' => 'true', 'Purpose' => 'prefetch']],
    'json poll' => [['Accept' => 'application/json']],
])->group('USR-004');

test('an admin impersonating a user does not count as their activity', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create(['last_active_at' => null]);
    $impersonation = Impersonation::query()->create(['admin_id' => $admin->id, 'user_id' => $user->id, 'started_at' => now()]);

    $this->actingAs($user)->withSession([Impersonation::SESSION_KEY => $impersonation->id])->get(route('profile.edit'));

    expect($user->fresh()->last_active_at)->toBeNull();
})->group('USR-004');

test('admins see users most recently active first, with when they were last active', function () {
    $admin = User::factory()->admin()->create(['name' => 'Admin', 'last_active_at' => now()]);
    User::factory()->create(['name' => 'Never', 'last_active_at' => null]);
    User::factory()->create(['name' => 'Last week', 'last_active_at' => now()->subWeek()]);
    $recent = User::factory()->create(['name' => 'Recent', 'last_active_at' => now()->subHour()]);

    $this->actingAs($admin)
        ->get(route('users.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('users.0.name', 'Admin')
            ->where('users.1.name', 'Recent')
            ->where('users.1.last_active_at', $recent->last_active_at->toIso8601String())
            ->where('users.2.name', 'Last week')
            ->where('users.3.name', 'Never')
            ->where('users.3.last_active_at', null));
})->group('USR-004');
