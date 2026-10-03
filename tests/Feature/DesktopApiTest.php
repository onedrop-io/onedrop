<?php

use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Jobs\CreateSandbox;
use App\Jobs\RunAgentTask;
use App\Jobs\UpdateProjectIcon;
use App\Models\AgentConnection;
use App\Models\Attachment;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Agents\AgentRunner;
use App\Sandbox\Agents\Conversation;
use App\Sandbox\Agents\FakeAgentRunner;
use App\Sandbox\ExecResult;
use App\Sandbox\Gateway;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->token = $this->user->createToken('Laptop')->plainTextToken;
    $this->project = Project::factory()->for($this->user)->create(['name' => 'Vacation calendar']);
});

test('the app lists the user\'s projects as the sidebar does', function () {
    Project::factory()->for($this->user)->create(['name' => 'Pinned one', 'pinned_at' => now()]);
    Project::factory()->create(['name' => 'Someone else\'s']);

    $this->withToken($this->token)->getJson(route('api.projects.index'))
        ->assertOk()
        ->assertJsonPath('pinned.0.name', 'Pinned one')
        ->assertJsonPath('recent.0.name', 'Vacation calendar')
        ->assertJsonCount(1, 'recent')
        ->assertJsonPath('archived', []);
})->group('DESK-002');

test('project icons load through the API', function () {
    Storage::fake('local');
    Storage::disk('local')->put('icons/1.png', 'png');
    $this->project->forceFill(['icon_path' => 'icons/1.png', 'icon_hash' => str_repeat('a', 40), 'icon_mime' => 'image/png'])->save();

    $url = $this->withToken($this->token)->getJson(route('api.projects.index'))->json('recent.0.icon_url');

    expect($url)->toBe(route('api.projects.icon.show', ['project' => $this->project, 'v' => 'aaaaaaaaaaaa']));
})->group('DESK-002');

test('the app opens a project\'s workspace, which marks it read', function () {
    Sandbox::factory()->for($this->project)->create(['preview_url' => 'http://localhost:5173']);
    $this->project->messages()->create(['role' => MessageRole::User, 'content' => 'a vacation calendar']);
    $this->project->messages()->create(['role' => MessageRole::Assistant, 'content' => 'Done!']);

    $this->withToken($this->token)->getJson(route('api.projects.show', $this->project))
        ->assertOk()
        ->assertJsonPath('project.name', 'Vacation calendar')
        ->assertJsonPath('messages.1.content', 'Done!')
        ->assertJsonPath('sandbox.preview_url', 'http://localhost:5173');

    expect($this->project->fresh()->read_at)->not->toBeNull();
})->group('DESK-003');

test('a workspace fetched while nobody is looking stays unread', function () {
    $this->withToken($this->token)->withHeader('X-Onedrop-Unseen', '1')
        ->getJson(route('api.projects.show', $this->project))
        ->assertOk();

    expect($this->project->fresh()->read_at)->toBeNull();
})->group('DESK-003');

test('on a server the preview opens through the sandbox\'s own sign-in address', function () {
    config(['sandbox.gateway_domain' => 'onedrop.example.com']);
    $sandbox = Sandbox::factory()->for($this->project)->create(['preview_url' => 'http://127.0.0.1:41000']);

    $url = $this->withToken($this->token)->getJson(route('api.projects.show', $this->project))->json('sandbox.preview_url');

    expect($url)->toStartWith("https://preview-{$sandbox->id}.onedrop.example.com/__onedrop/enter?");
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    expect(app(Gateway::class)->userFromToken($query['token'], ['kind' => 'preview', 'sandbox_id' => $sandbox->id]))->toBe($this->user->id);
})->group('DESK-003');

test('attachments open through the API', function () {
    Storage::fake(Attachment::disk());
    $message = $this->project->messages()->create(['role' => MessageRole::User, 'content' => 'look']);
    $attachment = Attachment::store($message, UploadedFile::fake()->image('shot.png'));

    $url = $this->withToken($this->token)->getJson(route('api.projects.show', $this->project))->json('messages.0.attachments.0.url');

    expect($url)->toBe(route('api.projects.attachments.show', [$this->project, $attachment]));
    $this->withToken($this->token)->get($url)->assertOk();
})->group('DESK-003');

test('the app cannot open someone else\'s project', function () {
    $theirs = Project::factory()->create();

    $this->withToken($this->token)->getJson(route('api.projects.show', $theirs))->assertForbidden();
    $this->withToken($this->token)->postJson(route('api.projects.messages.store', $theirs), ['content' => 'hi'])->assertForbidden();
    $this->withToken($this->token)->deleteJson(route('api.projects.destroy', $theirs))->assertForbidden();
})->group('DESK-003');

