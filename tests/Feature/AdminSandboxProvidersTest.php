<?php

use App\Models\Sandbox;
use App\Models\SystemSetting;
use App\Models\User;
use App\Sandbox\Providers\BlaxelSandboxProvider;
use App\Sandbox\Providers\RoutingSandboxProvider;
use App\Sandbox\SandboxProvider;
use App\Sandbox\SandboxProviders;
use App\Sandbox\SystemConfig;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    // The test suite runs on the fake provider, which the settings never replace; these tests start from Docker.
    config([
        'sandbox.provider' => 'docker',
        'sandbox.providers.blaxel.api_key' => null,
        'sandbox.providers.blaxel.workspace' => null,
        'sandbox.providers.runtime.api_key' => null,
    ]);
    fakeSandboxImages();
});

test('admins see every provider, which is active and how many sandboxes each holds', function () {
    Sandbox::factory()->count(2)->create(['provider' => 'docker']);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.sandboxes.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/sandboxes')
            ->has('providers', 4)
            ->where('providers.0.name', 'docker')
            ->where('providers.0.active', true)
            ->where('providers.0.enabled', true)
            ->where('providers.0.sandboxes', 2)
            ->where('providers.1.name', 'blaxel')
            ->where('providers.1.enabled', false)
            ->where('providers.1.missing', ['api_key', 'workspace']));
})->group('ADMIN-002');

test('admins can configure a provider; keys are stored encrypted, never shown, and kept when left blank', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->put(route('admin.sandboxes.update', 'blaxel'), [
        'enabled' => true,
        'api_key' => 'bl-secret-key',
        'workspace' => 'acme',
        'memory_mib' => 2048,
        'region' => '',
    ])->assertRedirect(route('admin.sandboxes.index'));

    expect(config('sandbox.providers.blaxel.api_key'))->toBe('bl-secret-key')
        ->and(config('sandbox.providers.blaxel.workspace'))->toBe('acme')
        ->and(config('sandbox.providers.blaxel.memory_mib'))->toBe(2048)
        ->and(SystemSetting::query()->toBase()->where('key', 'sandboxes')->value('value'))->not->toContain('bl-secret-key');

    $this->actingAs($admin)->put(route('admin.sandboxes.update', 'blaxel'), ['enabled' => true, 'api_key' => '', 'workspace' => 'acme-2']);

    expect(config('sandbox.providers.blaxel.api_key'))->toBe('bl-secret-key')
        ->and(config('sandbox.providers.blaxel.workspace'))->toBe('acme-2');

    $this->actingAs($admin)->get(route('admin.sandboxes.index'))
        ->assertDontSee('bl-secret-key')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('providers.1.enabled', true)
            ->where('providers.1.missing', [])
            ->where('providers.1.fields.0.key', 'api_key')
            ->where('providers.1.fields.0.value', null)
            ->where('providers.1.fields.0.set', true));
})->group('ADMIN-002');

test('saved settings win over .env in every new process, and running queue workers are told to restart', function () {
    Artisan::spy();

    $this->actingAs(User::factory()->admin()->create())->put(route('admin.sandboxes.update', 'docker'), [
        'enabled' => true,
        'memory' => '3g',
        'idle_seconds' => 120,
    ]);

    Artisan::shouldHaveReceived('call')->with('queue:restart');

    // A fresh process (a worker, the next request) starts from .env, then applies the settings.
    config(['sandbox.providers.docker.memory' => '2g', 'sandbox.providers.docker.idle_seconds' => 60]);
    SystemSetting::flush();
    SystemConfig::apply();

    expect(config('sandbox.providers.docker.memory'))->toBe('3g')
        ->and(config('sandbox.providers.docker.idle_seconds'))->toBe(120);
})->group('ADMIN-002');

test('new projects run on the first provider in the admin\'s order that is on and set up', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->put(route('admin.sandboxes.update', 'blaxel'), ['enabled' => true, 'api_key' => 'key', 'workspace' => 'acme']);

    $this->actingAs($admin)->put(route('admin.sandboxes.reorder'), ['providers' => ['blaxel', 'docker', 'runtime', 'e2b']])
        ->assertRedirect(route('admin.sandboxes.index'))
        ->assertInertiaFlash('toast.message', 'New projects now run on Blaxel.');

    expect(config('sandbox.provider'))->toBe('blaxel');

    $provider = app(SandboxProvider::class);
    expect($provider)->toBeInstanceOf(RoutingSandboxProvider::class)
        ->and($provider->provider('blaxel'))->toBeInstanceOf(BlaxelSandboxProvider::class);

    $this->actingAs($admin)->get(route('admin.sandboxes.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('providers.0.name', 'blaxel')
            ->where('providers.0.active', true)
            ->where('providers.1.name', 'docker')
            ->where('providers.1.active', false));

    // A fresh process (a worker, the next request) starts from .env, then applies the settings.
    config(['sandbox.provider' => 'docker']);
    SystemSetting::flush();
    SystemConfig::apply();
    expect(config('sandbox.provider'))->toBe('blaxel');
})->group('ADMIN-002');

