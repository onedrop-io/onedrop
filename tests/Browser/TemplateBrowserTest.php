<?php

use App\Jobs\ApplyRegistryTemplate;
use App\Jobs\CreateSandbox;
use App\Jobs\RunAgentTask;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    AgentConnection::factory()->for(User::where('email', 'dev@example.com')->sole())->create();
});

test('browsing all templates finds a Dokploy app, fills in the prompt and names the project after it', function () {
    config(['sandbox.provider' => 'docker', 'sandbox.providers.docker.nested_docker' => 'privileged']);
    Queue::fake();

    $page = visit('/login')
        ->fill('email', 'dev@example.com')
        ->fill('password', 'password')
        ->press('@login-button')
        ->assertSee('Popular open-source apps')
        ->assertSeeIn('@popular-templates', 'open source low-code platform')
        ->click('@browse-templates')
        ->assertSee('Sales CRM')
        ->assertSee('Ghost')
        ->fill('@template-search-input', 'workflows')
        ->assertSee('n8n')
        ->assertDontSee('Ghost')
        ->keys('@template-search-input', 'Enter')
        ->assertValue('#composer-prompt', 'Set up n8n: n8n is an open source low-code platform for automating workflows and integrations.')
        ->assertVisible('[data-test="popular-templates"] [aria-pressed="true"]')
        ->press('@composer-send')
        ->assertSee('Your app will appear here in a moment.')
        ->assertNoJavaScriptErrors();

    $project = Project::sole();

    $page->assertSeeIn('[data-sidebar="sidebar"]', 'n8n');

    expect($project->name)->toBe('n8n');
    Queue::assertPushedWithChain(CreateSandbox::class, [ApplyRegistryTemplate::class, RunAgentTask::class]);
})->group('PRJ-012');

test('registry templates can\'t be picked when sandboxes can\'t run Docker', function () {
    visit('/login')
        ->fill('email', 'dev@example.com')
        ->fill('password', 'password')
        ->press('@login-button')
        ->assertSee('These run with Docker.')
        ->click('@browse-templates')
        ->fill('@template-search-input', 'n8n')
        ->assertSee('Needs Docker inside sandboxes.')
        ->keys('@template-search-input', 'Enter')
        ->assertVisible('@template-search-input')
        ->assertValue('#composer-prompt', '')
        ->assertNoJavaScriptErrors();
})->group('PRJ-012');
