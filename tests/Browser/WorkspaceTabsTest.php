<?php

use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\ExecResult;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;

test('shell, services and console tabs are open by default, and can be shown from the + menu and closed', function () {
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
        ->assertScript('Array.from(document.querySelectorAll("button[data-test^=tab-]")).map((tab) => tab.dataset.test).join(",")', 'tab-tools,tab-preview,tab-shell,tab-services,tab-console')
        ->assertVisible('@preview-placeholder')
        ->click('@add-tab')
        ->click('@add-tab-console')
        ->assertSeeIn('@console-output', 'VITE v7 ready in 300 ms')
        ->assertDontSeeIn('@console-output', '[32m')
        ->assertMissing('[role="menu"]')
        ->assertMissing('@tab-console-2')
        ->click('@tab-shell')
        ->assertSeeIn('[data-test="tab-notice"]:visible', "This sandbox doesn't have a shell")
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
        ->click('@tab-shell')
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
        ->assertScript($order, 'tab-tools,tab-preview,tab-shell,tab-services,tab-console')
        ->drag('@tab-shell', '@tab-services')
        ->assertScript($order, 'tab-tools,tab-preview,tab-services,tab-shell,tab-console')
        ->drag('@tab-shell', '@tab-preview')
        ->assertScript($order, 'tab-tools,tab-preview,tab-services,tab-shell,tab-console')
        ->assertNoJavaScriptErrors();
})->group('TAB-001');

test('the console shows the dev server\'s colors, colors plain lines, and can be searched', function () {
    $reads = 0;
    $provider = new FakeSandboxProvider;
    $provider->execUsing = function (array $command) use (&$reads) {
        if ($command[0] === 'sh') {
            $log = "\e[32m  VITE v7 ready\e[0m in 300 ms\n"
                ."2026-10-03 20:22:38 /build/assets/app.js ............ ~ 504.99ms\n"
                ."2026-10-03 20:22:38 /favicon.svg .................. ~ 0.13ms\n"
                ."2026-10-03 20:22:39 / ............................. ~ 120ms\n"
                ."ERROR connection refused\n"
                ."\e[3";

            return new ExecResult(0, strlen($log)."\n".$log);
        }

        // The rest of the escape cut off by the first read.
        return new ExecResult(0, $command[0] === 'tail' && $reads++ === 0 ? "1mafter the split\e[0m\n" : '');
    };
    app()->instance(SandboxProvider::class, $provider);

    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['preview_url' => null, 'shell_url' => null]);
    $this->actingAs($user);

    visit("/projects/{$project->id}")
        ->click('@tab-console')
        ->assertSeeIn('[data-test="console-output"] .text-green-400', 'VITE v7 ready')
        ->assertSeeIn('[data-test="console-output"] .text-red-400', '504.99ms')
        ->assertSeeIn('[data-test="console-output"] .text-amber-300', '120ms')
        ->assertSeeIn('[data-test="console-output"] .text-neutral-500', '0.13ms')
        ->assertSeeIn('[data-test="console-output"] .text-neutral-500', '2026-10-03 20:22:39')
        ->assertSeeIn('[data-test="console-output"] .font-semibold', 'ERROR')
        ->assertSeeIn('[data-test="console-output"] .text-red-400', 'after the split')
        ->assertDontSeeIn('@console-output', '[3')
        ->type('@console-search', 'build -favicon')
        ->assertSeeIn('@console-matches', '1 line')
        ->assertSeeIn('[data-test="console-output"] mark', 'build')
        ->assertDontSeeIn('@console-output', 'connection refused')
        ->click('[data-test="console-output"] [data-line="1"]')
        ->assertValue('@console-search', '')
        ->assertSeeIn('@console-output', 'connection refused')
        ->assertPresent('[data-line="1"].bg-amber-400\/15')
        ->assertNoJavaScriptErrors();
})->group('TAB-002', 'SVC-001');
