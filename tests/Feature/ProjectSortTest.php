<?php

use App\Enums\ProjectSort;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->organization = $this->user->currentOrganization();

    // Created oldest to newest; "Old" was updated last.
    $this->old = Project::factory()->for($this->user)->create(['name' => 'Old', 'created_at' => now()->subDays(3)]);
    $this->middle = Project::factory()->for($this->user)->create(['name' => 'Middle', 'created_at' => now()->subDays(2)]);
    $this->new = Project::factory()->for($this->user)->create(['name' => 'New', 'created_at' => now()->subDay()]);
    Project::withoutTimestamps(fn () => $this->middle->forceFill(['updated_at' => now()->subHours(2)])->save());
    Project::withoutTimestamps(fn () => $this->new->forceFill(['updated_at' => now()->subHours(3)])->save());
    Project::withoutTimestamps(fn () => $this->old->forceFill(['updated_at' => now()->subHour()])->save());
});

/**
 * @return array{pinned: list<array<string, mixed>>, recent: list<array<string, mixed>>, archived: list<array<string, mixed>>, sort: string}
 */
function sortedSidebar(User $user): array
{
    return test()->actingAs($user)->followingRedirects()->get(route('dashboard'))->inertiaProps('sidebarProjects');
}

test('projects are sorted by last updated by default', function () {
    $sidebar = sortedSidebar($this->user);

    expect($sidebar['sort'])->toBe('updated')
        ->and(array_column($sidebar['recent'], 'name'))->toBe(['Old', 'Middle', 'New']);
})->group('PRJ-010');

test('projects can be sorted by when they were created', function () {
    $this->actingAs($this->user)
        ->put(route('projects.sort', $this->organization), ['sort' => 'created'])
        ->assertRedirect();

    expect($this->user->fresh()->project_sort)->toBe(ProjectSort::Created)
        ->and(array_column(sortedSidebar($this->user)['recent'], 'name'))->toBe(['New', 'Middle', 'Old']);
})->group('PRJ-010');

test('the sort must be one the sidebar knows', function () {
    $this->actingAs($this->user)
        ->put(route('projects.sort', $this->organization), ['sort' => 'name'])
        ->assertSessionHasErrors('sort');
})->group('PRJ-010');

test('dragging projects saves their order and sorts manually', function () {
    $this->actingAs($this->user)
        ->put(route('projects.order', $this->organization), ['ids' => [$this->middle->id, $this->new->id, $this->old->id]])
        ->assertRedirect();

    $sidebar = sortedSidebar($this->user);
    expect($sidebar['sort'])->toBe('manual')
        ->and(array_column($sidebar['recent'], 'name'))->toBe(['Middle', 'New', 'Old']);
})->group('PRJ-010');

test('reordering does not change when a project was last updated', function () {
    $updatedAt = $this->old->fresh()->updated_at;

    $this->actingAs($this->user)
        ->put(route('projects.order', $this->organization), ['ids' => [$this->new->id, $this->old->id]]);

    expect($this->old->fresh()->updated_at->equalTo($updatedAt))->toBeTrue();
})->group('PRJ-010');

test('projects left out of a reorder keep their place after the dragged ones', function () {
    // Pinned projects are another list; dragging recent ones mustn't reshuffle them.
    $pinnedA = Project::factory()->for($this->user)->create(['name' => 'Pinned A', 'pinned_at' => now()]);
    $pinnedB = Project::factory()->for($this->user)->create(['name' => 'Pinned B', 'pinned_at' => now()]);
    Project::withoutTimestamps(fn () => $pinnedA->forceFill(['updated_at' => now()->addHour()])->save());

    $this->actingAs($this->user)
        ->put(route('projects.order', $this->organization), ['ids' => [$this->new->id, $this->middle->id, $this->old->id]]);

    $sidebar = sortedSidebar($this->user);
    expect(array_column($sidebar['pinned'], 'name'))->toBe(['Pinned A', 'Pinned B'])
        ->and(array_column($sidebar['recent'], 'name'))->toBe(['New', 'Middle', 'Old']);
})->group('PRJ-010');

test('new projects come first in a manual order', function () {
    $this->actingAs($this->user)
        ->put(route('projects.order', $this->organization), ['ids' => [$this->old->id, $this->middle->id, $this->new->id]]);

    Project::factory()->for($this->user)->create(['name' => 'Newest']);

    expect(array_column(sortedSidebar($this->user)['recent'], 'name'))->toBe(['Newest', 'Old', 'Middle', 'New']);
})->group('PRJ-010');

test('someone else\'s projects can\'t be reordered', function () {
    $other = User::factory()->has(AgentConnection::factory())->create();
    $theirs = Project::factory()->for($other)->create(['organization_id' => $this->organization->id]);

    $this->actingAs($this->user)
        ->put(route('projects.order', $this->organization), ['ids' => [$theirs->id, $this->old->id]]);

    expect($theirs->fresh()->sidebar_position)->toBeNull()
        ->and($other->fresh()->project_sort)->toBe(ProjectSort::Updated);
})->group('PRJ-010');
