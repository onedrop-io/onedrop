<?php

use App\Enums\AgentHarness;
use App\Enums\AgentProvider;
use App\Enums\MessageRole;
use App\Enums\SandboxStatus;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\ExecResult;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;

test('users switch a project to Claude Code and pick from Claude models', function () {
    app()->instance(SandboxProvider::class, new FakeSandboxProvider);
    $user = User::factory()->create();
    AgentConnection::factory()->for($user)->provider(AgentProvider::OpenRouter)->create(['is_default' => true]);
    AgentConnection::factory()->for($user)->claudeLogin()->create(['is_default' => false]);
    $project = Project::factory()->for($user)->create(['agent_session_id' => 'ses_opencode']);
    Sandbox::factory()->for($project)->create(['preview_url' => null]);
    $this->actingAs($user);

    visit("/projects/{$project->id}")
        ->assertSeeIn('@harness-picker', 'OpenCode')
        ->click('@harness-picker')
        ->assertVisible('@harness-menu')
        ->click('@harness-claude_code')
        ->assertSeeIn('@harness-picker', 'Claude Code')
        ->assertSeeIn('@model-picker', 'Claude Sonnet 5')
        ->assertSee('Switched to Claude Code')
        ->click('@model-picker')
        ->assertVisible('[aria-label="Anthropic"]')
        ->assertMissing('[aria-label="OpenRouter"]')
        ->click('[data-test="model-claude:claude-opus-5-5"]')
        ->assertSeeIn('@model-picker', 'Claude Opus 5.5')
        ->assertNoJavaScriptErrors();

    $project->refresh();
    expect($project->agent_harness)->toBe(AgentHarness::ClaudeCode)
        ->and($project->agent_model)->toBe('claude-opus-5-5')
        ->and($project->agent_session_id)->toBeNull();
})->group('AGT-007');

test('users switch a project to Codex and pick from OpenAI models', function () {
    app()->instance(SandboxProvider::class, new FakeSandboxProvider);
    $user = User::factory()->create();
    AgentConnection::factory()->for($user)->provider(AgentProvider::OpenRouter)->create(['is_default' => true]);
    AgentConnection::factory()->for($user)->chatGpt()->create(['is_default' => false]);
    $project = Project::factory()->for($user)->create(['agent_session_id' => 'ses_opencode']);
    Sandbox::factory()->for($project)->create(['preview_url' => null]);
    $this->actingAs($user);

    visit("/projects/{$project->id}")
        ->assertSeeIn('@harness-picker', 'OpenCode')
        ->click('@harness-picker')
        ->assertSeeIn('@harness-codex', "OpenAI's agent")
        ->click('@harness-codex')
        ->assertSeeIn('@harness-picker', 'Codex')
        ->assertSeeIn('@model-picker', 'GPT-5.5')
        ->assertSee('Switched to Codex')
        ->click('@model-picker')
        ->assertVisible('[aria-label="OpenAI"]')
        ->assertMissing('[aria-label="OpenRouter"]')
        ->assertNoJavaScriptErrors();

    $project->refresh();
    expect($project->agent_harness)->toBe(AgentHarness::Codex)
        ->and($project->agent_provider)->toBe(AgentProvider::Codex)
        ->and($project->agent_model)->toBe('gpt-5.5')
        ->and($project->agent_session_id)->toBeNull();
})->group('AGT-009');

