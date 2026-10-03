<?php

use App\Enums\BuildMode;
use App\Enums\MessageRole;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

beforeEach(function () {
    $this->user = User::factory()->has(AgentConnection::factory())->create();
});

test('a new user hasn\'t chosen a mode yet, so the new-project page asks', function () {
    $this->actingAs($this->user)
        ->followingRedirects()
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->component('projects/create')->where('auth.user.build_mode', null));
})->group('PRJ-013');

test('a user can choose Simple, then switch to Advanced', function () {
    $this->actingAs($this->user)
        ->put(route('build-mode.update'), ['mode' => 'simple'])
        ->assertRedirect();

    expect($this->user->fresh()->build_mode)->toBe(BuildMode::Simple);

    $this->actingAs($this->user)
        ->followingRedirects()
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('auth.user.build_mode', 'simple'));

    $this->actingAs($this->user)
        ->put(route('build-mode.update'), ['mode' => 'advanced'])
        ->assertRedirect();

    expect($this->user->fresh()->build_mode)->toBe(BuildMode::Advanced);
})->group('PRJ-013');

test('only Simple and Advanced can be chosen', function () {
    $this->actingAs($this->user)
        ->put(route('build-mode.update'), ['mode' => 'expert'])
        ->assertSessionHasErrors('mode');

    expect($this->user->fresh()->build_mode)->toBeNull();
})->group('PRJ-013');

test('guests can\'t choose a mode', function () {
    $this->put(route('build-mode.update'), ['mode' => 'simple'])->assertRedirect(route('login'));
})->group('PRJ-013');

test('the dev user is seeded in Advanced mode', function () {
    $this->seed(DatabaseSeeder::class);

    expect(User::where('email', 'dev@example.com')->sole()->build_mode)->toBe(BuildMode::Advanced);
})->group('PRJ-013');

test('the chat\'s steps come with their plain words, for Simple mode', function () {
    $project = Project::factory()->for($this->user)->create();
    $project->messages()->create(['role' => MessageRole::User, 'content' => 'Add an orders page']);
    $project->messages()->create(['role' => MessageRole::Activity, 'content' => 'Editing resources/js/pages/orders/index.tsx']);
    $project->messages()->create(['role' => MessageRole::Assistant, 'content' => 'Done.']);

    $this->actingAs($this->user)
        ->get(route('projects.show', $project))
        ->assertInertia(fn ($page) => $page
            ->where('messages.0.plain', null)
            ->where('messages.1.content', 'Editing resources/js/pages/orders/index.tsx')
            ->where('messages.1.plain', 'Building the orders page')
            ->where('messages.2.plain', null));
})->group('PRJ-013');
