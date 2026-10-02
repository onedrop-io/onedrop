<?php

use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

test('the chat can be hidden and shown again, keeping its draft, and stays hidden after a reload', function () {
    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['preview_url' => null]);
    $this->actingAs($user);

    visit("/projects/{$project->id}")
        ->resize(1600, 900)
        ->assertVisible('section[aria-label="Chat"]')
        ->type('#composer-content', 'half a thought')
        ->click('@toggle-chat')
        ->assertMissing('section[aria-label="Chat"]')
        ->assertMissing('@chat-resize')
        ->click('@toggle-chat')
        ->assertVisible('section[aria-label="Chat"]')
        ->assertValue('#composer-content', 'half a thought')
        ->click('@toggle-chat')
        ->navigate("/projects/{$project->id}")
        ->assertMissing('section[aria-label="Chat"]')
        ->assertVisible('@tab-preview')
        ->click('@toggle-chat')
        ->assertVisible('section[aria-label="Chat"]')
        ->assertNoJavaScriptErrors();
})->group('LAYOUT-001');

test('the workspace splits into panes with their own shells, and tabs move between them without reloading', function () {
    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['preview_url' => null, 'shell_url' => 'about:blank']);
    $this->actingAs($user);

    $tabsIn = fn (int $pane) => "Array.from(document.querySelectorAll('[data-test=\"pane-{$pane}\"] button[data-test^=tab-]')).map((tab) => tab.dataset.test).join(',')";
    // Set on a Shell's page; a Shell that reloaded (a new session) loses it.
    $mark = 'document.querySelector(\'[data-test="shell-frame-3"]\').contentWindow.marked = true';
    $marked = 'document.querySelector(\'[data-test="shell-frame-3"]\')?.contentWindow?.marked === true';

    $page = visit("/projects/{$project->id}")
        ->resize(1600, 900)
        ->click('@split-menu')
        ->click('@split-right')
        ->assertPresent('@pane-2')
        ->assertScript($tabsIn(1), 'tab-tools,tab-preview,tab-shell,tab-services,tab-console')
        ->assertScript($tabsIn(2), 'tab-shell-2')
        ->assertPresent('@shell-frame-2')
        ->click('[data-test="pane-1"] [data-test="add-tab"]')
        ->click('@add-tab-shell')
        ->assertScript($tabsIn(1), 'tab-tools,tab-preview,tab-shell,tab-services,tab-console,tab-shell-3')
        ->assertSeeIn('@tab-shell-3', 'Shell 3')
        ->assertPresent('@shell-frame-3');

    $page->script($mark);

    $page->drag('@tab-shell-3', '[data-test="pane-2"] [data-test="sandbox-status"]')
        ->assertScript($tabsIn(1), 'tab-tools,tab-preview,tab-shell,tab-services,tab-console')
        ->assertScript($tabsIn(2), 'tab-shell-2,tab-shell-3')
        ->assertScript($marked, true)
        ->wait(0.3) // let the drag finish; a click straight after the drop is lost
        ->click('[data-test="pane-2"] [data-test="split-menu"]')
        ->click('@split-down')
        ->assertScript($tabsIn(3), 'tab-shell-4')
        ->assertScript(
            'document.querySelector(\'[data-test="pane-3"]\').getBoundingClientRect().top > document.querySelector(\'[data-test="pane-1"]\').getBoundingClientRect().bottom',
            true,
        )
        ->click('[data-test="tab-shell-4"] + button')
        ->assertMissing('@pane-3')
        ->assertMissing('@tab-shell-4')
        ->click('[data-test="pane-2"] [data-test="close-pane"]')
        ->assertMissing('@pane-2')
        ->assertScript($tabsIn(1), 'tab-tools,tab-preview,tab-shell,tab-services,tab-console,tab-shell-2,tab-shell-3')
        ->assertScript($marked, true)
        ->assertMissing('@close-pane')
        ->assertNoJavaScriptErrors();
})->group('LAYOUT-002');

