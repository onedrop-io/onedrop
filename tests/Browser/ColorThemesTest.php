<?php

use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    AgentConnection::factory()->for(User::where('email', 'dev@example.com')->sole())->create();
});

test('the dev user picks a color theme for dark and for light mode, and keeps it after a reload', function () {
    $this->actingAs(User::where('email', 'dev@example.com')->sole());

    $page = visit('/dashboard')
        ->click('@sidebar-menu-button')
        ->click('@theme-menu')
        ->assertSeeIn('@color-theme-link', 'OneDrop Dark')
        ->click('@color-theme-link')
        ->assertVisible('@settings-modal')
        ->assertSee('Dark themes')
        ->assertSee('Light themes')
        ->click('@theme-dracula');

    expect($page->script('document.documentElement.dataset.theme'))->toBe('dracula')
        ->and($page->script('document.documentElement.classList.contains("dark")'))->toBeTrue()
        ->and($page->script('getComputedStyle(document.body).backgroundColor'))->toBe('rgb(40, 42, 54)');

    // A light theme switches to light mode and keeps Dracula for dark.
    $page->click('@theme-github-light');

    expect($page->script('document.documentElement.dataset.theme'))->toBe('github-light')
        ->and($page->script('document.documentElement.classList.contains("dark")'))->toBeFalse();

    $page->refresh();

    expect($page->script('document.documentElement.dataset.theme'))->toBe('github-light');

    $page->click('Dark');

    expect($page->script('document.documentElement.dataset.theme'))->toBe('dracula');

    $page->assertNoJavaScriptErrors();
})->group('SET-002');

test('the shell takes the color theme\'s terminal palette, and follows a change', function () {
    $user = User::where('email', 'dev@example.com')->sole();
    $project = Project::factory()->for($user)->create();

    // A Shell on another origin running the sandbox's real shell-keys.js, with a stand-in for ttyd's xterm.
    $shell = '<!doctype html><html><head><script>'.file_get_contents(base_path('docker/sandbox/shell-keys.js'))
        .'</script></head><body><div id="bg"></div><script>window.term = { options: {} };'
        .'setInterval(() => { document.getElementById("bg").textContent = window.term.options.theme?.background ?? "none"; }, 50);'
        .'</script></body></html>';
    Sandbox::factory()->for($project)->create(['preview_url' => null, 'shell_url' => 'data:text/html,'.rawurlencode($shell)]);
    $this->actingAs($user);

    $page = visit('/settings/appearance')
        ->click('@theme-tokyo-night')
        ->navigate("/projects/{$project->id}")
        ->click('@tab-shell');

    $page->withinFrame('[data-test="shell-frame"]', function ($frame) {
        $frame->assertSeeIn('#bg', '#16161e');
    });

    $page->click('@sidebar-menu-button')
        ->click('@theme-menu')
        ->click('Light');

    $page->withinFrame('[data-test="shell-frame"]', function ($frame) {
        $frame->assertSeeIn('#bg', '#0a0a0a');
    });

    $page->assertNoJavaScriptErrors();
})->group('SET-002');
