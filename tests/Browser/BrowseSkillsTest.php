<?php

use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\SkillDocument;
use Illuminate\Support\Facades\Http;

test('the dev user searches skills.sh, reads a skill and adds it to the project', function () {
    Http::fake([
        'skills.sh/api/search*' => Http::response(['skills' => [
            ['id' => 'anthropics/skills/pdf', 'source' => 'anthropics/skills', 'skillId' => 'pdf', 'name' => 'pdf', 'installs' => 239605],
        ]]),
        'skills.sh/api/download/anthropics/skills/pdf' => Http::response(['files' => [
            ['path' => 'SKILL.md', 'contents' => SkillDocument::compose('pdf', 'Fills in PDF forms.', '# Fill a PDF form')],
            ['path' => 'scripts/fill.py', 'contents' => 'print(1)'],
        ]]),
    ]);

    $user = User::factory()->has(AgentConnection::factory())->create(['name' => 'Dev User', 'email' => 'dev@example.com']);
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['preview_url' => null]);
    $this->actingAs($user);

    $page = visit("/projects/{$project->id}?tool=skills")
        ->resize(1500, 1000)
        ->click('@tab-tools')
        ->click('@tool-skills')
        ->assertVisible('@skills-panel')
        ->click('@skills-add')
        ->click('@skills-add-browse')
        ->assertSee('Type at least two characters to search.')
        ->type('@skill-browse-search', 'pdf')
        ->assertSeeIn('@skill-browse-results', 'anthropics/skills')
        ->assertSeeIn('@skill-browse-results', '239.6K installs')
        ->click('@skill-browse-result')
        ->assertSeeIn('@skill-browse-description', 'Fills in PDF forms.')
        ->assertSee('scripts/fill.py')
        ->click('@skill-browse-add-detail')
        ->assertSeeIn('@skill-pdf', 'Fills in PDF forms.')
        ->assertAttribute('@skill-switch-pdf', 'aria-checked', 'true');

    $page->assertNoJavaScriptErrors();

    expect($project->skills()->pluck('name')->all())->toBe(['pdf']);
})->group('SKILL-005');
