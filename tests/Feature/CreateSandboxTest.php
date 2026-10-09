<?php

use App\Enums\SandboxStatus;
use App\Jobs\CreateSandbox;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\User;
use App\Sandbox\ExecResult;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxProvider;
use App\Sandbox\SandboxSpec;

test('the sandbox gets the owner\'s default AI credential and project name', function () {
    $user = User::factory()->has(AgentConnection::factory()->state(['credential' => 'sk-ant-api03-secret']))->create();
    $project = Project::factory()->for($user)->create(['name' => 'Timesheets']);

    CreateSandbox::dispatchSync($project);

    /** @var FakeSandboxProvider $provider */
    $provider = app(SandboxProvider::class);
    $spec = collect($provider->created)->sole();

    expect($spec->env)->toMatchArray([
        'ANTHROPIC_API_KEY' => 'sk-ant-api03-secret',
        'APP_PROJECT_NAME' => 'Timesheets',
    ])
        ->and($spec->port)->toBe(8000)
        ->and($spec->name)->toStartWith("onedrop-project-{$project->id}-")
        ->and($project->sandbox->status)->toBe(SandboxStatus::Running);
})->group('SBX-001');

test('the sandbox records its preview and shell urls', function () {
    app()->instance(SandboxProvider::class, new class extends FakeSandboxProvider
    {
        public function previewUrl(string $id, int $port): ?string
        {
            return "http://127.0.0.1:1{$port}";
        }
    });

    $project = Project::factory()->create();

    CreateSandbox::dispatchSync($project);

    // The preview goes through the host-rewriting proxy (8081), not straight to the app (8000).
    expect($project->sandbox->preview_url)->toBe('http://127.0.0.1:18081')
        ->and($project->sandbox->shell_url)->toBe('http://127.0.0.1:17681');
})->group('TAB-001');

test('a provider failure marks the sandbox failed with a readable error', function () {
    app()->instance(SandboxProvider::class, new class extends FakeSandboxProvider
    {
        public function create(SandboxSpec $spec): string
        {
            throw new SandboxException('Docker is not running. Start Docker and try again.');
        }
    });

    $project = Project::factory()->create();

    CreateSandbox::dispatchSync($project);

    expect($project->sandbox->status)->toBe(SandboxStatus::Failed)
        ->and($project->sandbox->error)->toBe('Docker is not running. Start Docker and try again.');
})->group('SBX-001');

test('an unimplemented provider fails loudly', function () {
    config(['sandbox.provider' => 'daytona']);
    app()->forgetInstance(SandboxProvider::class);

    app(SandboxProvider::class);
})->throws(InvalidArgumentException::class, "Sandbox provider [daytona] isn't implemented yet.")->group('SBX-001');

test('exec results report success', function () {
    expect((new ExecResult(0, 'ok'))->successful())->toBeTrue()
        ->and((new ExecResult(1, ''))->successful())->toBeFalse();
})->group('SBX-001');

test('every sandbox for a project shares its storage key, so buckets outlive the container', function () {
    $project = Project::factory()->create();

    CreateSandbox::dispatchSync($project);
    CreateSandbox::dispatchSync($project);

    /** @var FakeSandboxProvider $provider */
    $provider = app(SandboxProvider::class);
    $specs = collect($provider->created)->values();

    expect($specs)->toHaveCount(2)
        ->and($specs->pluck('storageKey')->unique()->all())->toBe(["project-{$project->id}"])
        ->and($specs[0]->name)->not->toBe($specs[1]->name);
})->group('STORE-001');
