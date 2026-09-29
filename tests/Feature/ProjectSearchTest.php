<?php

use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->has(AgentConnection::factory())->create();
});

test('search finds the user\'s projects by name, case-insensitively, newest first', function () {
    Project::factory()->for($this->user)->create(['name' => 'Todo App', 'updated_at' => now()->subDay()]);
    $archived = Project::factory()->for($this->user)->create(['name' => 'Old todos', 'archived_at' => now()]);
    Project::factory()->for($this->user)->create(['name' => 'Blog']);
    Project::factory()->create(['name' => 'Someone else\'s todo']);

    $this->actingAs($this->user)
        ->getJson(route('projects.search', ['q' => 'TODO']))
        ->assertOk()
        ->assertJsonPath('projects.*.name', ['Old todos', 'Todo App'])
        ->assertJsonPath('projects.0', ['id' => $archived->id, 'name' => 'Old todos', 'archived' => true]);
})->group('PRJ-005');

test('an empty search lists the user\'s most recent projects', function () {
    Project::factory()->for($this->user)->count(25)->create();
    Project::factory()->create();

    $this->actingAs($this->user)
        ->getJson(route('projects.search'))
        ->assertOk()
        ->assertJsonCount(20, 'projects');
})->group('PRJ-005');

test('guests cannot search projects', function () {
    $this->getJson(route('projects.search', ['q' => 'todo']))->assertUnauthorized();
})->group('PRJ-005');
