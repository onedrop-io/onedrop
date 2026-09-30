<?php

use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;

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
    $mark = 'document.querySelector(\'[data-test="shell-frame-2"]\').contentWindow.marked = true';
    $marked = 'document.querySelector(\'[data-test="shell-frame-2"]\')?.contentWindow?.marked === true';

    $page = visit("/projects/{$project->id}")
        ->resize(1600, 900)
        ->click('@split-menu')
        ->click('@split-right')
        ->assertPresent('@pane-2')
        ->assertScript($tabsIn(1), 'tab-tools,tab-preview')
        ->assertScript($tabsIn(2), 'tab-shell')
        ->assertPresent('@shell-frame')
        ->click('[data-test="pane-1"] [data-test="add-tab"]')
        ->click('@add-tab-shell')
        ->assertScript($tabsIn(1), 'tab-tools,tab-preview,tab-shell-2')
        ->assertSeeIn('@tab-shell-2', 'Shell 2')
        ->assertPresent('@shell-frame-2');

    $page->script($mark);

    $page->drag('@tab-shell-2', '[data-test="pane-2"] [data-test="sandbox-status"]')
        ->assertScript($tabsIn(1), 'tab-tools,tab-preview')
        ->assertScript($tabsIn(2), 'tab-shell,tab-shell-2')
        ->assertScript($marked, true)
        ->wait(0.3) // let the drag finish; a click straight after the drop is lost
        ->click('[data-test="pane-2"] [data-test="split-menu"]')
        ->click('@split-down')
        ->assertScript($tabsIn(3), 'tab-shell-3')
        ->assertScript(
            'document.querySelector(\'[data-test="pane-3"]\').getBoundingClientRect().top > document.querySelector(\'[data-test="pane-1"]\').getBoundingClientRect().bottom',
            true,
        )
        ->click('[data-test="tab-shell-3"] + button')
        ->assertMissing('@pane-3')
        ->assertMissing('@tab-shell-3')
        ->click('[data-test="pane-2"] [data-test="close-pane"]')
        ->assertMissing('@pane-2')
        ->assertScript($tabsIn(1), 'tab-tools,tab-preview,tab-shell,tab-shell-2')
        ->assertScript($marked, true)
        ->assertMissing('@close-pane')
        ->assertNoJavaScriptErrors();
})->group('LAYOUT-002');
