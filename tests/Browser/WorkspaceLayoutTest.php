<?php

use App\Models\AgentConnection;
use App\Models\Message;
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
        // A Shell starts once it's shown.
        ->click('[data-test="pane-1"] [data-test="tab-shell"]')
        ->assertPresent('@shell-frame')
        ->click('[data-test="pane-1"] [data-test="tab-preview"]')
        ->type('#composer-content', 'half a thought');

    $page->script($navigatePreview);
    $session = $page->script($shellSrc);
    expect($session)->toContain('arg=session');

    $page->refresh()
        ->assertScript($tabsIn(1), 'tab-tools,tab-preview,tab-shell,tab-services,tab-console,tab-tests')
        ->assertScript($tabsIn(2), 'tab-shell-2')
        ->assertScript("{$previewSrc}.endsWith('/settings?section=billing')", true)
        ->assertValue('#composer-content', 'half a thought')
        // A Shell not on screen reattaches once it's shown.
        ->assertMissing('@shell-frame')
        ->click('[data-test="pane-1"] [data-test="tab-shell"]')
        ->assertScript($shellSrc, $session)
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
        ->assertAttribute('@mobile-bar-chat', 'aria-selected', 'true')
        ->type('#composer-content', 'half a thought')
        ->click('@mobile-bar-preview')
        ->assertMissing('section[aria-label="Chat"]')
        ->assertMissing('@toggle-chat')
        ->click('@mobile-bar-chat')
        ->assertVisible('section[aria-label="Chat"]')
        ->assertValue('#composer-content', 'half a thought')
        ->resize(1600, 900)
        ->assertMissing('@mobile-tab-bar')
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

test('on a small screen the sidebar closes as soon as a project is tapped, and its page shows as loading', function () {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'dev@example.com')->sole();
    AgentConnection::factory()->for($user)->create();
    $project = Project::factory()->for($user)->create(['name' => 'Todo App']);
    $this->actingAs($user);

    $page = visit('/dashboard')
        ->resize(390, 844)
        ->click('[data-sidebar="trigger"]')
        ->assertVisible('[data-mobile="true"]');

    // A slow connection: the page's request waits 1.5 seconds before it's sent. Tapped from a script, so the test
    // doesn't wait for the page to load as a click would.
    $page->script(<<<'JS'
        const send = XMLHttpRequest.prototype.send;
        XMLHttpRequest.prototype.send = function (...args) { setTimeout(() => send.apply(this, args), 1500); };
        document.querySelector('[data-mobile="true"] li[data-test="sidebar-project"] a').click();
        JS);

    $page->assertMissing('[data-mobile="true"]')
        ->assertSeeIn('@page-loading-name', 'Todo App')
        ->assertVisible('[data-test="page-loading"] [role="status"]')
        ->assertPathIs("/projects/{$project->id}")
        ->assertMissing('@page-loading')
        ->assertNoJavaScriptErrors();
})->group('PRJ-002');

test('while a project loads, an empty frame of it shows a spinner where the chat goes, with nothing to draw again', function () {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'dev@example.com')->sole();
    AgentConnection::factory()->for($user)->create();
    $project = Project::factory()->for($user)->create(['name' => 'Todo App']);
    Message::factory()->for($project)->create(['content' => 'Add a due date to each todo']);
    $this->actingAs($user);

    $page = visit('/dashboard')->resize(1440, 900);

    // A slow connection: the page's request waits 2 seconds before it's sent.
    $page->script(<<<'JS'
        const send = XMLHttpRequest.prototype.send;
        XMLHttpRequest.prototype.send = function (...args) { setTimeout(() => send.apply(this, args), 2000); };
        document.querySelector('li[data-test="sidebar-project"] a').click();
        JS);

    $page->assertSeeIn('@page-loading-name', 'Todo App')
        ->assertVisible('[data-test="page-loading"] [role="status"]')
        ->assertScript('document.querySelector(\'[data-test="page-loading"]\').querySelectorAll("textarea, p").length', 0)
        ->assertDontSeeIn('@page-loading', 'Add a due date')
        ->assertPathIs("/projects/{$project->id}")
        ->assertMissing('@page-loading')
        ->assertSeeIn('@message-user', 'Add a due date to each todo')
        ->assertNoJavaScriptErrors();
})->group('PRJ-002');