test('the app starts a project from a description', function () {
    Queue::fake();

    $id = $this->withToken($this->token)->postJson(route('api.projects.store'), ['prompt' => 'a habit tracker'])
        ->assertCreated()
        ->json('id');

    $project = Project::findOrFail($id);
    expect($project->user_id)->toBe($this->user->id)
        ->and($project->status)->toBe(ProjectStatus::Working)
        ->and($project->messages()->sole()->content)->toBe('a habit tracker');
    Queue::assertPushedWithChain(CreateSandbox::class, [RunAgentTask::class, UpdateProjectIcon::class]);
})->group('DESK-004');

test('a new project needs a description', function () {
    $this->withToken($this->token)->postJson(route('api.projects.store'), ['prompt' => ''])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['prompt' => 'Describe what you want to build.']);
})->group('DESK-004');

test('the app shows what a new project starts from', function () {
    $this->withToken($this->token)->getJson(route('api.projects.create'))
        ->assertOk()
        ->assertJsonStructure(['agent', 'templates']);
})->group('DESK-004');

test('without an AI the app is sent to the web to set one up', function () {
    $token = User::factory()->create()->createToken('Laptop')->plainTextToken;

    $this->withToken($token)->getJson(route('api.user.show'))->assertOk()->assertJsonPath('user.ai_connected', false);
    $this->withToken($token)->getJson(route('api.projects.index'))->assertStatus(409)->assertJsonPath('message', 'Set up AI on the web first.');
})->group('DESK-001');

test('the app messages the agent, queues while it works, and stops it', function () {
    Queue::fake();
    app()->instance(AgentRunner::class, new class extends FakeAgentRunner
    {
        public function stop(Conversation $conversation): void {}
    });

    $this->withToken($this->token)->postJson(route('api.projects.messages.store', $this->project), ['content' => 'add dark mode'])
        ->assertCreated()
        ->assertJsonPath('queued', false);
    Queue::assertPushed(RunAgentTask::class);

    $this->project->update(['status' => ProjectStatus::Working]);
    $queued = $this->withToken($this->token)->postJson(route('api.projects.messages.store', $this->project), ['content' => 'and a logo'])
        ->assertCreated()
        ->assertJsonPath('queued', true)
        ->json('id');

    $this->withToken($this->token)->postJson(route('api.projects.agent.stop', $this->project))
        ->assertOk()
        ->assertJsonPath('draft', 'and a logo')
        ->assertJsonPath('dropped_attachments', 0);

    expect(Message::find($queued))->toBeNull();
})->group('DESK-003');

test('the app removes a queued message', function () {
    $message = $this->project->messages()->create(['role' => MessageRole::User, 'content' => 'later', 'queued' => true]);

    $this->withToken($this->token)->deleteJson(route('api.projects.messages.destroy', [$this->project, $message]))->assertNoContent();

    expect(Message::find($message->id))->toBeNull();
})->group('DESK-003');

test('the app renames, pins and archives a project', function () {
    $this->withToken($this->token)->patchJson(route('api.projects.update', $this->project), ['name' => '  Team   calendar ', 'pinned' => true])
        ->assertNoContent();

    expect($this->project->fresh())
        ->name->toBe('Team calendar')
        ->pinned_at->not->toBeNull();

    $this->withToken($this->token)->patchJson(route('api.projects.update', $this->project), ['archived' => true, 'pinned' => false])
        ->assertNoContent();

    expect($this->project->fresh())
        ->archived_at->not->toBeNull()
        ->pinned_at->toBeNull();
})->group('DESK-002');

test('the app deletes a project', function () {
    $this->withToken($this->token)->deleteJson(route('api.projects.destroy', $this->project))->assertNoContent();

    expect(Project::find($this->project->id))->toBeNull();
})->group('DESK-002');

test('the app keeps the sandbox awake while it shows the project', function () {
    Sandbox::factory()->for($this->project)->create();

    $this->withToken($this->token)->postJson(route('api.projects.sandbox.activity', $this->project))
        ->assertOk()
        ->assertJsonPath('woke', false);
})->group('DESK-003');

test('the app gets the models its picker offers, and stars them', function () {
    $this->withToken($this->token)->getJson(route('api.agent-models.index'))
        ->assertOk()
        ->assertJsonStructure(['harnesses', 'providers', 'favorites', 'recent']);

    $this->withToken($this->token)->putJson(route('api.agent-models.favorite'), ['provider' => 'claude', 'model' => 'claude-sonnet-5', 'favorite' => true])
        ->assertOk()
        ->assertJsonPath('favorites', ['claude:claude-sonnet-5']);
})->group('DESK-003', 'AGT-002');

