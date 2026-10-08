<?php

use App\Enums\OrganizationRole;
use App\Enums\ProjectKind;
use App\Enums\SandboxStatus;
use App\Jobs\CreateSandbox;
use App\Jobs\SyncOrganizationSecrets;
use App\Models\AgentConnection;
use App\Models\Organization;
use App\Models\OrganizationSecret;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Agents\OpenCodeRunner;
use App\Sandbox\ExecResult;
use App\Sandbox\OrganizationSecrets;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['app.multi_tenant' => true]);
    $this->provider = new FakeSandboxProvider;
    app()->instance(SandboxProvider::class, $this->provider);

    $this->organization = Organization::factory()->create(['slug' => 'acme']);
    $this->owner = User::factory()->has(AgentConnection::factory())->create();
    $this->organization->addMember($this->owner, OrganizationRole::Owner);
    $this->project = ownProject($this, ['name' => 'Billing']);
});

/**
 * A project of the owner's in the organization.
 *
 * @param  array<string, mixed>  $attributes
 */
function ownProject(mixed $test, array $attributes = []): Project
{
    return Project::factory()->for($test->owner)->for($test->organization)->create($attributes);
}

/**
 * The file write the platform ran in a sandbox, if any.
 *
 * @return array{id: string, command: list<string>, env: array<string, string>, detach: bool, root: bool}|null
 */
function secretsWrite(FakeSandboxProvider $provider): ?array
{
    return collect($provider->executed)->first(fn (array $call) => isset($call['env']['ONEDROP_ORG_SECRETS']));
}

