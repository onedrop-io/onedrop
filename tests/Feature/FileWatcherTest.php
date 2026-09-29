<?php

use App\Jobs\CreateSandbox;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use Illuminate\Support\Facades\URL;

/**
 * The address a sandbox's file watcher reports to, without the callback host.
 */
function filesChangedPath(Sandbox $sandbox): string
{
    return URL::signedRoute('sandbox-events.files', $sandbox, absolute: false);
}

test('new sandboxes get a signed address for their file watcher', function () {
    config(['sandbox.callback_url' => 'http://host.docker.internal:8000/']);
    $project = Project::factory()->create();

    CreateSandbox::dispatchSync($project);

    /** @var FakeSandboxProvider $provider */
    $provider = app(SandboxProvider::class);
    $url = collect($provider->created)->sole()->env['APP_FILES_CHANGED_URL'];

    expect($url)->toBe('http://host.docker.internal:8000'.filesChangedPath($project->sandbox));

    $this->postJson(str($url)->after('8000')->toString())->assertOk()->assertExactJson(['version' => 1]);
    $this->postJson(str($url)->after('8000')->toString())->assertOk()->assertExactJson(['version' => 2]);

    expect($project->sandbox->fresh()->files_version)->toBe(2);
})->group('FILE-004');

test('only the signed address can report file changes, and only for its own sandbox', function () {
    $sandbox = Sandbox::factory()->create();
    $other = Sandbox::factory()->create();
    $signature = str(filesChangedPath($other))->after('?')->toString();

    $this->postJson(route('sandbox-events.files', $sandbox, absolute: false))->assertForbidden();
    $this->postJson(route('sandbox-events.files', $sandbox, absolute: false).'?'.$signature)->assertForbidden();

    expect($sandbox->fresh()->files_version)->toBe(0)
        ->and($other->fresh()->files_version)->toBe(0);
})->group('FILE-004');

test('the files panel reads the version without calling into the sandbox', function () {
    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['external_id' => 'ctr-1', 'files_version' => 7]);

    $this->actingAs($user)
        ->getJson(route('projects.files.version', $project))
        ->assertOk()
        ->assertExactJson(['version' => 7]);

    /** @var FakeSandboxProvider $provider */
    $provider = app(SandboxProvider::class);
    expect($provider->executed)->toBe([]);
})->group('FILE-004');

test('a project without a sandbox has no file changes yet', function () {
    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();

    $this->actingAs($user)
        ->getJson(route('projects.files.version', $project))
        ->assertOk()
        ->assertExactJson(['version' => 0]);
})->group('FILE-004');

test('other users can\'t check a project\'s file changes', function () {
    $project = Project::factory()->create();
    Sandbox::factory()->for($project)->create();

    $this->actingAs(User::factory()->has(AgentConnection::factory())->create())
        ->getJson(route('projects.files.version', $project))
        ->assertForbidden();
})->group('FILE-004');