test('a reload keeps the panes, tabs, Shell sessions, preview page and chat draft, but a new browser tab starts afresh', function () {
    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['preview_url' => 'http://127.0.0.1:9', 'shell_url' => 'about:blank']);
    $this->actingAs($user);

    $tabsIn = fn (int $pane) => "Array.from(document.querySelectorAll('[data-test=\"pane-{$pane}\"] button[data-test^=tab-]')).map((tab) => tab.dataset.test).join(',')";
    $shellSrc = 'document.querySelector(\'[data-test="shell-frame"]\').getAttribute("src")';
    $previewSrc = 'document.querySelector(\'[data-test="preview-frame"]\').getAttribute("src")';
    // What the sandbox's preview script posts when the app's page changes.
    $navigatePreview = 'window.dispatchEvent(new MessageEvent("message", {data: {onedrop: "location", page: "/settings?section=billing"}, source: document.querySelector(\'[data-test="preview-frame"]\').contentWindow}))';

    $page = visit("/projects/{$project->id}")
        ->resize(1600, 900)
        ->click('@split-menu')
        ->click('@split-right')
        ->assertPresent('@shell-frame-2')
        ->click('[data-test="pane-1"] [data-test="add-tab"]')
        ->click('@add-tab-tests')
        ->click('[data-test="pane-1"] [data-test="tab-preview"]')
        ->type('#composer-content', 'half a thought');

    $page->script($navigatePreview);
    $session = $page->script($shellSrc);
    expect($session)->toContain('arg=session');

    $page->refresh()
        ->assertScript($tabsIn(1), 'tab-tools,tab-preview,tab-shell,tab-services,tab-console,tab-tests')
        ->assertScript($tabsIn(2), 'tab-shell-2')
        ->assertScript($shellSrc, $session)
        ->assertScript("{$previewSrc}.endsWith('/settings?section=billing')", true)
        ->assertValue('#composer-content', 'half a thought')
        ->assertNoJavaScriptErrors();

    // Another browser tab has its own sessionStorage: the default layout, and no shared Shells.
    visit("/projects/{$project->id}")
        ->resize(1600, 900)
        ->assertMissing('@pane-2')
        ->assertScript($tabsIn(1), 'tab-tools,tab-preview,tab-shell,tab-services,tab-console')
        ->assertValue('#composer-content', '');
})->group('LAYOUT-005');

test('on a small screen the chat and the workspace are tabs, keeping the draft when switching', function () {
    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['preview_url' => null]);
    $this->actingAs($user);

    visit("/projects/{$project->id}")
        ->resize(390, 844)
        ->assertVisible('section[aria-label="Chat"]')
        ->assertMissing('@mobile-tab-switcher')
        ->type('#composer-content', 'half a thought')
        ->click('@mobile-tab-workspace')
        ->assertMissing('section[aria-label="Chat"]')
        ->assertVisible('@mobile-tab-switcher')
        ->assertMissing('@toggle-chat')
        ->click('@mobile-tab-chat')
        ->assertVisible('section[aria-label="Chat"]')
        ->assertValue('#composer-content', 'half a thought')
        ->resize(1600, 900)
        ->assertMissing('@mobile-tab-chat')
        ->assertVisible('@tab-preview')
        ->assertNoJavaScriptErrors();
})->group('LAYOUT-006');

test('on a small screen the sidebar closes once a project in it is opened', function () {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'dev@example.com')->sole();
    AgentConnection::factory()->for($user)->create();
    $project = Project::factory()->for($user)->create(['name' => 'Todo App']);
    $this->actingAs($user);

    visit('/dashboard')
        ->resize(390, 844)
        ->click('[data-sidebar="trigger"]')
        ->assertVisible('[data-mobile="true"]')
        ->click('[data-mobile="true"] li[data-test="sidebar-project"] a:has-text("Todo App")')
        ->assertPathIs("/projects/{$project->id}")
        ->assertMissing('[data-mobile="true"]')
        ->assertNoJavaScriptErrors();
})->group('PRJ-002');