test('providers that are off or missing settings are skipped', function () {
    $admin = User::factory()->admin()->create();

    // Runtime is first but off, then on without its key: Docker keeps new projects.
    $this->actingAs($admin)->put(route('admin.sandboxes.reorder'), ['providers' => ['runtime', 'blaxel', 'docker', 'e2b']]);
    expect(config('sandbox.provider'))->toBe('docker');

    $this->actingAs($admin)->put(route('admin.sandboxes.update', 'runtime'), ['enabled' => true]);
    expect(config('sandbox.provider'))->toBe('docker');

    $this->actingAs($admin)->put(route('admin.sandboxes.update', 'runtime'), ['enabled' => true, 'api_key' => 'rt-key'])
        ->assertInertiaFlash('toast.message', 'New projects now run on Runtime Cloud.');
    expect(config('sandbox.provider'))->toBe('runtime');

    // Turning the first one off hands new projects to the next.
    $this->actingAs($admin)->put(route('admin.sandboxes.update', 'runtime'), ['enabled' => false]);
    expect(config('sandbox.provider'))->toBe('docker');
})->group('ADMIN-002');

test('the order must list every provider once', function (array $order) {
    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.sandboxes.reorder'), ['providers' => $order])
        ->assertSessionHasErrors();

    expect(app(SandboxProviders::class)->order())->toBe(['docker', 'blaxel', 'runtime', 'e2b']);
})->with([
    'missing one' => [['blaxel', 'docker']],
    'twice' => [['docker', 'docker', 'blaxel']],
    'unknown' => [['docker', 'blaxel', 'e2b']],
])->group('ADMIN-002');

test('installs that chose an active provider before there was an order keep it first', function () {
    SystemSetting::put('sandboxes', ['active' => 'runtime', 'enabled' => ['docker', 'runtime'], 'providers' => ['runtime' => ['api_key' => 'rt-key']]]);
    SystemConfig::apply();

    expect(config('sandbox.provider'))->toBe('runtime')
        ->and(app(SandboxProviders::class)->order())->toBe(['runtime', 'docker', 'blaxel', 'e2b']);
})->group('ADMIN-002');

test('the last provider that is on and set up cannot be turned off', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->put(route('admin.sandboxes.update', 'docker'), ['enabled' => false])
        ->assertSessionHasErrors('enabled');

    expect(app(SandboxProviders::class)->enabled())->toContain('docker');

    // A provider that's on but missing its key doesn't count.
    $this->actingAs($admin)->put(route('admin.sandboxes.update', 'runtime'), ['enabled' => true]);
    $this->actingAs($admin)
        ->put(route('admin.sandboxes.update', 'docker'), ['enabled' => false])
        ->assertSessionHasErrors('enabled');
})->group('ADMIN-002');

test('the test suite\'s fake provider is never replaced by saved settings', function () {
    SystemSetting::put('sandboxes', ['active' => 'docker']);
    config(['sandbox.provider' => 'fake']);

    SystemConfig::apply();

    expect(config('sandbox.provider'))->toBe('fake');
})->group('ADMIN-002');

test('non-admins cannot see or change sandbox providers', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('admin.sandboxes.index'))->assertForbidden();
    $this->actingAs($user)->put(route('admin.sandboxes.update', 'docker'), ['enabled' => true])->assertForbidden();
    $this->actingAs($user)->put(route('admin.sandboxes.reorder'), ['providers' => ['blaxel', 'docker', 'runtime', 'e2b']])->assertForbidden();
})->group('ADMIN-002');

test('admins can turn on Docker inside Docker sandboxes', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.sandboxes.update', 'docker'), ['enabled' => true, 'nested_docker' => 'privileged'])
        ->assertSessionHasNoErrors();

    expect(config('sandbox.providers.docker.nested_docker'))->toBe('privileged');

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.sandboxes.update', 'docker'), ['enabled' => true, 'nested_docker' => 'yes-please'])
        ->assertSessionHasErrors('nested_docker');
})->group('ADMIN-002', 'SBX-008');

test('admins can limit task copies per project, or clear the limit; it wins over .env', function () {
    config(['sandbox.max_task_copies' => 3]);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->put(route('admin.sandboxes.task-copies'), ['max_task_copies' => 5])->assertRedirect(route('admin.sandboxes.index'));
    config(['sandbox.max_task_copies' => 3]);
    SystemConfig::apply();
    expect(config('sandbox.max_task_copies'))->toBe(5);

    $this->actingAs($admin)->put(route('admin.sandboxes.task-copies'), ['max_task_copies' => ''])->assertRedirect();
    config(['sandbox.max_task_copies' => 3]);
    SystemConfig::apply();
    expect(config('sandbox.max_task_copies'))->toBeNull();

    $this->actingAs($admin)->get(route('admin.sandboxes.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('maxTaskCopies', null));
    $this->actingAs($admin)->put(route('admin.sandboxes.task-copies'), ['max_task_copies' => 0])->assertSessionHasErrors('max_task_copies');
    $this->actingAs(User::factory()->create())->put(route('admin.sandboxes.task-copies'), ['max_task_copies' => 1])->assertForbidden();
})->group('TASK-003', 'ADMIN-002');

test('a queue job uses the settings saved since its worker started, such as a bigger Runtime disk', function () {
    config(['queue.default' => 'sync', 'sandbox.providers.runtime.disk_mib' => 20480]);

    // Saved by the web app; this "worker" read its settings before.
    SystemSetting::put(SandboxProviders::SETTING, ['providers' => ['runtime' => ['disk_mib' => 40960]]]);

    dispatch(fn () => cache()->put('disk-seen-by-job', config('sandbox.providers.runtime.disk_mib')));

    expect(cache('disk-seen-by-job'))->toBe(40960);
})->group('ADMIN-002');
