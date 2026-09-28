<?php

use App\Enums\ProjectStatus;
use App\Enums\SandboxStatus;
use App\Models\AgentConnection;
use App\Models\Group;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Agents\AgentRunner;
use App\Sandbox\Agents\FakeAgentRunner;
use App\Sandbox\ExecResult;
use App\Sandbox\OneDropSignIn;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use App\Sandbox\WorkspaceAuth;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    app()->instance(AgentRunner::class, new FakeAgentRunner);

    $this->workspace = authWorkspace();
    $this->provider = fakeDatabaseSandbox($this->workspace);
    app()->instance(SandboxProvider::class, $this->provider);

    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->project = Project::factory()->for($this->user)->create([
        'published_url' => 'https://demo.tail1234.ts.net',
    ]);
    $this->sandbox = Sandbox::factory()->for($this->project)->create([
        'external_id' => 'ctr-1',
        'preview_url' => 'http://127.0.0.1:49152',
    ]);
});

test('the owner sees how sign-in is set up, with sign-in and callback addresses', function () {
    $this->actingAs($this->user)
        ->getJson(route('projects.auth.show', $this->project))
        ->assertOk()
        ->assertJsonPath('configured', true)
        ->assertJsonPath('methods', ['password', 'google'])
        ->assertJsonPath('login_url', 'http://127.0.0.1:49152/login')
        ->assertJsonPath('published_login_url', 'https://demo.tail1234.ts.net/login')
        ->assertJsonPath('providers.0', [
            'id' => 'google',
            'enabled' => true,
            'client_id_set' => false,
            'client_secret_set' => false,
            'callback_urls' => [
                ['label' => 'Preview', 'url' => 'http://127.0.0.1:49152/auth/google/callback'],
                ['label' => 'Published', 'url' => 'https://demo.tail1234.ts.net/auth/google/callback'],
            ],
        ])
        ->assertJsonPath('providers.1.enabled', false);
})->group('APPAUTH-001');

test('on a server, addresses go through the gateway', function () {
    config(['sandbox.gateway_domain' => 'apps.example.com']);

    $this->actingAs($this->user)
        ->getJson(route('projects.auth.show', $this->project))
        ->assertJsonPath('login_url', route('projects.gateway.open', [$this->project, 'preview', 'path' => '/login']))
        ->assertJsonPath('providers.0.callback_urls.0.url', "https://preview-{$this->sandbox->id}.apps.example.com/auth/google/callback");
})->group('APPAUTH-001');

test('the owner can list and search the app users', function () {
    $this->actingAs($this->user)
        ->getJson(route('projects.auth.users', [$this->project, 'search' => 'ann']))
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('users.0.email', 'ann@example.com');
})->group('APPAUTH-001');

test('saving provider keys writes them into the app and restarts it', function () {
    $this->actingAs($this->user)
        ->putJson(route('projects.auth.keys', $this->project), [
            'provider' => 'google',
            'client_id' => 'abc.apps.googleusercontent.com',
            'client_secret' => 'GOCSPX-secret',
        ])
        ->assertOk()
        ->assertJsonPath('providers.0.client_id_set', true)
        ->assertJsonPath('providers.0.client_secret_set', true)
        ->assertJsonMissing(['GOCSPX-secret']);

    expect(file_get_contents($this->workspace.'/.env'))->toContain("GOOGLE_CLIENT_SECRET=GOCSPX-secret\n")
        ->and(collect($this->provider->executed)->pluck('command')->last())->toBe(['/opt/zap/restart']);
})->group('APPAUTH-001');

