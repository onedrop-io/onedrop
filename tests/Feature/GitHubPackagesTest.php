<?php

use App\Enums\OrganizationRole;
use App\Jobs\SyncOrganizationSecrets;
use App\Models\AgentConnection;
use App\Models\Organization;
use App\Models\OrganizationSecret;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\SocialAccount;
use App\Models\User;
use App\Sandbox\OrganizationSecrets;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function () {
    config([
        'app.multi_tenant' => true,
        'services.github.client_id' => 'oauth-app-id',
        'services.github.client_secret' => 'oauth-app-secret',
        'services.github_app.client_id' => 'github-app-id',
    ]);
    $this->provider = new FakeSandboxProvider;
    app()->instance(SandboxProvider::class, $this->provider);

    $this->organization = Organization::factory()->create();
    $this->owner = User::factory()->has(AgentConnection::factory())->create(['name' => 'Ada']);
    $this->organization->addMember($this->owner, OrganizationRole::Member);
    $this->project = Project::factory()->for($this->owner)->for($this->organization)->create();
});

/**
 * GitHub sending the person back, having granted the given scopes.
 *
 * @param  list<string>  $scopes
 */
function returnFromGitHub(mixed $test, array $scopes, string $token = 'gho_new'): void
{
    Socialite::fake('github', SocialiteUser::fake(['id' => '42', 'email' => 'ada@example.com', 'token' => $token, 'approvedScopes' => $scopes]));

    $test->get(route('social.callback', ['provider' => 'github', 'code' => 'abc']))->assertRedirect();
}

/**
 * The secrets the platform last wrote into a sandbox, decoded.
 *
 * @return array<string, string>
 */
function writtenSecrets(FakeSandboxProvider $provider): array
{
    $write = collect($provider->executed)->last(fn (array $call) => isset($call['env']['ONEDROP_ORG_SECRETS']));

    return collect(explode("\n", trim($write['env']['ONEDROP_ORG_SECRETS'] ?? '')))->filter()
        ->mapWithKeys(fn (string $line) => [explode(' ', $line)[0] => base64_decode(explode(' ', $line)[1])])
        ->all();
}

test('GitHub sign-in asks for read:packages', function () {
    $location = $this->get(route('social.redirect', 'github'))->assertRedirect()->headers->get('Location');

    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect(explode(',', $query['scope']))->toContain('user:email', 'read:packages');
})->group('GIT-016');

test('connecting GitHub with package access keeps the token encrypted and updates the person\'s sandboxes', function () {
    Queue::fake();

    $this->actingAs($this->owner);
    returnFromGitHub($this, ['read:packages', 'user:email']);

    $account = SocialAccount::sole();
    expect($account->canReadPackages())->toBeTrue()
        ->and($account->token)->toBe('gho_new')
        ->and(DB::table('social_accounts')->value('token'))->not->toContain('gho_new')
        ->and($account->toArray())->not->toHaveKey('token');
    Queue::assertPushed(SyncOrganizationSecrets::class, fn (SyncOrganizationSecrets $job) => $job->for->is($this->owner) && $job->sandbox === null);
})->group('GIT-016');

test('a sign-in that wasn\'t granted package access keeps no token, and drops an earlier one', function () {
    Queue::fake();
    SocialAccount::factory()->for($this->owner)->withPackages()->create(['provider_id' => '42']);

    $this->actingAs($this->owner);
    returnFromGitHub($this, ['user:email']);

    expect(SocialAccount::sole()->only('token', 'scopes'))->toBe(['token' => null, 'scopes' => null]);
})->group('GIT-016');

test('a project\'s sandbox gets its owner\'s token as GITHUB_TOKEN', function () {
    SocialAccount::factory()->for($this->owner)->withPackages('gho_ada')->create();
    $sandbox = Sandbox::factory()->for($this->project)->create(['external_id' => 'ctr-1']);

    app(OrganizationSecrets::class)->sync($sandbox);

    expect(writtenSecrets($this->provider))->toBe(['GITHUB_TOKEN' => 'gho_ada'])
        ->and(collect($this->provider->installed)->sole()['files'])->toContain('lazy/docker-credential-onedrop');
})->group('GIT-016');

