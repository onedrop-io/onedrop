<?php

use App\Models\AgentConnection;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    AgentConnection::factory()->for(User::where('email', 'dev@example.com')->sole())->create();
});

test('a template or a free app picked on the home page is waiting on the new-project page', function () {
    config(['sandbox.provider' => 'docker', 'sandbox.providers.docker.nested_docker' => 'privileged']);

    visit('/login')
        ->fill('email', 'dev@example.com')
        ->fill('password', 'password')
        ->press('@login-button')
        ->assertSee('Or install a free app');

    visit('/')
        ->assertSeeIn('@home-start', 'Start from scratch')
        ->click('Sales CRM')
        ->keys('#composer-prompt', 'End')
        ->assertSeeIn('[data-test="home-start"] [data-test="free-apps"]', 'low-code platform')
        ->keys('#composer-prompt', 'Enter')
        ->assertSee('what are we working on today?')
        ->assertScript("document.querySelector('#composer-prompt').value.length > 100")
        ->assertNoJavaScriptErrors();

    visit('/')
        ->assertSeeIn('[data-test="home-start"] [data-test="free-apps"]', 'Version 1.104.0')
        ->keys('#composer-prompt', 'ControlOrMeta+k')
        ->assertScript("document.activeElement === document.querySelector('[data-test=\"home-start\"] [data-test=\"app-search-input\"]')")
        ->fill('@app-search-input', 'workflows')
        ->keys('@app-search-input', 'Enter')
        ->assertSeeIn('@app-details', 'starts your project right away')
        ->click('@app-details-use')
        ->assertSee('what are we working on today?')
        ->assertSeeIn('[data-test="app-details"] h2', 'n8n')
        ->assertNoJavaScriptErrors();
})->group('HOME-004');

test('a visitor using a free app on the home page is told they\'ll create an account first', function () {
    config(['sandbox.provider' => 'docker', 'sandbox.providers.docker.nested_docker' => 'privileged']);

    visit('/')
        ->fill('@app-search-input', 'n8n')
        ->keys('@app-search-input', 'Enter')
        ->assertSeeIn('@app-details', 'asks you to create a free account')
        ->click('@app-details-use')
        ->assertPathIs('/register')
        ->assertSeeIn('@pending-start-name', 'n8n')
        ->assertSeeIn('@pending-start', 'Create your free account')
        ->assertSeeIn('@pending-start', 'We set up n8n for you')
        ->click('@pending-start-dismiss')
        ->assertMissing('@pending-start')
        ->assertSee('Create an account')
        ->assertNoJavaScriptErrors();
})->group('HOME-004');

test('a visitor who picks a free app, then logs in, is told on the AI onboarding what it\'s for', function () {
    config(['sandbox.provider' => 'docker', 'sandbox.providers.docker.nested_docker' => 'privileged']);

    visit('/')
        ->fill('@app-search-input', 'n8n')
        ->keys('@app-search-input', 'Enter')
        ->click('@app-details-use')
        ->assertPathIs('/register');

    visit('/login')
        ->assertSeeIn('@pending-start-name', 'n8n')
        ->assertSeeIn('@pending-start', 'Log in')
        ->fill('email', 'sam@example.com')
        ->fill('password', 'password')
        ->press('@login-button')
        ->assertPathIs('/onboarding/ai')
        ->assertSeeIn('@pending-start-next', 'Next: we set up n8n for you.')
        ->assertNoJavaScriptErrors();
})->group('HOME-004');

test('the home page lists the first few free apps until asked for all, and search finds the rest', function () {
    config(['sandbox.provider' => 'docker', 'sandbox.providers.docker.nested_docker' => 'privileged', 'sandbox.template_registries.dokploy.url' => 'https://dokploy-many.test']);
    Http::fake(['dokploy-many.test/meta.json' => Http::response(collect(range(1, 14))->map(fn (int $number) => [
        'id' => "app-{$number}", 'name' => "App Number {$number}", 'description' => "The app numbered {$number}.", 'links' => [],
    ])->all())]);

    visit('/')
        ->assertSeeIn('[data-test="home-start"] [data-test="free-apps"]', 'App Number 12')
        ->assertDontSeeIn('[data-test="home-start"] [data-test="free-apps"]', 'App Number 14')
        ->fill('@app-search-input', 'numbered 14')
        ->assertSeeIn('[data-test="home-start"] [data-test="free-apps"]', 'App Number 14')
        ->fill('@app-search-input', '')
        ->click('Show all 14 apps')
        ->assertSeeIn('[data-test="home-start"] [data-test="free-apps"]', 'App Number 14')
        ->assertNoJavaScriptErrors();
})->group('HOME-004');
