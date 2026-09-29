<?php

use App\Enums\CredentialType;
use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Enums\SandboxStatus;
use App\Jobs\CreateSandbox;
use App\Jobs\SignOutOfClaude;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\ExecResult;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->provider = new FakeSandboxProvider;
    app()->instance(SandboxProvider::class, $this->provider);

    $this->user = User::factory()->create();
    AgentConnection::factory()->for($this->user)->claudeLogin()->create();
    $this->project = Project::factory()->for($this->user)->create();
    $this->sandbox = Sandbox::factory()->for($this->project)->create(['external_id' => 'ctr-1', 'shell_url' => 'http://127.0.0.1:7681']);
});

test('the chat can tell whether Claude Code is signed in to the user\'s subscription', function (array $status, ?bool $signedIn, ?string $email) {
    $this->provider->execUsing = fn (array $command) => new ExecResult($status['loggedIn'] ? 0 : 1, json_encode($status));

    $this->actingAs($this->user)
        ->getJson(route('projects.claude-login.show', $this->project))
        ->assertOk()
        ->assertExactJson(['signed_in' => $signedIn, 'email' => $email]);

    expect($this->provider->executed[0]['command'])->toBe(['claude', 'auth', 'status', '--json']);
})->with([
    'signed in' => [['loggedIn' => true, 'authMethod' => 'claude.ai', 'email' => 'dev@example.com'], true, 'dev@example.com'],
    'signed out' => [['loggedIn' => false, 'authMethod' => 'none'], false, null],
    'signed in to an API account instead' => [['loggedIn' => true, 'authMethod' => 'console', 'email' => 'dev@example.com'], false, null],
])->group('AI-005');

test('the sign-in status is unknown while the sandbox is not running', function () {
    $this->sandbox->update(['status' => SandboxStatus::Paused]);

    $this->actingAs($this->user)
        ->getJson(route('projects.claude-login.show', $this->project))
        ->assertExactJson(['signed_in' => null, 'email' => null]);

    expect($this->provider->executed)->toBe([]);
})->group('AI-005');

test('only the owner can see the sign-in status', function () {
    $this->actingAs(User::factory()->has(AgentConnection::factory())->create())
        ->getJson(route('projects.claude-login.show', $this->project))
        ->assertForbidden();
})->group('AI-005');

test('every sandbox of the user gets the same Claude sign-in folder', function () {
    $other = Project::factory()->for($this->user)->create();

    CreateSandbox::dispatchSync($other);

    expect(collect($this->provider->created)->last()->claudeLoginKey)->toBe("user-{$this->user->id}");
})->group('AI-005');

test('the workspace offers Claude Code\'s own sign-in in the Shell tab', function () {
    $this->actingAs($this->user)
        ->get(route('projects.show', $this->project))
        ->assertInertia(fn ($page) => $page->component('projects/show')
            ->where('claudeSubscription', true)
            ->where('sandbox.claude_login_url', 'http://127.0.0.1:7681/?arg=claude-login'));
})->group('AI-005');

test('signing out logs Claude Code out in the user\'s running sandboxes and deletes their sign-in folder', function () {
    $root = storageRoot();
    config(['sandbox.providers.docker.storage_path' => $root]);
    mkdir("{$root}/user-{$this->user->id}/claude", 0755, true);
    Sandbox::factory()->for(Project::factory())->create(['external_id' => 'someone-else']);
    Sandbox::factory()->for(Project::factory()->for($this->user))->create(['external_id' => 'paused', 'status' => SandboxStatus::Paused]);

    (new SignOutOfClaude($this->user->id))->handle($this->provider);

    expect($this->provider->executed)->toBe([['id' => 'ctr-1', 'command' => ['claude', 'auth', 'logout'], 'env' => [], 'detach' => false]])
        ->and(is_dir("{$root}/user-{$this->user->id}/claude"))->toBeFalse();
})->group('AI-005');

test('a rejected API key is explained without mentioning signing in', function () {
    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create(['status' => ProjectStatus::Working]);
    $sandbox = Sandbox::factory()->for($project)->create();

    $this->withToken($sandbox->issueEventsToken())
        ->postJson(route('sandbox-events.store', $sandbox), ['agent' => 'claude_code', 'events' => [
            ['type' => 'result', 'is_error' => true, 'api_error_status' => 401, 'result' => 'Failed to authenticate. API Error: 401 API key is invalid.'],
        ]])
        ->assertOk();

    expect($project->messages()->where('role', MessageRole::Assistant)->sole()->content)->toBe('Claude rejected your API key. Reconnect Claude in Settings → AI.');
})->group('AI-005');

test('saved Claude subscription tokens become sign-ins and the tokens are deleted', function () {
    $user = User::factory()->create();
    DB::table('agent_connections')->insert([
        'user_id' => $user->id,
        'provider' => 'claude',
        'credential_type' => 'oauth_token',
        'credential' => Crypt::encryptString('sk-ant-oat01-saved-token'),
        'hint' => 'oken',
        'is_default' => true,
        'verified_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    (require database_path('migrations/2026_09_29_151005_convert_claude_subscription_tokens_to_sign_ins.php'))->up();

    $connection = $user->agentConnections()->sole();
    expect($connection->credential_type)->toBe(CredentialType::ClaudeLogin)
        ->and($connection->credential)->toBe('')
        ->and($connection->hint)->toBe('')
        ->and(DB::table('agent_connections')->where('user_id', $user->id)->value('credential'))->not->toContain('sk-ant-oat');
})->group('AI-005');