test('on a small screen the preview bar leaves out the address, the size menu and splitting', function () {
    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['preview_url' => 'http://127.0.0.1:49152']);
    $this->actingAs($user);

    visit("/projects/{$project->id}")
        ->resize(390, 844)
        ->click('@mobile-bar-preview')
        ->assertVisible('@preview-annotate')
        ->assertMissing('@preview-size')
        ->assertMissing('@split-menu')
        ->assertScript("getComputedStyle(document.querySelector('[data-test=\"preview-address\"]')).visibility", 'hidden')
        ->resize(1600, 900)
        ->assertVisible('@preview-address')
        ->assertVisible('@preview-size')
        ->assertVisible('@split-menu')
        ->assertNoJavaScriptErrors();
})->group('LAYOUT-006');

test('on a phone the tabs are a bar along the bottom, with the rest in a sheet', function () {
    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['preview_url' => 'http://127.0.0.1:49152', 'shell_url' => 'about:blank']);
    $this->actingAs($user);

    // Opened on a phone: opened wide, the files panel would start open and cover the workspace once narrowed.
    $page = visit("/projects/{$project->id}")
        ->on()->mobile()
        ->assertVisible('@mobile-tab-bar')
        ->assertMissing('@mobile-tab-workspace')
        ->click('@mobile-bar-preview')
        ->assertMissing('@tab-preview')
        ->assertMissing('@add-tab')
        ->assertAttribute('@mobile-bar-preview', 'aria-selected', 'true')
        ->assertVisible('@preview-annotate')
        // A Shell starts once it's on screen, at the phone's width, so its banner isn't rewrapped off the top.
        ->assertMissing('@shell-frame')
        ->click('@mobile-bar-shell')
        ->assertAttribute('@mobile-bar-shell', 'aria-selected', 'true')
        ->assertVisible('@shell-frame')
        ->assertSeeIn('@mobile-bar-more', '2')
        ->click('@mobile-bar-more')
        ->click('@mobile-add-tab-tests')
        ->assertMissing('@mobile-tab-sheet')
        ->assertSeeIn('@mobile-bar-more', 'Tests')
        ->assertAttribute('@mobile-bar-more', 'aria-selected', 'true')
        ->click('@mobile-bar-more')
        ->assertMissing('@mobile-tab-tools')
        ->assertMissing('@mobile-tab-preview')
        ->assertVisible('@mobile-close-shell')
        ->assertMissing('@mobile-add-tab-tests')
        ->assertVisible('@mobile-add-tab-shell')
        ->click('@mobile-close-tests')
        ->assertMissing('@mobile-tab-tests')
        ->click('@mobile-tab-console')
        ->assertSeeIn('@mobile-bar-more', 'Console')
        // No pane bar on a phone: the console's clear button is in its own bar.
        ->assertMissing('@pane-1')
        ->assertVisible('@console-clear')
        ->click('@mobile-bar-preview')
        ->assertAttribute('@mobile-bar-preview', 'aria-selected', 'true')
        ->assertSeeIn('@mobile-bar-more', 'More')
        ->assertVisible('@preview-annotate')
        ->click('@preview-more')
        ->assertSee('Open in a new tab')
        ->assertSee('Open in a new window')
        ->assertNoJavaScriptErrors();

    $page->screenshot(filename: 'mobile-tab-bar');

    $page->resize(1600, 900)
        ->assertMissing('@mobile-tab-bar')
        ->assertVisible('@tab-preview');
})->group('LAYOUT-007');