test('an organization secret named GITHUB_TOKEN wins over the owner\'s token', function () {
    SocialAccount::factory()->for($this->owner)->withPackages('gho_ada')->create();
    OrganizationSecret::factory()->for($this->organization)->create(['name' => 'GITHUB_TOKEN', 'value' => 'ghp_shared']);

    expect(app(OrganizationSecrets::class)->for($this->project))->toBe(['GITHUB_TOKEN' => 'ghp_shared']);
})->group('GIT-016');

test('no token reaches a project without package access, or once its owner left the organization', function () {
    $account = SocialAccount::factory()->for($this->owner)->create(['token' => null]);
    $secrets = app(OrganizationSecrets::class);

    expect($secrets->for($this->project))->toBe([]);

    $account->update(['token' => 'gho_ada', 'scopes' => 'read:packages']);
    expect($secrets->for($this->project->fresh()))->toBe(['GITHUB_TOKEN' => 'gho_ada']);

    $this->organization->removeMember($this->owner);
    expect($secrets->for($this->project->fresh()))->toBe([]);
})->group('GIT-016');

test('a change to someone\'s GitHub connection reaches only their projects\' awake sandboxes', function () {
    SocialAccount::factory()->for($this->owner)->withPackages()->create();
    Sandbox::factory()->for($this->project)->create(['external_id' => 'ctr-mine']);
    Sandbox::factory()->for(Project::factory()->for($this->owner)->for($this->organization))->create(['external_id' => 'ctr-asleep', 'suspended_at' => now()]);
    Sandbox::factory()->for(Project::factory()->for($this->organization))->create(['external_id' => 'ctr-theirs']);

    SyncOrganizationSecrets::dispatchSync($this->owner);

    expect(collect($this->provider->executed)->filter(fn ($call) => isset($call['env']['ONEDROP_ORG_SECRETS']))->pluck('id')->all())->toBe(['ctr-mine']);
})->group('GIT-016');

test('disconnecting GitHub, or leaving the organization, updates the person\'s sandboxes', function () {
    Queue::fake();
    $this->owner->forceFill(['password' => 'secret-password'])->save();
    $account = SocialAccount::factory()->for($this->owner)->withPackages()->create();

    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('social-accounts.destroy', $account))->assertRedirect();

    Queue::assertPushed(SyncOrganizationSecrets::class, fn (SyncOrganizationSecrets $job) => $job->for->is($this->owner));

    Queue::fake();
    $this->organization->addMember(User::factory()->create(), OrganizationRole::Owner);
    $this->actingAs($this->owner)->delete(route('organizations.members.destroy', ['organization' => $this->organization, 'user' => $this->owner]))->assertRedirect();

    Queue::assertPushed(SyncOrganizationSecrets::class, fn (SyncOrganizationSecrets $job) => $job->for->is($this->owner));
})->group('GIT-016');

test('Settings → Security says whether GitHub lets the person\'s projects download packages', function (?string $token, ?string $scopes, ?string $expected) {
    if ($scopes !== 'none') {
        SocialAccount::factory()->for($this->owner)->create(['token' => $token, 'scopes' => $scopes]);
    }

    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertInertia(fn (Assert $page) => $page->where(
            'socialAccounts',
            fn ($rows) => collect($rows)->firstWhere('provider', 'github')['packages'] === $expected,
        ));
})->with([
    'granted' => ['gho_ada', 'read:packages', 'granted'],
    'connected through the GitHub App' => [null, null, 'reconnect'],
    'not connected' => [null, 'none', 'connect'],
])->group('GIT-016');

test('without a separate OAuth app, Settings → Security doesn\'t offer package access', function () {
    config(['services.github.client_id' => 'github-app-id']);

    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertInertia(fn (Assert $page) => $page->where(
            'socialAccounts',
            fn ($rows) => collect($rows)->firstWhere('provider', 'github')['packages'] === null,
        ));
})->group('GIT-016');

