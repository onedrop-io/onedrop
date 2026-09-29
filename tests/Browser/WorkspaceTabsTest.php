<?php

use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\ExecResult;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;

test('console and shell tabs can be added from the + menu and closed', function () {
    $provider = new FakeSandboxProvider;
    $provider->execUsing = fn (array $command) => $command[0] === 'sh'
        ? new ExecResult(0, "42\n\e[32m  VITE v7 ready\e[0m in 300 ms\n")
        : new ExecResult(0, '');
    app()->instance(SandboxProvider::class, $provider);

    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['preview_url' => null, 'shell_url' => null]);
    $this->actingAs($user);

    visit("/projects/{$project->id}")
        ->click('@add-tab')
        ->click('@add-tab-console')
        ->assertSeeIn('@console-output', 'VITE v7 ready in 300 ms')
        ->assertDontSeeIn('@console-output', '[32m')
        ->assertMissing('[role="menu"]')
        ->click('@add-tab')
        ->click('@add-tab-shell')
        ->assertSeeIn('@tab-notice', "This sandbox doesn't have a shell")
        ->click('@tab-console')
        ->assertSeeIn('@console-output', 'VITE v7 ready')
        ->click('[aria-label="Clear console"]')
        ->assertDontSeeIn('@console-output', 'VITE v7 ready')
        ->click('[data-test="tab-console"] + button')
        ->assertMissing('@tab-console')
        ->assertVisible('@tab-shell')
        ->assertNoJavaScriptErrors();
})->group('TAB-001');

test('the shell tab puts the cursor in the terminal when shown', function () {
    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['preview_url' => null, 'shell_url' => 'about:blank']);
    $this->actingAs($user);

    $shellFocused = 'document.activeElement?.dataset.test === "shell-frame"';

    visit("/projects/{$project->id}")
        ->click('@add-tab')
        ->click('@add-tab-shell')
        ->wait(0.5) // let the menu finish closing; it hands focus back to "+" as it goes
        ->assertScript($shellFocused, true)
        ->click('@tab-preview')
        ->assertScript($shellFocused, false)
        ->click('@tab-shell')
        ->assertScript($shellFocused, true)
        ->assertNoJavaScriptErrors();
})->group('TAB-001');

test('closable tabs can be dragged into a different order', function () {
    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['preview_url' => null, 'shell_url' => null]);
    $this->actingAs($user);

    $order = 'Array.from(document.querySelectorAll("button[data-test^=tab-]")).map((tab) => tab.dataset.test).join(",")';

    visit("/projects/{$project->id}")
        ->click('@add-tab')
        ->click('@add-tab-console')
        ->assertMissing('[role="menu"]')
        ->click('@add-tab')
        ->click('@add-tab-shell')
        ->assertScript($order, 'tab-tools,tab-preview,tab-console,tab-shell')
        ->drag('@tab-console', '@tab-shell')
        ->assertScript($order, 'tab-tools,tab-preview,tab-shell,tab-console')
        ->drag('@tab-console', '@tab-preview')
        ->assertScript($order, 'tab-tools,tab-preview,tab-shell,tab-console')
        ->assertNoJavaScriptErrors();
})->group('TAB-001');