test('on a phone the header\'s commit and share are in one menu, leaving room for the project\'s name', function () {
    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create(['name' => 'Dolorum Aut Ut Alias Iusto Molestiae']);
    Sandbox::factory()->for($project)->create(['preview_url' => null]);
    $this->actingAs($user);

    $page = visit("/projects/{$project->id}")
        ->resize(390, 844)
        ->assertMissing('@share-button')
        ->assertMissing('@git-actions-primary')
        ->assertScript("!!document.querySelector('[data-sidebar=\"trigger\"] svg.lucide-menu')", true)
        ->assertSeeIn('@publish-button', 'Publish')
        ->assertScript("document.querySelector('header [data-slot=\"breadcrumb-page\"]').offsetHeight < 30", true)
        ->click('@git-actions-menu')
        ->assertSeeIn('@git-actions-commit', 'Commit…')
        ->assertVisible('@git-actions-push')
        ->click('@git-actions-share')
        ->assertVisible('@share-panel')
        ->assertSee('Show it off')
        ->assertVisible('@share-prompt')
        ->assertNoJavaScriptErrors();

    $page->screenshot(filename: 'mobile-header');

    $page->resize(1600, 900)
        ->assertVisible('@share-button')
        ->assertVisible('@git-actions-primary');
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

    $page->assertValue('@preview-address', 'http://127.0.0.1:49152')
        ->assertDontSeeIn('@sandbox-status', 'http://127.0.0.1:49152')
        ->click('@preview-address')
        ->assertScript('window.copiedText ?? null', null)
        ->hover('@preview-address')
        ->click('@preview-address-copy')
        ->assertAttribute('@preview-address-copy', 'title', 'Copied')
        ->assertScript('window.copiedText', 'http://127.0.0.1:49152')
        ->assertNoJavaScriptErrors();
})->group('PRJ-002');

test('the preview opens in a pop-up window as big as the screen', function () {
    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['preview_url' => 'http://127.0.0.1:49152']);
    $this->actingAs($user);

    $page = visit("/projects/{$project->id}")->resize(1600, 900);

    $page->script('window.open = (url, target, features) => { window.opened = [url, target, features]; return null; }');

    $page->hover('@preview-address')
        ->click('@preview-open-window')
        ->assertScript('window.opened[0]', 'http://127.0.0.1:49152')
        ->assertScript('window.opened[2] === `popup,noopener,left=0,top=0,width=${screen.availWidth},height=${screen.availHeight}`', true)
        ->assertNoJavaScriptErrors();
})->group('PRJ-002');

test('the preview address follows the app page and goes where it is typed', function () {
    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['preview_url' => 'http://127.0.0.1:9']);
    $this->actingAs($user);

    // What the sandbox's preview script posts when the app's page changes.
    $navigate = fn (string $page, bool $back) => 'window.dispatchEvent(new MessageEvent("message", {data: {onedrop: "location", page: "'.$page.'", back: '.($back ? 'true' : 'false').', forward: false}, source: document.querySelector(\'[data-test="preview-frame"]\').contentWindow}))';
    $previewSrc = 'document.querySelector(\'[data-test="preview-frame"]\').getAttribute("src")';

    $page = visit("/projects/{$project->id}")->resize(1600, 900)
        ->assertValue('@preview-address', 'http://127.0.0.1:9')
        ->assertAttribute('@preview-back', 'disabled', '')
        ->assertAttribute('@preview-forward', 'disabled', '');

    $page->script($navigate('/orders/12?tab=items', true));

    $page->assertValue('@preview-address', 'http://127.0.0.1:9/orders/12?tab=items')
        ->assertAttributeMissing('@preview-back', 'disabled')
        ->assertAttribute('@preview-forward', 'disabled', '')
        ->click('@preview-address')
        ->type('@preview-address', '127.0.0.1:9/customers')
        ->keys('@preview-address', 'Enter')
        ->assertScript($previewSrc, 'http://127.0.0.1:9/customers')
        // Another site isn't one of the app's pages: the typing stays to fix, and the preview stays put.
        ->click('@preview-address')
        ->type('@preview-address', 'https://example.com/')
        ->keys('@preview-address', 'Enter')
        ->assertValue('@preview-address', 'https://example.com/')
        ->assertScript($previewSrc, 'http://127.0.0.1:9/customers')
        ->keys('@preview-address', 'Escape')
        ->assertValue('@preview-address', 'http://127.0.0.1:9/orders/12?tab=items')
        ->assertNoJavaScriptErrors();
})->group('PRJ-002');