test('the app learns where to connect for live updates', function () {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'app-key',
        'broadcasting.connections.reverb.browser' => ['host' => 'ws.example.com', 'port' => 443, 'scheme' => 'https'],
    ]);

    $this->withToken($this->token)->getJson(route('api.user.show'))
        ->assertJsonPath('realtime.key', 'app-key')
        ->assertJsonPath('realtime.host', 'ws.example.com')
        ->assertJsonPath('app.url', url('/'))
        ->assertJsonPath('links.usage', route('usage.index'));
})->group('DESK-005');

test('the app listens on the channels of projects it can see', function () {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
    ]);
    // Channels are registered on the broadcaster that was the default when the app booted.
    require base_path('routes/channels.php');

    $this->withToken($this->token)
        ->postJson(route('api.broadcasting.auth'), ['socket_id' => '1234.5678', 'channel_name' => "private-project.{$this->project->id}"])
        ->assertOk()
        ->assertJsonStructure(['auth']);

    $this->withToken($this->token)
        ->postJson(route('api.broadcasting.auth'), ['socket_id' => '1234.5678', 'channel_name' => 'private-project.'.Project::factory()->create()->id])
        ->assertForbidden();
})->group('DESK-005');

test('the app works in one organization at a time and can switch to the user\'s others', function () {
    $home = $this->user->currentOrganization();
    $other = Organization::factory()->withMember($this->user)->create(['name' => 'Acme']);
    Project::factory()->for($this->user)->create(['name' => 'Acme portal', 'organization_id' => $other->id]);

    $this->withToken($this->token)->getJson(route('api.user.show'))
        ->assertJsonPath('organization.id', $home->id)
        ->assertJsonCount(2, 'organizations');
    $this->withToken($this->token)->getJson(route('api.projects.index'))
        ->assertJsonPath('recent.0.name', 'Vacation calendar')
        ->assertJsonCount(1, 'recent');

    $this->withToken($this->token)->putJson(route('api.user.organization.update'), ['organization' => $other->id])->assertNoContent();

    $this->withToken($this->token)->getJson(route('api.projects.index'))
        ->assertJsonPath('recent.0.name', 'Acme portal')
        ->assertJsonCount(1, 'recent');
    $this->withToken($this->token)->postJson(route('api.projects.store'), ['prompt' => 'an intranet'])->assertCreated();
    expect(Project::latest('id')->first()->organization_id)->toBe($other->id);

    $this->withToken($this->token)->putJson(route('api.user.organization.update'), ['organization' => Organization::factory()->create()->id])->assertNotFound();
})->group('DESK-002', 'ORG-002');

test('projects in an organization the user left are not found', function () {
    $elsewhere = Project::factory()->create(['organization_id' => Organization::factory()->create()->id]);

    $this->withToken($this->token)->getJson(route('api.projects.show', $elsewhere))->assertNotFound();
})->group('DESK-003', 'ORG-001');

test('the app asks whether Claude Code is signed in, and carries on once it is', function () {
    $provider = new FakeSandboxProvider;
    $provider->execUsing = fn (array $command) => new ExecResult(0, json_encode(['loggedIn' => true, 'authMethod' => 'claude.ai', 'email' => 'dev@example.com']));
    app()->instance(SandboxProvider::class, $provider);
    Sandbox::factory()->for($this->project)->create(['external_id' => 'ctr-1']);

    $this->withToken($this->token)->getJson(route('api.projects.claude-login.show', $this->project))
        ->assertOk()
        ->assertJsonPath('signed_in', true)
        ->assertJsonPath('email', 'dev@example.com');

    // Nothing failed for want of a sign-in, so there's nothing to run again.
    $this->withToken($this->token)->postJson(route('api.projects.claude-login.resume', $this->project))
        ->assertOk()
        ->assertJsonPath('resumed', false);
})->group('DESK-003', 'AI-005');

test('a busy app can still check whether Claude Code is signed in', function () {
    $provider = new FakeSandboxProvider;
    $provider->execUsing = fn (array $command) => new ExecResult(1, json_encode(['loggedIn' => false]));
    app()->instance(SandboxProvider::class, $provider);
    Sandbox::factory()->for($this->project)->create(['external_id' => 'ctr-1']);

    // A minute of the workspace refreshing and keeping its sandbox awake.
    foreach (range(1, 40) as $request) {
        $this->withToken($this->token)->postJson(route('api.projects.sandbox.activity', $this->project))->assertOk();
    }

    $this->withToken($this->token)->getJson(route('api.projects.claude-login.show', $this->project))
        ->assertOk()
        ->assertJsonPath('signed_in', false);
})->group('DESK-003', 'AI-005');