test('setting up asks the agent in the chat, and changing methods asks for just the change', function () {
    $empty = databaseWorkspace();
    app()->instance(SandboxProvider::class, fakeDatabaseSandbox($empty));

    $this->actingAs($this->user)
        ->postJson(route('projects.auth.setup', $this->project), ['methods' => ['password', 'github']])
        ->assertOk()
        ->assertJsonPath('queued', false);

    expect($this->project->messages()->latest('id')->value('content'))
        ->toBe('Add user sign-in to my app with email and password and GitHub. Follow the guide at /opt/zap/guides/auth.md.');

    app()->instance(SandboxProvider::class, $this->provider);
    $this->project->update(['status' => ProjectStatus::Working]);

    $this->actingAs($this->user)
        ->postJson(route('projects.auth.setup', $this->project), ['methods' => ['password', 'github', 'microsoft']])
        ->assertOk()
        ->assertJsonPath('queued', true);

    expect($this->project->queuedMessages()->value('content'))
        ->toBe('Change how people sign in to my app: turn on GitHub and Microsoft, and turn off Google. Follow the guide at /opt/zap/guides/auth.md.');

    $this->actingAs($this->user)
        ->postJson(route('projects.auth.setup', $this->project), ['methods' => ['google', 'password']])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Those are already the sign-in methods your app has.');

    $this->actingAs($this->user)
        ->postJson(route('projects.auth.setup', $this->project), ['methods' => ['passkeys']])
        ->assertJsonValidationErrors('methods.0');
})->group('APPAUTH-001');

test('other users cannot see or change sign-in', function () {
    $stranger = User::factory()->has(AgentConnection::factory())->create();

    $this->actingAs($stranger)->getJson(route('projects.auth.show', $this->project))->assertForbidden();
    $this->actingAs($stranger)->getJson(route('projects.auth.users', $this->project))->assertForbidden();
    $this->actingAs($stranger)->putJson(route('projects.auth.keys', $this->project), ['provider' => 'google', 'client_id' => 'x'])->assertForbidden();
    $this->actingAs($stranger)->postJson(route('projects.auth.setup', $this->project), ['methods' => ['password']])->assertForbidden();

    expect($this->provider->executed)->toBe([]);
})->group('APPAUTH-001');

test('it explains a stopped sandbox and a sandbox without the tool', function () {
    $this->sandbox->update(['status' => SandboxStatus::Paused]);
    $this->actingAs($this->user)->getJson(route('projects.auth.show', $this->project))->assertStatus(409);

    $this->sandbox->update(['status' => SandboxStatus::Running]);
    $old = new FakeSandboxProvider;
    $old->execUsing = fn () => new ExecResult(1, 'Could not open input file: '.WorkspaceAuth::SCRIPT);
    app()->instance(SandboxProvider::class, $old);

    $this->actingAs($this->user)
        ->getJson(route('projects.auth.show', $this->project))
        ->assertStatus(502)
        ->assertJsonPath('message', "This sandbox doesn't have the Users & Auth tool yet. Rebuild the sandbox image and recreate the sandbox.");
})->group('APPAUTH-001');

test('the owner can add, edit, set the password of, and delete app users', function () {
    withUsersHelper($this->workspace);
    $this->actingAs($this->user);

    $this->postJson(route('projects.auth.users.store', $this->project), ['name' => 'Dee', 'email' => 'dee@example.com', 'password' => 'correct horse'])
        ->assertOk()
        ->assertJsonPath('created', true);

    $this->patchJson(route('projects.auth.users.update', [$this->project, 4]), ['name' => 'Deedee', 'password' => 'another pass'])
        ->assertOk();

    expect(password_verify('another pass', workspacePasswordHash($this->workspace, 4)))->toBeTrue();
    $this->getJson(route('projects.auth.users', [$this->project, 'search' => 'dee@']))->assertJsonPath('users.0.name', 'Deedee');

    $this->deleteJson(route('projects.auth.users.destroy', [$this->project, 4]))->assertOk();
    $this->getJson(route('projects.auth.users', $this->project))->assertJsonPath('total', 3);

    $this->postJson(route('projects.auth.users.store', $this->project), ['name' => 'Short', 'email' => 'short@example.com', 'password' => 'short'])
        ->assertJsonValidationErrors('password');
    $this->deleteJson(route('projects.auth.users.destroy', [$this->project, 99]))
        ->assertUnprocessable()
        ->assertJsonPath('message', "That user doesn't exist any more. Refresh the list.");
})->group('APPAUTH-001');

