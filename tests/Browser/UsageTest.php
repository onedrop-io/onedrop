<?php

use App\Models\AgentConnection;
use App\Models\AgentUsage;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

test('the dev user opens Usage from their menu and switches metric, period and breakdown', function () {
    $this->seed(DatabaseSeeder::class);
    $dev = User::where('email', 'dev@example.com')->sole();
    AgentConnection::factory()->for($dev)->claudeLogin()->create();
    $project = Project::factory()->for($dev)->create(['name' => 'Timer app']);
    AgentUsage::factory()->for($dev)->create(['project_id' => $project->id, 'model' => 'claude-opus-5-5', 'cost' => 12.5, 'input_tokens' => 1_000, 'output_tokens' => 9_000, 'cache_read_tokens' => 90_000, 'cache_write_tokens' => 0]);
    AgentUsage::factory()->openCode()->for($dev)->create(['project_id' => $project->id, 'cost' => 0.5, 'input_tokens' => 500, 'output_tokens' => 500, 'cache_read_tokens' => 0, 'cache_write_tokens' => 0, 'created_at' => now()->subDays(10)]);
    $this->actingAs($dev);

    $page = visit('/projects/'.$project->id)
        ->click('@sidebar-menu-button')
        ->click('@usage-link')
        ->assertPathIs('/usage')
        ->assertSeeIn('@usage-total', '$13.00')
        ->assertSeeIn('@usage-agent-claude_code', 'Claude Code')
        ->assertSeeIn('@usage-agent-claude_code', '96.2% of cost')
        ->assertSeeIn('@usage-breakdown', 'claude-opus-5-5')
        ->assertSeeIn('@usage-breakdown', 'gpt-5.5')
        ->assertSee('Daily cost');

    $page->click('Tokens')
        ->assertSeeIn('@usage-total', '101K')
        ->assertSee('Daily tokens')
        ->click('Project')
        ->assertSeeIn('@usage-breakdown', 'Timer app')
        ->click('7 days')
        ->assertQueryStringHas('range', '7d')
        ->assertSeeIn('@usage-agent-claude_code', 'Claude Code')
        ->assertDontSee('OpenCode')
        ->assertNoJavaScriptErrors();
})->group('USAGE-001');
