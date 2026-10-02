<?php

use App\Jobs\ApplyRegistryTemplate;
use App\Jobs\CreateSandbox;
use App\Jobs\RunAgentTask;
use App\Jobs\UpdateProjectIcon;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    AgentConnection::factory()->for(User::where('email', 'dev@example.com')->sole())->create();
});

test('searching the free apps finds a Dokploy app, shows its details, and using it starts the project named after it', function () {
    config(['sandbox.provider' => 'docker', 'sandbox.providers.docker.nested_docker' => 'privileged']);
    Queue::fake();

    $page = visit('/login')
        ->fill('email', 'dev@example.com')
        ->fill('password', 'password')
        ->press('@login-button')
        ->assertSee('Or install a free app')
        ->assertSeeIn('@free-apps', 'open source low-code platform')
        ->assertSeeIn('@free-apps', 'professional pub')
        ->fill('@app-search-input', 'workflows')
        ->assertSeeIn('@free-apps', 'low-code platform')
        ->assertDontSeeIn('@free-apps', 'professional pub')
        ->keys('@app-search-input', 'Enter')
        ->assertSeeIn('@app-details', 'Version 1.104.0')
        ->assertSeeIn('@app-details', 'What happens when you use it')
        ->assertVisible('[data-test="app-details"] a[href="https://github.com/n8n-io/n8n"]')
        ->click('@app-details-use')
        ->assertSee('Your app will appear here in a moment.')
        ->assertNoJavaScriptErrors();

    $project = Project::sole();

    $page->assertSeeIn('[data-sidebar="sidebar"]', 'n8n');

    expect($project->name)->toBe('n8n')
        ->and($project->prompt)->toBe('Set up n8n: n8n is an open source low-code platform for automating workflows and integrations.');
    Queue::assertPushedWithChain(CreateSandbox::class, [ApplyRegistryTemplate::class, RunAgentTask::class, UpdateProjectIcon::class]);
})->group('PRJ-012');

test('pressing Cmd or Ctrl K from the prompt jumps to the free apps search', function () {
    visit('/login')
        ->fill('email', 'dev@example.com')
        ->fill('password', 'password')
        ->press('@login-button')
        ->assertSeeIn('@free-apps', 'low-code platform')
        ->assertVisible('@app-search-shortcut')
        ->fill('@app-search-input', 'workflows')
        ->click('#composer-prompt')
        ->keys('#composer-prompt', 'ControlOrMeta+k')
        ->assertScript("document.activeElement === document.querySelector('[data-test=\"app-search-input\"]')")
        ->assertScript('(() => { const input = document.activeElement; return input.selectionStart === 0 && input.selectionEnd === input.value.length; })()')
        ->assertValue('#composer-prompt', '')
        ->assertNoJavaScriptErrors();
})->group('PRJ-012');

test('registry templates can\'t be picked when sandboxes can\'t run Docker', function () {
    visit('/login')
        ->fill('email', 'dev@example.com')
        ->fill('password', 'password')
        ->press('@login-button')
        ->assertSee('These run with Docker.')
        ->fill('@app-search-input', 'n8n')
        ->assertSeeIn('@free-apps', 'low-code platform')
        ->keys('@app-search-input', 'Enter')
        ->assertSeeIn('@app-details', 'Free and open source')
        ->assertVisible('@app-details-unavailable')
        ->assertVisible('[data-test="app-details-use"]:disabled')
        ->assertValue('#composer-prompt', '')
        ->click('[data-test="app-details-unavailable"] [data-test="turn-on-docker-link"]')
        ->assertPathIs('/admin/sandboxes')
        ->assertNoJavaScriptErrors();
})->group('PRJ-012');

test('a member is told to ask an admin to turn on Docker, with no link to settings', function () {
    AgentConnection::factory()->for(User::where('email', 'sam@example.com')->sole())->create();

    visit('/login')
        ->fill('email', 'sam@example.com')
        ->fill('password', 'password')
        ->press('@login-button')
        ->assertSee('These run with Docker. An admin can turn on Docker inside sandboxes in Settings → Sandboxes.')
        ->assertMissing('@turn-on-docker-link')
        ->assertNoJavaScriptErrors();
})->group('PRJ-012');

test('a free app suggested for what they typed opens its details, and using it starts the project with what they typed', function () {
    config(['sandbox.provider' => 'docker', 'sandbox.providers.docker.nested_docker' => 'privileged']);
    Queue::fake();

    visit('/login')
        ->fill('email', 'dev@example.com')
        ->fill('password', 'password')
        ->press('@login-button')
        ->assertSeeIn('@free-apps', 'low-code platform')
        ->fill('#composer-prompt', 'n8n for our workflows')
        ->assertSeeIn('@already-built', 'Free app')
        ->click('@already-built-option')
        ->assertSeeIn('@app-details', 'What happens when you use it')
        ->click('@app-details-use')
        ->assertSee('Your app will appear here in a moment.')
        ->assertNoJavaScriptErrors();

    expect(Project::sole()->prompt)->toBe("Set up n8n: n8n is an open source low-code platform for automating workflows and integrations.\n\nn8n for our workflows");
})->group('PRJ-001', 'PRJ-012');

test('on a phone, the free apps fit the screen and their details fill it', function () {
    config(['sandbox.provider' => 'docker', 'sandbox.providers.docker.nested_docker' => 'privileged', 'sandbox.template_registries.dokploy.url' => 'https://dokploy-long.test']);
    Http::fake(['dokploy-long.test/meta.json' => Http::response([
        ['id' => 'longword', 'name' => 'Longword', 'description' => 'Handles '.str_repeat('supercalifragilistic', 6).' files.', 'links' => []],
        ['id' => 'ghost', 'name' => 'Ghost', 'description' => 'A professional publishing platform.', 'links' => []],
    ])]);

    visit('/login')->on()->mobile()
        ->fill('email', 'dev@example.com')
        ->fill('password', 'password')
        ->press('@login-button')
        ->assertSeeIn('@free-apps', 'Longword')
        ->assertScript("(() => { const grid = document.querySelector('[data-test=\"free-apps\"]').getBoundingClientRect(); return [...document.querySelectorAll('[data-test=\"free-apps\"] > *')].every((card) => card.getBoundingClientRect().right <= grid.right + 1); })()")
        ->click('Longword')
        ->assertVisible('@app-details')
        ->assertScript("(() => { const box = document.querySelector('[data-test=\"app-details\"]').getBoundingClientRect(); return box.left === 0 && box.top === 0 && Math.round(box.width) === window.innerWidth && Math.round(box.height) === window.innerHeight; })()")
        ->assertMissing('@app-screenshots')
        ->assertNoJavaScriptErrors();
})->group('PRJ-012');