test('on a small screen the preview bar leaves out the address, the size menu and splitting', function () {
    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['preview_url' => 'http://127.0.0.1:49152']);
    $this->actingAs($user);

    visit("/projects/{$project->id}")
        ->resize(390, 844)
        ->click('@mobile-tab-workspace')
        ->assertVisible('@preview-annotate')
        ->assertMissing('@preview-size')
        ->assertMissing('@split-menu')
        ->assertScript("getComputedStyle(document.querySelector('[data-test=\"sandbox-status\"]')).visibility", 'hidden')
        ->resize(1600, 900)
        ->assertVisible('@preview-size')
        ->assertVisible('@split-menu')
        ->assertNoJavaScriptErrors();
})->group('LAYOUT-006');

test('on a small screen a pane\'s tabs are one switcher, with the rest in a sheet', function () {
    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['preview_url' => 'http://127.0.0.1:49152', 'shell_url' => 'about:blank']);
    $this->actingAs($user);

    $page = visit("/projects/{$project->id}")
        ->resize(390, 844)
        ->click('@mobile-tab-workspace')
        ->assertMissing('@tab-preview')
        ->assertMissing('@add-tab')
        ->assertSeeIn('@mobile-tab-switcher', 'Preview')
        ->assertVisible('@preview-annotate')
        ->click('@mobile-tab-switcher')
        ->click('@mobile-add-tab-tests')
        ->assertMissing('@mobile-tab-sheet')
        ->assertSeeIn('@mobile-tab-switcher', 'Tests')
        ->assertSeeIn('@mobile-tab-switcher', '6')
        ->click('@mobile-tab-switcher')
        ->assertMissing('@mobile-add-tab-tests')
        ->assertVisible('@mobile-add-tab-shell')
        ->click('@mobile-close-tests')
        ->assertMissing('@mobile-tab-tests')
        ->click('@mobile-tab-preview')
        ->assertSeeIn('@mobile-tab-switcher', 'Preview')
        ->assertVisible('@preview-annotate')
        ->click('@pane-more')
        ->assertSee('Open in a new tab')
        ->assertNoJavaScriptErrors();

    $page->screenshot(filename: 'mobile-tab-switcher');

    $page->resize(1600, 900)
        ->assertMissing('@mobile-tab-switcher')
        ->assertVisible('@tab-preview');
})->group('LAYOUT-007');

test('on a small screen the header\'s commit and share buttons are icons, leaving room for the project\'s name', function () {
    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create(['name' => 'Dolorum Aut Ut Alias Iusto Molestiae']);
    Sandbox::factory()->for($project)->create(['preview_url' => null]);
    $this->actingAs($user);

    $page = visit("/projects/{$project->id}")
        ->resize(390, 844)
        ->assertVisible('@share-button')
        ->assertScript("document.querySelector('[data-test=\"share-button\"]').offsetWidth < 48", true)
        ->assertSeeIn('@publish-button', 'Publish')
        ->assertScript("document.querySelector('header [data-slot=\"breadcrumb-page\"]').offsetHeight < 30", true)
        ->assertNoJavaScriptErrors();

    $page->screenshot(filename: 'mobile-header');
})->group('LAYOUT-007');

test('the preview address copies only from its copy button', function () {
    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['preview_url' => 'http://127.0.0.1:49152']);
    $this->actingAs($user);

    $page = visit("/projects/{$project->id}")->resize(1600, 900);

    $page->script(<<<'JS'
        Object.defineProperty(navigator, 'clipboard', {
            configurable: true,
            value: { writeText: async (text) => { window.copiedText = text; } },
        });
    JS);

    $page->click('@preview-address')
        ->assertScript('window.copiedText ?? null', null)
        ->hover('@preview-address')
        ->click('@preview-address-copy')
        ->assertAttribute('@preview-address-copy', 'title', 'Copied')
        ->assertScript('window.copiedText', 'http://127.0.0.1:49152')
        ->assertNoJavaScriptErrors();
})->group('PRJ-002');
