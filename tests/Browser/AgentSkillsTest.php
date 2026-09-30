<?php

use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\Skill;
use App\Models\User;
use App\Sandbox\ExecResult;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use App\Sandbox\SandboxSkills;
use App\Sandbox\SkillDocument;

test('the dev user writes a skill, turns a shared one on, shares theirs, and sees the project\'s skills', function () {
    $provider = new FakeSandboxProvider;
    $provider->execUsing = function (array $command, array $env) {
        $request = json_decode($env['APP_SKILLS_REQUEST'] ?? '{}', true);

        return new ExecResult(0, json_encode(match ([$command, $request['op'] ?? null]) {
            [['php', SandboxSkills::SCRIPT], 'list'] => ['ok' => true, 'data' => ['skills' => [
                ['folder' => 'deploy', 'path' => '.agents/skills/deploy', 'head' => SkillDocument::compose('deploy', 'Deploys the app to staging.', 'Steps')],
            ]]],
            [['php', SandboxSkills::SCRIPT], 'export'] => ['ok' => true, 'data' => ['files' => [
                ['path' => 'SKILL.md', 'data' => base64_encode(SkillDocument::compose('deploy', 'Deploys the app to staging.', '# Deploy steps'))],
            ]]],
            default => [],
        }));
    };
    app()->instance(SandboxProvider::class, $provider);

    $user = User::factory()->has(AgentConnection::factory())->create(['name' => 'Dev User', 'email' => 'dev@example.com']);
    $sam = User::factory()->create(['name' => 'Sam']);
    Skill::factory()->for($sam)->shared()->create(['name' => 'code-review', 'description' => 'Reviews code the way Sam likes.']);
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['preview_url' => null]);
    $this->actingAs($user);

    $page = visit("/projects/{$project->id}?tool=skills")
        ->resize(1500, 1000)
        ->click('@tab-tools')
        ->click('@tool-skills')
        ->assertVisible('@skills-panel')
        ->assertSeeIn('@skill-code-review', 'Shared by Sam')
        ->assertSeeIn('@skill-deploy', 'In the project')
        ->click('@skills-filter-mine')
        ->assertSeeIn('@skills-empty', 'No skills yet')
        ->click('@skills-add')
        ->click('@skills-add-write')
        ->type('@skill-name', 'Release Notes')
        ->type('@skill-description', 'Writes release notes. Use when asked for a changelog.')
        ->type('@skill-instructions', '# Release notes')
        ->click('@skill-save')
        ->assertSeeIn('@skill-release-notes', 'Yours')
        ->assertAttribute('@skill-switch-release-notes', 'aria-checked', 'true')
        ->click('@skills-filter-shared')
        ->click('@skill-switch-code-review')
        ->assertAttribute('@skill-switch-code-review', 'aria-checked', 'true')
        ->click('@skills-filter-mine')
        ->click('@skill-menu-release-notes')
        ->click('@skill-share-release-notes')
        ->assertSeeIn('@skill-release-notes', 'shared with everyone')
        ->click('@skills-view-list')
        ->click('@skills-filter-project')
        ->assertSeeIn('@skill-deploy', 'Deploys the app to staging.')
        ->click('@skill-menu-deploy')
        ->click('@skill-save-deploy')
        ->assertSee('Saved deploy to your skills');

    $page->assertNoJavaScriptErrors();

    expect($user->skills()->pluck('name')->sort()->values()->all())->toBe(['deploy', 'release-notes'])
        ->and($user->skills()->where('name', 'release-notes')->value('shared'))->toBeTrue()
        ->and($project->skills()->pluck('name')->sort()->values()->all())->toBe(['code-review', 'release-notes']);
})->group('SKILL-001', 'SKILL-002', 'SKILL-003');
