<?php

use App\Enums\AppTemplate;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->user = User::factory()->has(AgentConnection::factory())->create();
});

test('the new-project page offers every template', function () {
    $this->actingAs($this->user)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->component('projects/create')
            ->has('templates', count(AppTemplate::cases()))
            ->where('templates.0', [
                'value' => 'crm',
                'label' => 'Sales CRM',
                'description' => AppTemplate::Crm->description(),
                'prompt' => AppTemplate::Crm->prompt(),
            ]));
})->group('PRJ-004');

test('a project started from a template is named after it and keeps the edited prompt', function () {
    Queue::fake();

    $prompt = AppTemplate::Crm->prompt().' Also track which trade show each lead came from.';

    $this->actingAs($this->user)->post(route('projects.store'), [
        'prompt' => $prompt,
        'template' => 'crm',
    ]);

    $project = $this->user->projects()->sole();

    expect($project->name)->toBe('Sales CRM')
        ->and($project->prompt)->toBe($prompt)
        ->and($project->messages()->sole()->content)->toBe($prompt);
})->group('PRJ-004');

test('without a template the project is named from the prompt', function () {
    Queue::fake();

    $this->actingAs($this->user)->post(route('projects.store'), [
        'prompt' => 'a recipe box',
        'template' => '',
    ]);

    expect($this->user->projects()->sole()->name)->toBe('A Recipe Box');
})->group('PRJ-004');

test('an unknown template is rejected', function () {
    $this->actingAs($this->user)
        ->post(route('projects.store'), ['prompt' => 'a recipe box', 'template' => 'spaceship'])
        ->assertSessionHasErrors('template');

    expect(Project::count())->toBe(0);
})->group('PRJ-004');
