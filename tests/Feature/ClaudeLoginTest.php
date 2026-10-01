<?php

use App\Enums\CredentialType;
use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Enums\SandboxStatus;
use App\Jobs\CreateSandbox;
use App\Jobs\RunAgentTask;
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
use Illuminate\Support\Facades\Queue;

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

test('signing in runs the message that failed because Claude Code wasn\'t signed in', function () {
    Queue::fake();
    $this->provider->execUsing = fn (array $command) => new ExecResult(0, json_encode(['loggedIn' => true, 'authMethod' => 'claude.ai']));
    $message = $this->project->messages()->create(['role' => MessageRole::User, 'content' => 'Build a timer']);
    $this->project->messages()->create(['role' => MessageRole::Assistant, 'content' => 'Sign in to Claude…']);
    $this->project->update(['sign_in_retry_message_id' => $message->id]);

    $this->actingAs($this->user)
        ->postJson(route('projects.claude-login.resume', $this->project))
        ->assertExactJson(['resumed' => true]);

    $this->project->refresh();
    expect($this->project->status)->toBe(ProjectStatus::Working)
        ->and($this->project->sign_in_retry_message_id)->toBeNull()
        ->and($this->project->messages()->where('role', MessageRole::User)->count())->toBe(1)
        ->and($this->project->messages()->reorder()->latest('id')->value('content'))->toBe('Signed in to Claude, picking up where it left off');
    Queue::assertPushed(RunAgentTask::class, fn (RunAgentTask $job) => $job->message->is($message));
})->group('AI-005');

test('nothing runs again while Claude Code is still signed out, or when nothing failed', function (bool $pending, array $status) {
    Queue::fake();
    $this->provider->execUsing = fn (array $command) => new ExecResult(1, json_encode($status));
    $message = $this->project->messages()->create(['role' => MessageRole::User, 'content' => 'Build a timer']);
    $this->project->update(['sign_in_retry_message_id' => $pending ? $message->id : null]);

    $this->actingAs($this->user)
        ->postJson(route('projects.claude-login.resume', $this->project))
        ->assertExactJson(['resumed' => false]);

    expect($this->project->fresh()->sign_in_retry_message_id)->toBe($pending ? $message->id : null);
    Queue::assertNothingPushed();
})->with([
    'still signed out' => [true, ['loggedIn' => false, 'authMethod' => 'none']],
    'nothing failed' => [false, ['loggedIn' => true, 'authMethod' => 'claude.ai']],
])->group('AI-005');

test('sending another message drops the one waiting for sign-in', function () {
    Queue::fake();
    $message = $this->project->messages()->create(['role' => MessageRole::User, 'content' => 'Build a timer']);
    $this->project->update(['sign_in_retry_message_id' => $message->id]);

    $this->actingAs($this->user)->post(route('projects.messages.store', $this->project), ['content' => 'Build a clock instead']);

    expect($this->project->fresh()->sign_in_retry_message_id)->toBeNull();
})->group('AI-005');

test('only the owner can pick the chat back up', function () {
    $this->actingAs(User::factory()->has(AgentConnection::factory())->create())
        ->postJson(route('projects.claude-login.resume', $this->project))
        ->assertForbidden();
})->group('AI-005');

test('every sandbox of the user gets the same Claude sign-in folder', function () {
    $other = Project::factory()->for($this->user)->create();

    CreateSandbox::dispatchSync($other);

    expect(collect($this->provider->created)->last()->claudeLoginKey)->toBe("user-{$this->user->id}");
})->group('AI-005');

test('the workspace offers Claude Code\'s own sign-in in the Shell tab', function (string $shellUrl, string $loginUrl) {
    $this->sandbox->update(['shell_url' => $shellUrl]);

    $this->actingAs($this->user)
        ->get(route('projects.show', $this->project))
        ->assertInertia(fn ($page) => $page->component('projects/show')
            ->where('claudeSubscription', true)
            ->where('sandbox.claude_login_url', $loginUrl));
})->with([
    'docker' => ['http://127.0.0.1:7681', 'http://127.0.0.1:7681/?arg=claude-login'],
    'runtime, whose address carries its token' => ['https://7681-abc.runtimehost.com/?runtime_preview_token=t', 'https://7681-abc.runtimehost.com/?runtime_preview_token=t&arg=claude-login'],
])->group('AI-005');

test('the workspace tells the chat when a message is waiting for a Claude sign-in', function (bool $waiting) {
    $message = $this->project->messages()->create(['role' => MessageRole::User, 'content' => 'Build a timer']);
    $this->project->update(['sign_in_retry_message_id' => $waiting ? $message->id : null]);

    $this->actingAs($this->user)
        ->get(route('projects.show', $this->project))
        ->assertInertia(fn ($page) => $page->where('project.waiting_for_sign_in', $waiting));
})->with(['waiting' => true, 'not waiting' => false])->group('AI-005');

test('signing out logs Claude Code out in the user\'s running sandboxes and empties their sign-in folder', function () {
    $root = storageRoot();
    config(['sandbox.providers.docker.storage_path' => $root]);
    mkdir("{$root}/user-{$this->user->id}/claude/backups", 0755, true);
    file_put_contents("{$root}/user-{$this->user->id}/claude/.credentials.json", '{}');
    Sandbox::factory()->for(Project::factory())->create(['external_id' => 'someone-else']);
    Sandbox::factory()->for(Project::factory()->for($this->user))->create(['external_id' => 'paused', 'status' => SandboxStatus::Paused]);

    (new SignOutOfClaude($this->user->id))->handle($this->provider);

    expect($this->provider->executed)->toBe([['id' => 'ctr-1', 'command' => ['claude', 'auth', 'logout'], 'env' => [], 'detach' => false]])
        // Kept, so the sandboxes that have it mounted can still save the next sign-in.
        ->and(is_dir("{$root}/user-{$this->user->id}/claude"))->toBeTrue()
        ->and(glob("{$root}/user-{$this->user->id}/claude/{,.}[!.]*", GLOB_BRACE))->toBe([]);
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
