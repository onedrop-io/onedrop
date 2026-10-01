<?php

use App\Enums\ProjectStatus;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->has(AgentConnection::factory())->create();
});

test('the notifications settings page opens in the settings modal', function () {
    $this->actingAs($this->user)
        ->get(route('notifications.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/notifications'));
})->group('NOTIF-001');

test('the sidebar says which projects have an agent working', function () {
    Project::factory()->for($this->user)->create(['name' => 'Busy', 'status' => ProjectStatus::Working]);
    Project::factory()->for($this->user)->create(['name' => 'Done', 'status' => ProjectStatus::Idle]);

    $recent = $this->actingAs($this->user)->followingRedirects()->get(route('dashboard'))->inertiaProps('sidebarProjects.recent');

    expect(array_column($recent, 'working', 'name'))->toEqual(['Busy' => true, 'Done' => false]);
})->group('NOTIF-001');