test('Tools → Secrets says whose GitHub token the project has, unless a secret replaces it', function () {
    $workspace = sys_get_temp_dir().'/onedrop-github-packages-'.bin2hex(random_bytes(4));
    mkdir($workspace);
    register_shutdown_function(fn () => exec('rm -rf '.escapeshellarg($workspace)));
    app()->instance(SandboxProvider::class, fakeDatabaseSandbox($workspace));
    Sandbox::factory()->for($this->project)->create(['external_id' => 'ctr-1']);
    SocialAccount::factory()->for($this->owner)->withPackages('gho_ada')->create();

    $this->actingAs($this->owner)->getJson(route('projects.secrets.index', $this->project))
        ->assertOk()
        ->assertJsonPath('github', 'Ada')
        ->assertDontSee('gho_ada');

    OrganizationSecret::factory()->for($this->organization)->create(['name' => 'GITHUB_TOKEN']);

    $this->actingAs($this->owner)->getJson(route('projects.secrets.index', $this->project))->assertJsonPath('github', null);
})->group('GIT-016');

test('the sandbox points Docker and npm at GITHUB_TOKEN while it has one, without writing the token there', function () {
    $sandbox = Sandbox::factory()->for($this->project)->create(['external_id' => 'ctr-1']);
    $account = SocialAccount::factory()->for($this->owner)->withPackages('gho_ada')->create();
    $root = sys_get_temp_dir().'/onedrop-github-home-'.bin2hex(random_bytes(4));
    mkdir("{$root}/.docker", 0777, true);
    register_shutdown_function(fn () => exec('rm -rf '.escapeshellarg($root)));
    file_put_contents("{$root}/.npmrc", "save-exact=true\n");
    file_put_contents("{$root}/.docker/config.json", '{"auths":{"registry.example.com":{}}}');

    $run = function () use ($sandbox, $root) {
        app(OrganizationSecrets::class)->sync($sandbox);
        $write = collect($this->provider->executed)->last(fn (array $call) => isset($call['env']['ONEDROP_ORG_SECRETS']));
        [, , $script] = $write['command'];
        $env = 'HOME='.escapeshellarg($root).' ONEDROP_ORG_SECRETS='.escapeshellarg($write['env']['ONEDROP_ORG_SECRETS']);
        exec("{$env} bash -c ".escapeshellarg($script).' org-secrets '.escapeshellarg("{$root}/secrets"), $output, $status);
        expect($status)->toBe(0);
    };

    $run();

    expect(file_get_contents("{$root}/.npmrc"))->toBe("save-exact=true\n//npm.pkg.github.com/:_authToken=\${GITHUB_TOKEN}\n")
        ->and(json_decode(file_get_contents("{$root}/.docker/config.json"), true))->toBe(['auths' => ['registry.example.com' => []], 'credHelpers' => ['ghcr.io' => 'onedrop']])
        ->and(file_get_contents("{$root}/.npmrc").file_get_contents("{$root}/.docker/config.json"))->not->toContain('gho_ada');

    $run();
    expect(substr_count(file_get_contents("{$root}/.npmrc"), 'npm.pkg.github.com'))->toBe(1);

    $account->delete();
    $run();

    expect(file_get_contents("{$root}/.npmrc"))->toBe("save-exact=true\n")
        ->and(json_decode(file_get_contents("{$root}/.docker/config.json"), true))->toBe(['auths' => ['registry.example.com' => []], 'credHelpers' => []]);
})->group('GIT-016');

test('Docker\'s credential helper signs in to ghcr.io with GITHUB_TOKEN, and has nothing without one', function () {
    $helper = base_path('docker/sandbox/lazy/docker-credential-onedrop');

    $get = function (string $env) use ($helper): array {
        exec("echo ghcr.io | env -i PATH=/usr/bin:/bin:/opt/homebrew/bin {$env} {$helper} get", $output, $status);

        return [implode("\n", $output), $status];
    };

    [$output, $status] = $get('GITHUB_TOKEN=gho_ada');
    expect($status)->toBe(0)
        ->and(json_decode($output, true))->toBe(['ServerURL' => 'ghcr.io', 'Username' => 'x-access-token', 'Secret' => 'gho_ada']);

    [$output, $status] = $get('');
    expect($status)->toBe(1)->and($output)->toBe('credentials not found in native keychain');
})->group('GIT-016');
