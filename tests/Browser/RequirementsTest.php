<?php

use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\ExecResult;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;

/** A project whose sandbox has $requirements in .onedrop/REQ.md (null: not written yet). */
function requirementsProject(?string $requirements): Project
{
    $provider = new FakeSandboxProvider;
    $provider->execUsing = fn (array $command) => match (true) {
        $command === ['test', '-f', '/workspace/.onedrop/REQ.md'] => new ExecResult($requirements === null ? 1 : 0, ''),
        $command[0] === 'head' && end($command) === '/workspace/.onedrop/REQ.md' => new ExecResult(0, (string) $requirements),
        default => new ExecResult(0, ''),
    };
    app()->instance(SandboxProvider::class, $provider);

    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['preview_url' => null, 'shell_url' => null]);
    test()->actingAs($user);

    return $project;
}

test('the requirements tab shows what the agent wrote down, and tracking can be turned off', function () {
    $project = requirementsProject("# Requirements\n\n## Sign-in\n\n### REQ-001: Sign in with Google\n- User should be able to sign in with Google.\n\n### Decisions\n- **2026-09-30: Only staff emails.** It's an internal app.\n");

    visit("/projects/{$project->id}")
        ->click('@add-tab')
        ->click('@add-tab-requirements')
        ->assertSeeIn('@requirements-content', 'REQ-001: Sign in with Google')
        ->assertSeeIn('@requirements-content', 'User should be able to sign in with Google.')
        ->assertSeeIn('@requirements-content', 'Only staff emails.')
        ->assertAttribute('@requirements-toggle', 'aria-checked', 'true')
        ->click('@requirements-toggle')
        ->assertAttribute('@requirements-toggle', 'aria-checked', 'false')
        ->assertSeeIn('@requirements-view', "Off: the agent won't add to these or write tests for them.")
        ->assertVisible('@tab-requirements')
        ->assertNoJavaScriptErrors();

    expect($project->fresh()->track_requirements)->toBeFalse();
})->group('REQ-001', 'REQ-002');

test('the requirements tab explains itself before the agent has written any', function () {
    $project = requirementsProject(null);

    visit("/projects/{$project->id}?tab=requirements")
        ->assertSeeIn('@requirements-empty', 'No requirements yet')
        ->assertNoJavaScriptErrors();
})->group('REQ-001');