test('asking for the users helper sends the agent a message', function () {
    $this->actingAs($this->user)->postJson(route('projects.auth.helper', $this->project))->assertOk();

    expect($this->project->messages()->latest('id')->value('content'))->toContain('.zap/users');
})->group('APPAUTH-001');

test('other users cannot change app users', function () {
    $stranger = User::factory()->has(AgentConnection::factory())->create();
    $this->actingAs($stranger);

    $this->postJson(route('projects.auth.users.store', $this->project), ['name' => 'X', 'email' => 'x@example.com', 'password' => 'correct horse'])->assertForbidden();
    $this->patchJson(route('projects.auth.users.update', [$this->project, 1]), ['name' => 'X'])->assertForbidden();
    $this->deleteJson(route('projects.auth.users.destroy', [$this->project, 1]))->assertForbidden();
    $this->postJson(route('projects.auth.helper', $this->project))->assertForbidden();
    $this->postJson(route('projects.auth.users.sign-out', [$this->project, 1]))->assertForbidden();

    expect($this->provider->executed)->toBe([]);
})->group('APPAUTH-001');

test('the owner can turn accounts off, require a new password and sign users out', function () {
    withAccountControls(withUsersHelper($this->workspace));
    $this->actingAs($this->user);

    $this->patchJson(route('projects.auth.users.update', [$this->project, 1]), ['disabled' => true, 'password_change_required' => true])->assertOk();
    $this->getJson(route('projects.auth.users', [$this->project, 'search' => 'ann']))
        ->assertJsonPath('users.0.password_change_required', true)
        ->assertJsonPath('capabilities', ['helper' => true, 'disable' => true, 'require_password_change' => true, 'roles' => true, 'sign_in_as' => false]);

    $this->postJson(route('projects.auth.users.sign-out', [$this->project, 2]))->assertOk();
    $this->patchJson(route('projects.auth.users.update', [$this->project, 3]), ['password' => 'new password', 'sign_out' => true])->assertOk();

    expect(signedOutUsers($this->workspace))->toBe(['2', '3']);
})->group('APPAUTH-001');

test('asking for missing account controls names only what is missing', function () {
    withUsersHelper($this->workspace);

    $this->actingAs($this->user)->postJson(route('projects.auth.helper', $this->project))->assertOk();

    expect($this->project->messages()->latest('id')->value('content'))
        ->toStartWith('Let me manage users from Tools → Users & Auth: add turning accounts off, requiring a new password, roles and signing in as a user.');

    withAccountControls($this->workspace);
    file_put_contents($this->workspace.'/.zap/auth.json', json_encode(['methods' => ['password'], 'helper' => ['sign-in-link']]));
    $this->actingAs($this->user)->postJson(route('projects.auth.helper', $this->project))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Your app already has every account control.');
})->group('APPAUTH-001');

test('the owner can change a user role', function () {
    withAccountControls($this->workspace);
    $this->actingAs($this->user);

    $this->patchJson(route('projects.auth.users.update', [$this->project, 2]), ['role' => 'admin'])->assertOk();
    $this->getJson(route('projects.auth.users', [$this->project, 'search' => 'bob']))
        ->assertJsonPath('users.0.role', 'admin')
        ->assertJsonPath('roles', ['admin', 'member']);

    $this->patchJson(route('projects.auth.users.update', [$this->project, 2]), ['role' => 'owner'])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Your app has no role called that.');
})->group('APPAUTH-001');

test('the owner can download the users as a CSV, safe to open in a spreadsheet', function () {
    withAccountControls($this->workspace);
    (new PDO('sqlite:'.$this->workspace.'/database/database.sqlite'))->exec("UPDATE users SET name = '=HYPERLINK(\"http://evil\")', disabled_at = '2026-09-25 00:00:00' WHERE id = 2");

    $csv = $this->actingAs($this->user)
        ->get(route('projects.auth.users.export', $this->project))
        ->assertOk()
        ->assertDownload()
        ->streamedContent();

    expect(explode("\n", trim($csv)))->toBe([
        'id,name,email,role,status,joined,last_signed_in',
        '3,Cy_1,100%@example.com,member,active,"2026-09-03 10:00:00",',
        '2,"\'=HYPERLINK(""http://evil"")",bob@example.com,member,"turned off","2026-09-02 10:00:00",',
        '1,Ann,ann@example.com,admin,active,"2026-09-01 10:00:00","2026-09-20 08:00:00"',
    ]);
})->group('APPAUTH-001');

