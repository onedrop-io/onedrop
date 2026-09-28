<?php

use App\Models\AgentConnection;
use App\Models\Group;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Agents\AgentRunner;
use App\Sandbox\Agents\FakeAgentRunner;
use App\Sandbox\SandboxProvider;

beforeEach(function () {
    $this->workspace = databaseWorkspace();
    app()->instance(SandboxProvider::class, fakeDatabaseSandbox($this->workspace));
    app()->instance(AgentRunner::class, new FakeAgentRunner);

    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->project = Project::factory()->for($this->user)->create();
    Sandbox::factory()->for($this->project)->create(['preview_url' => 'http://127.0.0.1:49152']);
    $this->actingAs($this->user);
});

test('the user sets up sign-in with the agent, sees the app users and adds provider keys', function () {
    $page = visit("/projects/{$this->project->id}")
        ->resize(1920, 1080)
        ->click('@tab-tools')
        ->click('@tool-auth')
        ->assertSeeIn('@auth-setup', 'Let people sign in to your app')
        ->click('@auth-method-google')
        ->click('@auth-setup-send')
        ->assertVisible('@auth-setup-sent')
        ->assertSee('Add user sign-in to my app with email and password and Google.');

    // The agent built it: sign-in is described in .zap/auth.json and users are signing up.
    authWorkspace(root: $this->workspace);

    $page->click('Check again')
        ->assertSeeIn('@auth-users-count', '3 users')
        ->assertSeeIn('@auth-users', 'ann@example.com')
        ->type('@auth-users-search', 'bob')
        ->assertSeeIn('@auth-users-count', '1 user')
        ->assertDontSeeIn('@auth-users', 'ann@example.com')

        ->click('@auth-tab-configure')
        ->assertSeeIn('[data-test="auth-config-google"] [data-test="auth-method-status"]', 'Needs keys')
        ->assertSeeIn('@auth-keys-google', 'http://127.0.0.1:49152/auth/google/callback')
        ->type('@auth-client-id-google', 'abc.apps.googleusercontent.com')
        ->type('@auth-client-secret-google', 'GOCSPX-secret')
        ->click('@auth-save-keys-google')
        ->assertVisible('@auth-keys-saved-google')
        ->assertSeeIn('[data-test="auth-config-google"] [data-test="auth-method-status"]', 'On');

    expect(file_get_contents($this->workspace.'/.env'))->toContain('GOOGLE_CLIENT_SECRET=GOCSPX-secret');

    $page->click('@auth-toggle-github')
        ->click('@auth-config-send')
        ->assertSeeIn('@auth-config-pending', 'Asked the agent')
        ->assertSee('Change how people sign in to my app: turn on GitHub.')
        ->assertNoJavaScriptErrors();
})->group('APPAUTH-001');

test('users & auth explains when the sandbox is not running', function () {
    $this->project->sandbox->update(['status' => 'paused']);

    visit("/projects/{$this->project->id}")
        ->click('@tab-tools')
        ->click('@tool-auth')
        ->assertSeeIn('@auth-empty', 'works when the sandbox is running')
        ->assertNoJavaScriptErrors();
})->group('APPAUTH-001');

