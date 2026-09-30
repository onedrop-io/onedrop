<?php

use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->provider = new class extends FakeSandboxProvider
    {
        public int $asked = 0;

        public function previewUrl(string $id, int $port): ?string
        {
            $this->asked++;

            return "https://{$port}-{$id}.runtimehost.com/?runtime_preview_token=fresh";
        }
    };
    app()->instance(SandboxProvider::class, $this->provider);

    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->project = Project::factory()->for($this->user)->create();
    $this->sandbox = Sandbox::factory()->for($this->project)->create([
        'provider' => 'runtime',
        'external_id' => 'sbx-1',
        'preview_url' => 'https://8081-sbx-1.runtimehost.com/?runtime_preview_token=old',
    ]);
});

test('opening a runtime project renews its preview and shell links once a day', function () {
    config(['sandbox.provider' => 'runtime']);

    $this->actingAs($this->user)->get(route('projects.show', $this->project))->assertOk()
        ->assertInertia(fn ($page) => $page->where('sandbox.preview_url', 'https://8081-sbx-1.runtimehost.com/?runtime_preview_token=fresh'));

    expect($this->sandbox->fresh()->shell_url)->toBe('https://7681-sbx-1.runtimehost.com/?runtime_preview_token=fresh');

    $asked = $this->provider->asked;
    $this->actingAs($this->user)->get(route('projects.show', $this->project))->assertOk();

    expect($this->provider->asked)->toBe($asked);
})->group('SBX-003');

test('docker projects keep their published addresses', function () {
    config(['sandbox.provider' => 'docker']);
    $this->sandbox->update(['provider' => 'docker']);

    $this->actingAs($this->user)->get(route('projects.show', $this->project))->assertOk();

    expect($this->provider->asked)->toBe(0);
})->group('SBX-003');

test('the ssh panel explains that runtime sandboxes have no ssh address', function () {
    $this->actingAs($this->user)->getJson(route('projects.developer.ssh', $this->project))
        ->assertOk()
        ->assertJsonPath('unavailable', "SSH isn't available on this server yet. Use the Shell tab to run commands in the sandbox.");
})->group('SBX-003');

test('the image is built on runtime with the key in the environment, not the command line', function () {
    config(['sandbox.provider' => 'runtime', 'sandbox.providers.runtime.api_key' => 'rt-secret']);
    Process::fake(['*' => Process::result('ready')]);

    $this->artisan('sandbox:build-image')->assertSuccessful();

    Process::assertRan(fn ($process) => in_array('withruntime@0.8', $process->command, true)
        && in_array('onedrop-sandbox:latest', $process->command, true)
        && ! str_contains(implode(' ', $process->command), 'rt-secret')
        && $process->environment['RUNTIME_API_KEY'] === 'rt-secret');
})->group('SBX-003');

test('building on runtime without a key says what to set', function () {
    config(['sandbox.provider' => 'runtime', 'sandbox.providers.runtime.api_key' => null]);
    Process::fake();

    $this->artisan('sandbox:build-image')->assertFailed()->expectsOutputToContain('RUNTIME_API_KEY');

    Process::assertNothingRan();
})->group('SBX-003');

test('opening a blaxel project renews its private preview links too', function () {
    config(['sandbox.provider' => 'blaxel']);
    $this->sandbox->update(['provider' => 'blaxel']);

    $this->actingAs($this->user)->get(route('projects.show', $this->project))->assertOk();

    expect($this->sandbox->fresh()->preview_url)->toBe('https://8081-sbx-1.runtimehost.com/?runtime_preview_token=fresh');
})->group('SBX-004');

test('the ssh panel explains that blaxel sandboxes have no ssh address', function () {
    $this->sandbox->update(['provider' => 'blaxel']);

    $this->actingAs($this->user)->getJson(route('projects.developer.ssh', $this->project))
        ->assertOk()
        ->assertJsonPath('unavailable', "SSH isn't available on this server yet. Use the Shell tab to run commands in the sandbox.");
})->group('SBX-004');

test('the blaxel image is docker/sandbox plus the blaxel steps, pushed with the key in the environment', function () {
    config(['sandbox.provider' => 'blaxel', 'sandbox.providers.blaxel.api_key' => 'bl-secret', 'sandbox.providers.blaxel.workspace' => 'onedrop']);
    $pushed = null;

    Process::fake(function (PendingProcess $process) use (&$pushed) {
        if ($process->command[0] === 'cp') {
            File::copyDirectory($process->command[2], $process->command[3]);
        } else {
            $pushed = [
                'dockerfile' => File::get($process->path.'/Dockerfile'),
                'toml' => File::get($process->path.'/blaxel.toml'),
            ];
        }

        return Process::result();
    });

    $this->artisan('sandbox:build-image')->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->command[0] === 'bl'
        && $process->command[1] === 'push'
        && in_array('onedrop-sandbox', $process->command, true)
        && ! str_contains(implode(' ', $process->command), 'bl-secret')
        && $process->environment === ['BL_API_KEY' => 'bl-secret', 'BL_WORKSPACE' => 'onedrop']);

    expect($pushed['dockerfile'])->toStartWith(File::get(base_path('docker/sandbox/Dockerfile')))
        ->toContain('/usr/local/bin/sandbox-api')
        ->toEndWith(File::get(base_path('docker/sandbox/blaxel/Dockerfile.append')))
        ->and($pushed['toml'])->toContain('name = "onedrop-sandbox"')->toContain('type = "sandbox"')
        ->and(glob(storage_path('framework/blaxel-image-*')))->toBe([]);
})->group('SBX-004');

test('building on blaxel without credentials says what to set', function () {
    config(['sandbox.provider' => 'blaxel', 'sandbox.providers.blaxel.api_key' => null]);
    Process::fake();

    $this->artisan('sandbox:build-image')->assertFailed()->expectsOutputToContain('BL_API_KEY');

    Process::assertNothingRan();
})->group('SBX-004');