test('the owner gets a one-time link that opens the app as a user', function () {
    withUsersHelper($this->workspace);
    file_put_contents($this->workspace.'/.zap/auth.json', json_encode(['methods' => ['password'], 'helper' => ['sign-in-link']]));

    $this->actingAs($this->user)
        ->postJson(route('projects.auth.users.sign-in', [$this->project, 2]))
        ->assertOk()
        ->assertJsonPath('url', 'http://127.0.0.1:49152/auth/zap-sign-in?token=one-time-2');
})->group('APPAUTH-001');

test('choosing OneDrop accounts sets up the app keys and asks the agent', function () {
    config(['sandbox.callback_url' => 'http://host.docker.internal:8000']);

    $this->actingAs($this->user)
        ->postJson(route('projects.auth.setup', $this->project), ['methods' => ['password', 'google', 'onedrop']])
        ->assertOk();

    $project = $this->project->fresh();
    $env = file_get_contents($this->workspace.'/.env');

    expect($project->onedrop_enabled)->toBeTrue()
        ->and($project->onedrop_callback_path)->toBe('/auth/onedrop/callback')
        ->and($env)->toContain("ONEDROP_CLIENT_ID={$project->onedrop_client_id}\n")
        ->toContain("ONEDROP_TOKEN_URL=http://host.docker.internal:8000/oauth/token\n")
        ->and(collect($this->provider->executed)->pluck('command')->last())->toBe(['/opt/zap/restart'])
        ->and($this->project->messages()->latest('id')->value('content'))->toContain('turn on OneDrop accounts');

    preg_match('/^ONEDROP_CLIENT_SECRET=(.+)$/m', $env, $secret);
    expect(app(OneDropSignIn::class)->authenticate($project->onedrop_client_id, $secret[1]))->not->toBeNull();

    $this->getJson(route('projects.auth.show', $this->project))->assertJsonPath('onedrop.enabled', true);
})->group('APPAUTH-002');

test('turning OneDrop accounts off stops OneDrop sign-ins', function () {
    app(OneDropSignIn::class)->enable($this->project, '/auth/onedrop/callback');
    file_put_contents($this->workspace.'/.zap/auth.json', json_encode(['methods' => ['password', 'onedrop']]));

    $this->actingAs($this->user)
        ->postJson(route('projects.auth.setup', $this->project), ['methods' => ['password']])
        ->assertOk();

    expect($this->project->fresh()->onedrop_enabled)->toBeFalse();
})->group('APPAUTH-002');

test('the owner chooses which groups may sign in with OneDrop', function () {
    $groups = Group::factory()->count(2)->create();
    $this->actingAs($this->user);

    $this->putJson(route('projects.auth.onedrop', $this->project), ['group_ids' => [$groups[1]->id, $groups[1]->id]])
        ->assertOk()
        ->assertJsonPath('group_ids', [$groups[1]->id])
        ->assertJsonCount(2, 'groups');

    $this->putJson(route('projects.auth.onedrop', $this->project), ['group_ids' => null])->assertJsonPath('group_ids', null);
    $this->putJson(route('projects.auth.onedrop', $this->project), ['group_ids' => [999]])->assertJsonValidationErrors('group_ids.0');
})->group('APPAUTH-002');

test('other users cannot export, sign in as users or change OneDrop access', function () {
    $stranger = User::factory()->has(AgentConnection::factory())->create();
    $this->actingAs($stranger);

    $this->get(route('projects.auth.users.export', $this->project))->assertForbidden();
    $this->postJson(route('projects.auth.users.sign-in', [$this->project, 1]))->assertForbidden();
    $this->putJson(route('projects.auth.onedrop', $this->project), ['group_ids' => null])->assertForbidden();
})->group('APPAUTH-001', 'APPAUTH-002');