test('the user adds, edits, controls and deletes app users', function () {
    withAccountControls(withUsersHelper(authWorkspace(root: $this->workspace)));

    $page = visit("/projects/{$this->project->id}")
        ->resize(1920, 1080)
        ->click('@tab-tools')
        ->click('@tool-auth')
        ->assertSeeIn('@auth-users-count', '3 users')

        ->click('@auth-add-user')
        ->assertVisible('@auth-user-dialog')
        ->assertScript('document.activeElement?.dataset.test', 'auth-user-name')
        ->type('@auth-user-name', 'Dee')
        ->type('@auth-user-email', 'dee@example.com')
        ->type('@auth-user-password', 'correct horse')
        ->click('@auth-user-save')
        ->assertMissing('@auth-user-dialog')
        ->assertSeeIn('@auth-users-count', '4 users');

    expect(password_verify('correct horse', workspacePasswordHash($this->workspace, 4)))->toBeTrue();

    // Newest first: Dee is the first row.
    $page->click('@auth-user-actions')
        ->click('@auth-edit-user')
        ->assertVisible('@auth-user-dialog')
        ->assertScript('document.activeElement?.dataset.test', 'auth-user-name')
        ->clear('@auth-user-name')
        ->type('@auth-user-name', 'Deedee')
        ->click('@auth-user-save')
        ->assertMissing('@auth-user-dialog')
        ->assertSeeIn('@auth-users', 'Deedee')

        ->assertSeeIn('[data-test="auth-user"]:first-child [data-test="auth-user-role"]', 'member')
        ->click('@auth-user-actions')
        ->click('@auth-change-role')
        ->click('@auth-role-admin')
        ->assertSeeIn('[data-test="auth-user"]:first-child [data-test="auth-user-role"]', 'admin')
        ->assertAttribute('@auth-export-users', 'href', "/projects/{$this->project->id}/auth/users/export")

        ->click('@auth-user-actions')
        ->click('@auth-set-password')
        ->assertScript('document.activeElement?.dataset.test', 'auth-user-new-password')
        ->type('@auth-user-new-password', 'another pass')
        ->click('@auth-password-save')
        ->assertMissing('@auth-user-dialog');

    expect(password_verify('another pass', workspacePasswordHash($this->workspace, 4)))->toBeTrue()
        ->and(signedOutUsers($this->workspace))->toBe(['4']);

    $page->click('@auth-user-actions')
        ->click('@auth-toggle-disabled')
        ->assertVisible('@auth-user-disabled')
        ->click('@auth-user-actions')
        ->click('@auth-require-password')
        ->assertVisible('@auth-user-must-change')
        ->click('@auth-user-actions')
        ->assertSeeIn('@auth-toggle-disabled', 'Turn account on')
        ->click('@auth-toggle-disabled')
        ->assertMissing('@auth-user-disabled')

        ->click('@auth-user-actions')
        ->click('@auth-delete-user')
        ->assertSeeIn('@auth-delete-dialog', 'Deedee')
        ->click('@auth-delete-confirm')
        ->assertMissing('@auth-delete-dialog')
        ->assertSeeIn('@auth-users-count', '3 users')
        ->assertDontSeeIn('@auth-users', 'Deedee')
        ->assertNoJavaScriptErrors();
})->group('APPAUTH-001');

test('controls an older app lacks offer to ask the agent', function () {
    authWorkspace(root: $this->workspace);

    visit("/projects/{$this->project->id}")
        ->resize(1920, 1080)
        ->click('@tab-tools')
        ->click('@tool-auth')
        ->click('@auth-user-actions')
        ->click('@auth-toggle-disabled')
        ->assertVisible('@auth-needs-helper')
        ->click('@auth-request-helper')
        ->assertSee('Asked the agent')
        ->assertNoJavaScriptErrors();

    expect($this->project->messages()->where('role', 'user')->latest('id')->value('content'))->toContain('.zap/users');
})->group('APPAUTH-001');

test('the user turns on OneDrop accounts and chooses who can sign in', function () {
    authWorkspace(root: $this->workspace);
    $engineering = Group::factory()->create(['name' => 'Engineering']);

    visit("/projects/{$this->project->id}")
        ->resize(1920, 1080)
        ->click('@tab-tools')
        ->click('@tool-auth')
        ->click('@auth-tab-configure')
        ->assertMissing('@auth-onedrop-access')
        ->click('@auth-toggle-onedrop')
        ->click('@auth-config-send')
        ->assertSee('Change how people sign in to my app: turn on OneDrop accounts.');

    expect($this->project->fresh()->onedrop_enabled)->toBeTrue()
        ->and(file_get_contents($this->workspace.'/.env'))->toContain('ONEDROP_CLIENT_ID=od_');

    // The agent added the button.
    file_put_contents($this->workspace.'/.zap/auth.json', json_encode(['methods' => ['password', 'google', 'onedrop']]));

    visit("/projects/{$this->project->id}")
        ->resize(1920, 1080)
        ->click('@tab-tools')
        ->click('@tool-auth')
        ->click('@auth-tab-configure')
        ->assertVisible('@auth-onedrop-access')
        ->click('@auth-onedrop-groups')
        ->click("@auth-onedrop-group-{$engineering->id}")
        ->assertChecked("@auth-onedrop-group-{$engineering->id}")
        ->assertNoJavaScriptErrors();

    expect($this->project->fresh()->onedrop_group_ids)->toBe([$engineering->id]);
})->group('APPAUTH-002');