test('users sign in to Claude from the chat in Claude Code\'s own sign-in in the Shell tab, then see the Preview, and the chat carries on', function () {
    $provider = new FakeSandboxProvider;
    $provider->execUsing = fn (array $command) => new ExecResult(1, json_encode(['loggedIn' => false, 'authMethod' => 'none']));
    app()->instance(SandboxProvider::class, $provider);
    $user = User::factory()->create();
    AgentConnection::factory()->for($user)->claudeLogin()->create();
    $project = Project::factory()->for($user)->create(['agent_harness' => AgentHarness::ClaudeCode]);
    Sandbox::factory()->for($project)->create(['status' => SandboxStatus::Running, 'preview_url' => null, 'shell_url' => 'http://127.0.0.1:7681']);
    // The last message failed because Claude Code wasn't signed in.
    $failed = $project->messages()->create(['role' => MessageRole::User, 'content' => 'Build a timer']);
    $project->update(['sign_in_retry_message_id' => $failed->id]);
    $this->actingAs($user);

    $page = visit("/projects/{$project->id}")
        ->assertSeeIn('@harness-picker', 'Claude Code')
        ->click('@claude-sign-in')
        ->assertVisible('@shell-frame-2')
        ->assertScript('document.querySelector(\'[data-test="shell-frame-2"]\').getAttribute("src").startsWith("http://127.0.0.1:7681/?arg=claude-login&arg=session&arg=")', true)
        ->assertNoJavaScriptErrors();

    // Once Claude Code reports a sign-in, the chat shows who it's signed in as.
    $provider->execUsing = fn (array $command) => new ExecResult(0, json_encode(['loggedIn' => true, 'authMethod' => 'claude.ai', 'email' => 'dev@example.com']));

    // ...goes back to the Preview tab, and picks the failed message back up.
    $page->wait(6)->assertSeeIn('@claude-login-status', 'Claude Code is signed in as dev@example.com')
        ->assertVisible('@preview-placeholder')
        ->assertMissing('@shell-frame-2')
        ->assertSee('Signed in to Claude, picking up where it left off')
        ->assertNoJavaScriptErrors();

    expect($project->fresh()->sign_in_retry_message_id)->toBeNull();
})->group('AI-005');

test('on a phone, "Sign in to Claude" switches to the workspace, and signing in switches back to the chat', function () {
    $provider = new FakeSandboxProvider;
    $provider->execUsing = fn (array $command) => new ExecResult(1, json_encode(['loggedIn' => false, 'authMethod' => 'none']));
    app()->instance(SandboxProvider::class, $provider);
    $user = User::factory()->create();
    AgentConnection::factory()->for($user)->claudeLogin()->create();
    $project = Project::factory()->for($user)->create(['agent_harness' => AgentHarness::ClaudeCode]);
    Sandbox::factory()->for($project)->create(['status' => SandboxStatus::Running, 'preview_url' => null, 'shell_url' => 'http://127.0.0.1:7681']);
    $this->actingAs($user);

    $page = visit("/projects/{$project->id}")
        ->resize(390, 844)
        ->click('@claude-sign-in')
        ->assertAttribute('@mobile-tab-workspace', 'aria-selected', 'true')
        ->assertVisible('@shell-frame-2')
        ->assertNoJavaScriptErrors();

    $provider->execUsing = fn (array $command) => new ExecResult(0, json_encode(['loggedIn' => true, 'authMethod' => 'claude.ai', 'email' => 'dev@example.com']));

    $page->wait(6)->assertAttribute('@mobile-tab-chat', 'aria-selected', 'true')
        ->assertSeeIn('@claude-login-status', 'Claude Code is signed in as dev@example.com')
        ->assertMissing('@shell-frame-2')
        ->assertNoJavaScriptErrors();
})->group('AI-005', 'LAYOUT-006');

test('the chat offers "Sign in to Claude" while a message waits for it, even when the sign-in can\'t be checked', function () {
    app()->instance(SandboxProvider::class, new FakeSandboxProvider);
    $user = User::factory()->create();
    AgentConnection::factory()->for($user)->claudeLogin()->create();
    $project = Project::factory()->for($user)->create(['agent_harness' => AgentHarness::ClaudeCode]);
    // Not running, so the check can't ask Claude Code.
    Sandbox::factory()->for($project)->create(['status' => SandboxStatus::Paused, 'preview_url' => null, 'shell_url' => 'http://127.0.0.1:7681']);
    $failed = $project->messages()->create(['role' => MessageRole::User, 'content' => 'Build a timer']);
    $project->update(['sign_in_retry_message_id' => $failed->id]);
    $this->actingAs($user);

    visit("/projects/{$project->id}")
        ->assertVisible('@claude-sign-in')
        ->assertNoJavaScriptErrors();
})->group('AI-005');