test('an owner adds a secret for all projects; its value is kept encrypted and never sent back', function () {
    Queue::fake();

    $this->actingAs($this->owner)
        ->post(route('organizations.secrets.store', $this->organization), [
            'name' => 'AWS_SECRET_ACCESS_KEY',
            'value' => 'wJalrXUtnFEMI/K7MDENG',
            'all_projects' => true,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('organizations.edit', $this->organization));

    $secret = $this->organization->secrets()->sole();

    expect($secret->value)->toBe('wJalrXUtnFEMI/K7MDENG')
        ->and(DB::table('organization_secrets')->value('value'))->not->toContain('wJalrXUtnFEMI');
    Queue::assertPushed(SyncOrganizationSecrets::class, fn (SyncOrganizationSecrets $job) => $job->organization->is($this->organization) && $job->sandbox === null);

    $this->get(route('organizations.edit', $this->organization))
        ->assertDontSee('wJalrXUtnFEMI')
        ->assertInertia(fn (Assert $page) => $page
            ->where('secrets.items.0.name', 'AWS_SECRET_ACCESS_KEY')
            ->where('secrets.items.0.all_projects', true)
            ->where('secrets.projects.0.name', 'Billing'));
})->group('SECRET-003');

test('a secret can go to selected projects only, and its value is kept when left empty', function () {
    Queue::fake();
    $other = ownProject($this);
    $secret = OrganizationSecret::factory()->for($this->organization)->create(['name' => 'AWS_REGION', 'value' => 'us-east-1']);

    $this->actingAs($this->owner)
        ->put(route('organizations.secrets.update', [$this->organization, $secret]), [
            'value' => '',
            'all_projects' => false,
            'projects' => [$this->project->id],
        ])
        ->assertSessionHasNoErrors();

    $secret->refresh();
    expect($secret->value)->toBe('us-east-1')
        ->and($secret->all_projects)->toBeFalse()
        ->and(app(OrganizationSecrets::class)->for($this->project))->toBe(['AWS_REGION' => 'us-east-1'])
        ->and(app(OrganizationSecrets::class)->for($other))->toBe([]);

    $this->put(route('organizations.secrets.update', [$this->organization, $secret]), ['value' => 'eu-west-1', 'all_projects' => true])
        ->assertSessionHasNoErrors();

    expect($secret->refresh()->value)->toBe('eu-west-1')
        ->and($secret->projects()->count())->toBe(0)
        ->and(app(OrganizationSecrets::class)->for($other))->toBe(['AWS_REGION' => 'eu-west-1']);
    Queue::assertPushed(SyncOrganizationSecrets::class, 2);
})->group('SECRET-003');

test('names, values and projects are checked', function (array $input, string $error) {
    $computer = ownProject($this, ['kind' => ProjectKind::Computer]);
    $elsewhere = Project::factory()->create();
    OrganizationSecret::factory()->for($this->organization)->create(['name' => 'TAKEN']);

    $input = array_map(fn ($value) => match ($value) {
        'computer' => [$computer->id],
        'elsewhere' => [$elsewhere->id],
        default => $value,
    }, $input);

    $this->actingAs($this->owner)
        ->post(route('organizations.secrets.store', $this->organization), [...['name' => 'API_KEY', 'value' => 'x', 'all_projects' => true], ...$input])
        ->assertSessionHasErrors($error);
})->with([
    'a name starting with a digit' => [['name' => '1KEY'], 'name'],
    'a name with a dash' => [['name' => 'API-KEY'], 'name'],
    'the platform\'s own prefix' => [['name' => 'ONEDROP_TOKEN'], 'name'],
    'a name already used' => [['name' => 'TAKEN'], 'name'],
    'no value' => [['value' => ''], 'value'],
    'selected projects, none chosen' => [['all_projects' => false], 'projects'],
    'a person\'s computer' => [['all_projects' => false, 'projects' => 'computer'], 'projects.0'],
    'another organization\'s project' => [['all_projects' => false, 'projects' => 'elsewhere'], 'projects.0'],
])->group('SECRET-003');

test('all of an organization\'s secrets together must fit in one command\'s environment', function () {
    OrganizationSecret::factory()->for($this->organization)->count(4)->create(['value' => str_repeat('a', 20000)]);

    $this->actingAs($this->owner)
        ->post(route('organizations.secrets.store', $this->organization), ['name' => 'ONE_MORE', 'value' => str_repeat('b', 20000), 'all_projects' => true])
        ->assertSessionHasErrors('value');
})->group('SECRET-003');

test('members can\'t see or change the organization\'s secrets', function () {
    $member = User::factory()->has(AgentConnection::factory())->create();
    $this->organization->addMember($member, OrganizationRole::Member);
    $secret = OrganizationSecret::factory()->for($this->organization)->create();

    $this->actingAs($member)->get(route('organizations.edit', $this->organization))
        ->assertInertia(fn (Assert $page) => $page->where('secrets', null));
    $this->post(route('organizations.secrets.store', $this->organization), ['name' => 'X', 'value' => 'y', 'all_projects' => true])->assertForbidden();
    $this->put(route('organizations.secrets.update', [$this->organization, $secret]), ['all_projects' => true])->assertForbidden();
    $this->delete(route('organizations.secrets.destroy', [$this->organization, $secret]))->assertForbidden();

    expect($secret->fresh())->not->toBeNull();
})->group('SECRET-003');

test('an owner deletes a secret, but not another organization\'s', function () {
    Queue::fake();
    $secret = OrganizationSecret::factory()->for($this->organization)->create();
    $theirs = OrganizationSecret::factory()->create();

    $this->actingAs($this->owner)->delete(route('organizations.secrets.destroy', [$this->organization, $theirs]))->assertNotFound();
    $this->delete(route('organizations.secrets.destroy', [$this->organization, $secret]))->assertRedirect();

    expect($secret->fresh())->toBeNull()->and($theirs->fresh())->not->toBeNull();
    Queue::assertPushed(SyncOrganizationSecrets::class, 1);
})->group('SECRET-003');

test('a sandbox gets the secrets that reach its project as a file, and its app restarts when they changed', function () {
    $sandbox = Sandbox::factory()->for($this->project)->create(['external_id' => 'ctr-1']);
    OrganizationSecret::factory()->for($this->organization)->create(['name' => 'AWS_ACCESS_KEY_ID', 'value' => 'AKIA123']);
    OrganizationSecret::factory()->for($this->organization)->create(['name' => 'PEM', 'value' => "line one\nline 'two'"]);
    OrganizationSecret::factory()->for($this->organization)->selected()->create(['name' => 'OTHER_PROJECT_ONLY']);
    $this->provider->execUsing = fn (array $command) => new ExecResult(0, $command[0] === 'bash' ? "changed\n" : '', '');

    app(OrganizationSecrets::class)->sync($sandbox);

    $write = secretsWrite($this->provider);
    expect($write['id'])->toBe('ctr-1')
        ->and($write['command'])->toContain(OrganizationSecrets::FILE)
        ->and($write['root'])->toBeFalse()
        ->and($write['env']['ONEDROP_ORG_SECRETS'])->toBe('AWS_ACCESS_KEY_ID '.base64_encode('AKIA123')."\nPEM ".base64_encode("line one\nline 'two'")."\n")
        ->and(collect($this->provider->executed)->pluck('command')->last())->toBe(['/opt/onedrop/restart']);

    // Unchanged since: the sandbox isn't asked again.
    $this->provider->executed = [];
    app(OrganizationSecrets::class)->sync($sandbox);
    expect($this->provider->executed)->toBe([]);
})->group('SECRET-003');

test('a file that didn\'t change leaves the app running, and a sandbox never given any isn\'t asked', function () {
    $sandbox = Sandbox::factory()->for($this->project)->create(['external_id' => 'ctr-1']);

    app(OrganizationSecrets::class)->sync($sandbox);
    expect($this->provider->executed)->toBe([]);

    OrganizationSecret::factory()->for($this->organization)->create();
    app(OrganizationSecrets::class)->sync($sandbox);

    expect(secretsWrite($this->provider))->not->toBeNull()
        ->and(collect($this->provider->executed)->pluck('command'))->not->toContain(['/opt/onedrop/restart']);
})->group('SECRET-003');

test('a sandbox that doesn\'t have what reads the file yet gets it with the file', function () {
    $sandbox = Sandbox::factory()->for($this->project)->create(['external_id' => 'ctr-1']);
    OrganizationSecret::factory()->for($this->organization)->create();

    app(OrganizationSecrets::class)->sync($sandbox);

    expect(collect($this->provider->installed)->sole()['files'])->toBe(['bashrc', 'org-secrets'])
        ->and(secretsWrite($this->provider))->not->toBeNull();
})->group('SECRET-003');

test('a person\'s computer never gets the organization\'s secrets', function () {
    $computer = ownProject($this, ['kind' => ProjectKind::Computer]);
    $sandbox = Sandbox::factory()->for($computer)->create(['external_id' => 'ctr-2']);
    OrganizationSecret::factory()->for($this->organization)->create();

    app(OrganizationSecrets::class)->sync($sandbox);

    expect($this->provider->executed)->toBe([]);
})->group('SECRET-003');

test('a change reaches the organization\'s awake sandboxes, task copies too, but doesn\'t wake sleeping ones', function () {
    $awake = Sandbox::factory()->for($this->project)->create(['external_id' => 'ctr-awake']);
    $asleep = Sandbox::factory()->for(ownProject($this))->create(['external_id' => 'ctr-asleep', 'suspended_at' => now()]);
    $elsewhere = Sandbox::factory()->create(['external_id' => 'ctr-elsewhere']);
    OrganizationSecret::factory()->for($this->organization)->create();

    SyncOrganizationSecrets::dispatchSync($this->organization);

    expect(collect($this->provider->executed)->filter(fn ($call) => isset($call['env']['ONEDROP_ORG_SECRETS']))->pluck('id')->all())->toBe(['ctr-awake']);
})->group('SECRET-003');

test('a new sandbox gets the secrets once it\'s made', function () {
    OrganizationSecret::factory()->for($this->organization)->create(['name' => 'AWS_REGION', 'value' => 'us-east-1']);

    CreateSandbox::dispatchSync($this->project);

    $write = secretsWrite($this->provider);
    expect($write['id'])->toBe($this->project->sandbox->external_id)
        ->and($write['env']['ONEDROP_ORG_SECRETS'])->toBe('AWS_REGION '.base64_encode('us-east-1')."\n");
})->group('SECRET-003');

test('every agent run brings them up to date before the agent starts', function () {
    Sandbox::factory()->for($this->project)->create(['external_id' => 'ctr-1']);
    OrganizationSecret::factory()->for($this->organization)->create(['name' => 'AWS_REGION']);
    $message = $this->project->messages()->create(['role' => 'user', 'content' => 'list my buckets']);

    app(OpenCodeRunner::class)->start($this->project, $message);

    $commands = collect($this->provider->executed)->pluck('command');
    $write = $commands->search(fn ($command) => in_array(OrganizationSecrets::FILE, $command, true));

    expect($write)->not->toBeFalse()
        ->and($write)->toBeLessThan($commands->search(['node', '/opt/onedrop/forwarder.mjs']));
})->group('SECRET-003');

test('a sandbox that wakes up brings them up to date', function () {
    Queue::fake();
    $sandbox = Sandbox::factory()->for($this->project)->create(['external_id' => 'ctr-1', 'status' => SandboxStatus::Running, 'suspended_at' => now()]);

    $sandbox->wake($this->provider);

    Queue::assertPushed(SyncOrganizationSecrets::class, fn (SyncOrganizationSecrets $job) => $job->sandbox?->is($sandbox));
})->group('SECRET-003');

test('Tools → Secrets lists the names of the organization\'s secrets that reach the project', function () {
    $workspace = sys_get_temp_dir().'/onedrop-org-secrets-'.bin2hex(random_bytes(4));
    mkdir($workspace);
    file_put_contents($workspace.'/.env', "AWS_REGION=local\n");
    register_shutdown_function(fn () => exec('rm -rf '.escapeshellarg($workspace)));
    app()->instance(SandboxProvider::class, fakeDatabaseSandbox($workspace));
    Sandbox::factory()->for($this->project)->create(['external_id' => 'ctr-1']);
    OrganizationSecret::factory()->for($this->organization)->create(['name' => 'AWS_REGION', 'value' => 'us-east-1']);
    OrganizationSecret::factory()->for($this->organization)->create(['name' => 'AWS_ACCESS_KEY_ID']);
    OrganizationSecret::factory()->for($this->organization)->selected()->create(['name' => 'NOT_HERE']);

    $this->actingAs($this->owner)
        ->getJson(route('projects.secrets.index', $this->project))
        ->assertOk()
        ->assertExactJson(['secrets' => ['AWS_REGION'], 'organization' => ['AWS_ACCESS_KEY_ID', 'AWS_REGION']])
        ->assertDontSee('us-east-1');
})->group('SECRET-003');

test('the Shell, the app and the agent read the file, with the platform\'s settings and the project\'s .env winning', function () {
    $helper = file_get_contents(base_path('docker/sandbox/org-secrets'));

    expect($helper)->toContain(OrganizationSecrets::FILE)
        ->and(file_get_contents(base_path('docker/sandbox/bashrc')))->toContain('. /opt/onedrop/org-secrets')
        ->and(file_get_contents(base_path('docker/sandbox/start.sh')))->toContain('. /opt/onedrop/org-secrets')
        ->and(file_get_contents(base_path('docker/sandbox/forwarder.mjs')))->toContain(OrganizationSecrets::FILE);

    $root = sys_get_temp_dir().'/onedrop-org-env-'.bin2hex(random_bytes(4));
    mkdir($root);
    register_shutdown_function(fn () => exec('rm -rf '.escapeshellarg($root)));
    file_put_contents("{$root}/.env", "AWS_ACCESS_KEY_ID=local\n");
    file_put_contents("{$root}/secrets", OrganizationSecrets::contents([
        'AWS_ACCESS_KEY_ID' => 'AKIA-real',
        'AWS_SECRET_ACCESS_KEY' => "two\nlines 'q' \$HOME",
        'HOME' => '/nope',
    ]));
    file_put_contents("{$root}/helper", str_replace([OrganizationSecrets::FILE, '/workspace/.env'], ["{$root}/secrets", "{$root}/.env"], $helper));

    $output = shell_exec('env -i HOME=/home/sandbox PATH=/usr/bin:/bin bash -c '.escapeshellarg(". {$root}/helper; printf '%s|%s|%s' \"\${AWS_ACCESS_KEY_ID-unset}\" \"\$AWS_SECRET_ACCESS_KEY\" \"\$HOME\""));

    expect($output)->toBe("unset|two\nlines 'q' \$HOME|/home/sandbox");
})->group('SECRET-003');
