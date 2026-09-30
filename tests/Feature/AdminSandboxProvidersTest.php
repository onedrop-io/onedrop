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
});

test('admins see every provider, which is active and how many sandboxes each holds', function () {
    Sandbox::factory()->count(2)->create(['provider' => 'docker']);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.sandboxes.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/sandboxes')
            ->has('providers', 3)
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

test('admins can make a turned-on provider with its settings the active one', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->put(route('admin.sandboxes.update', 'blaxel'), ['enabled' => true, 'api_key' => 'key', 'workspace' => 'acme']);

    $this->actingAs($admin)->post(route('admin.sandboxes.activate', 'blaxel'))
        ->assertRedirect(route('admin.sandboxes.index'));

    expect(config('sandbox.provider'))->toBe('blaxel');

    $provider = app(SandboxProvider::class);
    expect($provider)->toBeInstanceOf(RoutingSandboxProvider::class)
        ->and($provider->provider('blaxel'))->toBeInstanceOf(BlaxelSandboxProvider::class);

    config(['sandbox.provider' => 'docker']);
    SystemSetting::flush();
    SystemConfig::apply();
    expect(config('sandbox.provider'))->toBe('blaxel');
})->group('ADMIN-002');

test('a provider cannot be made active while it is off or missing settings', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post(route('admin.sandboxes.activate', 'runtime'))->assertSessionHasErrors('provider');

    $this->actingAs($admin)->put(route('admin.sandboxes.update', 'runtime'), ['enabled' => true]);
    $this->actingAs($admin)->post(route('admin.sandboxes.activate', 'runtime'))->assertSessionHasErrors('provider');

    expect(config('sandbox.provider'))->toBe('docker');
})->group('ADMIN-002');

test('the active provider cannot be turned off', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.sandboxes.update', 'docker'), ['enabled' => false])
        ->assertSessionHasErrors('enabled');

    expect(app(SandboxProviders::class)->enabled())->toContain('docker');
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
    $this->actingAs($user)->post(route('admin.sandboxes.activate', 'docker'))->assertForbidden();
})->group('ADMIN-002');