test('the chat carries on when it opens already signed in to Claude, e.g. after a reload mid sign-in', function () {
    $provider = new FakeSandboxProvider;
    $provider->execUsing = fn (array $command) => new ExecResult(0, json_encode(['loggedIn' => true, 'authMethod' => 'claude.ai', 'email' => 'dev@example.com']));
    app()->instance(SandboxProvider::class, $provider);
    $user = User::factory()->create();
    AgentConnection::factory()->for($user)->claudeLogin()->create();
    $project = Project::factory()->for($user)->create(['agent_harness' => AgentHarness::ClaudeCode]);
    Sandbox::factory()->for($project)->create(['status' => SandboxStatus::Running, 'preview_url' => null, 'shell_url' => 'http://127.0.0.1:7681']);
    $failed = $project->messages()->create(['role' => MessageRole::User, 'content' => 'Build a timer']);
    $project->update(['sign_in_retry_message_id' => $failed->id]);
    $this->actingAs($user);

    visit("/projects/{$project->id}")
        ->assertSeeIn('@claude-login-status', 'Claude Code is signed in as dev@example.com')
        ->assertSee('Signed in to Claude, picking up where it left off')
        ->assertNoJavaScriptErrors();

    expect($project->fresh()->sign_in_retry_message_id)->toBeNull();
})->group('AI-005');

test('connecting a Claude subscription in settings unlocks Claude Code in the chat without a reload', function () {
    $user = User::factory()->create();
    AgentConnection::factory()->for($user)->provider(AgentProvider::OpenRouter)->create(['is_default' => true]);
    $this->actingAs($user);

    $page = visit('/dashboard')
        ->click('@harness-picker')
        ->assertSeeIn('@harness-claude_code', 'Connect Claude in Settings → AI to use it')
        ->keys('@harness-menu', 'Escape')
        ->assertMissing('@harness-menu')
        ->click('@sidebar-menu-button')
        ->click('@settings-link')
        ->click('[data-test="settings-modal"] a:has-text("AI")')
        ->assertPathIs('/settings/ai')
        ->press('@use-claude-subscription')
        ->assertSee('Claude subscription added.')
        ->keys('@settings-modal', 'Escape')
        ->assertMissing('@settings-modal');

    $page->click('@harness-picker')
        ->click('@harness-claude_code')
        ->assertSeeIn('@harness-picker', 'Claude Code')
        ->assertNoJavaScriptErrors();
})->group('AGT-007');

test('the composer controls fit a narrow chat without running under the send button', function () {
    app()->instance(SandboxProvider::class, new FakeSandboxProvider);
    $user = User::factory()->create();
    AgentConnection::factory()->for($user)->provider(AgentProvider::OpenRouter)->create(['is_default' => true]);
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['preview_url' => null]);
    $this->actingAs($user);

    $page = visit("/projects/{$project->id}")
        ->assertVisible('@composer-autofix')
        ->assertVisible('@composer-send');

    $layout = $page->script(<<<'JS'
        async () => {
            document.querySelector('[data-test="composer-send"]').closest('form').style.width = '340px';
            await new Promise((resolve) => requestAnimationFrame(resolve));
            const send = document.querySelector('[data-test="composer-send"]').getBoundingClientRect();
            const autofix = document.querySelector('[data-test="composer-autofix"]').getBoundingClientRect();
            const toolbar = document.querySelector('[data-test="composer-send"]').parentElement.parentElement;
            return {
                overlaps: autofix.right > send.left,
                overflows: toolbar.scrollWidth > toolbar.clientWidth,
                agentLabelShown: document.querySelector('[data-test="harness-picker"]').innerText.trim() !== '',
            };
        }
    JS);

    expect($layout)->toBe(['overlaps' => false, 'overflows' => false, 'agentLabelShown' => false]);
})->group('AGT-002');