test('the preview back and forward buttons move the app page through its history', function () {
    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    // A preview page that answers back and forward the way the sandbox's preview script does.
    $page = '<script>const send = (page, back, forward) => parent.postMessage({onedrop: "location", page, back, forward}, "*");'
        .'send("/orders/12", true, false);'
        .'addEventListener("message", (e) => e.data.onedrop === "go" && (e.data.delta === -1 ? send("/orders", false, true) : send("/orders/12", true, false)));</script>';
    Sandbox::factory()->for($project)->create(['preview_url' => 'data:text/html,'.rawurlencode($page)]);
    $this->actingAs($user);

    visit("/projects/{$project->id}")->resize(1600, 900)
        ->assertAttributeMissing('@preview-back', 'disabled')
        ->assertAttribute('@preview-forward', 'disabled', '')
        ->click('@preview-back')
        ->assertAttribute('@preview-back', 'disabled', '')
        ->assertAttributeMissing('@preview-forward', 'disabled')
        ->click('@preview-forward')
        ->assertAttributeMissing('@preview-back', 'disabled')
        ->assertAttribute('@preview-forward', 'disabled', '')
        ->assertNoJavaScriptErrors();
})->group('PRJ-002');

test('the preview bar shows the path over a dimmed host and a line while the page loads', function () {
    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['preview_url' => 'http://127.0.0.1:9']);
    $this->actingAs($user);

    // What the sandbox's preview script posts when the app's page changes.
    $navigate = 'window.dispatchEvent(new MessageEvent("message", {data: {onedrop: "location", page: "/orders/12", back: false, forward: false}, source: document.querySelector(\'[data-test="preview-frame"]\').contentWindow}))';

    $page = visit("/projects/{$project->id}")->resize(1600, 900)
        ->assertMissing('@preview-loading');

    $page->script($navigate);

    $page->assertSeeIn('@preview-address-bar', '127.0.0.1:9')
        ->assertSeeIn('@preview-address-bar', '/orders/12')
        ->assertVisible('a[aria-label="Open preview in a new tab"]');

    // Nothing listens on port 9, so the page fails to load at once: record that the line showed at all.
    $page->script('new MutationObserver(() => { if (document.querySelector(\'[data-test="preview-loading"]\')) { window.sawLoading = true; } }).observe(document.body, {childList: true, subtree: true})');

    $page->click('@preview-reload')
        ->assertScript('window.sawLoading', true)
        ->assertMissing('@preview-loading')
        ->assertNoJavaScriptErrors();
})->group('PRJ-002');

test('on a tablet, a tab moves to another pane from the split menu, and a tab opened elsewhere scrolls into view', function () {
    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['preview_url' => null, 'shell_url' => 'about:blank']);
    $this->actingAs($user);

    $tabsIn = fn (int $pane) => "Array.from(document.querySelectorAll('[data-test=\"pane-{$pane}\"] button[data-test^=tab-]')).map((tab) => tab.dataset.test).join(',')";
    // Whether a tab's button lies inside its scrolling tab bar's visible part.
    $inView = fn (string $tab) => "(() => { const tab = document.querySelector('[data-test=\"{$tab}\"]'); const bar = tab.closest('[data-tabs]').getBoundingClientRect(); const rect = tab.getBoundingClientRect(); return rect.left >= bar.left - 1 && rect.right <= bar.right + 1; })()";

    visit("/projects/{$project->id}")->on()->iPadPro()
        ->click('@split-menu')
        ->click('@split-right')
        ->assertScript($tabsIn(2), 'tab-shell-2')
        ->wait(0.5) // the new pane's Shell takes the focus as it loads, which would close a menu opened before
        ->click('[data-test="pane-1"] [data-test="tab-services"]')
        ->click('[data-test="pane-1"] [data-test="split-menu"]')
        ->click('@move-tab-2')
        ->assertScript($tabsIn(1), 'tab-tools,tab-preview,tab-shell,tab-console')
        ->assertScript($tabsIn(2), 'tab-shell-2,tab-services')
        ->click('[data-test="pane-2"] [data-test="close-pane"]')
        ->click('@tab-tools')
        ->click('@tool-git')
        ->assertPresent('@tab-source-control')
        ->assertScript($inView('tab-source-control'), true)
        ->assertNoJavaScriptErrors();
})->group('TOUCH-001', 'LAYOUT-002');
