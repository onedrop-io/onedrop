<?php

use App\Enums\SandboxStatus;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\ExecResult;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;

beforeEach(function () {
    $this->provider = new FakeSandboxProvider;
    app()->instance(SandboxProvider::class, $this->provider);

    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->project = Project::factory()->for($this->user)->create();
    Sandbox::factory()->for($this->project)->create(['external_id' => 'ctr-1']);
});

test('lists workspace files, folders and collapsed folders', function () {
    $this->provider->execUsing = fn () => new ExecResult(0, "d src\nf src/App.tsx\nf package.json\nd node_modules\nf index.html\n");

    $this->actingAs($this->user)
        ->getJson(route('projects.files.index', $this->project))
        ->assertOk()
        ->assertExactJson(['files' => [
            ['path' => 'index.html', 'type' => 'file'],
            ['path' => 'node_modules', 'type' => 'dir'],
            ['path' => 'package.json', 'type' => 'file'],
            ['path' => 'src', 'type' => 'dir'],
            ['path' => 'src/App.tsx', 'type' => 'file'],
        ]]);

    $command = $this->provider->executed[0]['command'];
    expect($command[0])->toBe('find')
        ->and($command)->toContain('/workspace', '-prune', 'node_modules', 'vendor', '.git');
})->group('FILE-001');

test('reads a file inside the workspace', function () {
    $this->provider->execUsing = fn () => new ExecResult(0, "export default 1;\n");

    $this->actingAs($this->user)
        ->getJson(route('projects.files.show', [$this->project, 'path' => 'src/App.tsx']))
        ->assertOk()
        ->assertExactJson(['path' => 'src/App.tsx', 'content' => "export default 1;\n", 'notice' => null]);

    expect($this->provider->executed[0]['command'])->toBe(['head', '--bytes', '200001', '--', '/workspace/src/App.tsx']);
})->group('FILE-001');

test('binary and oversized files get a notice instead of content', function (string $output, string $notice) {
    $this->provider->execUsing = fn () => new ExecResult(0, $output);

    $this->actingAs($this->user)
        ->getJson(route('projects.files.show', [$this->project, 'path' => 'logo.png']))
        ->assertOk()
        ->assertJson(['content' => null])
        ->assertJsonFragment(['notice' => $notice]);
})->with([
    'binary' => ["\x89PNG\0\0data", "This file isn't text, so it can't be shown here."],
    'too large' => [str_repeat('a', 200_001), 'This file is too large to show here.'],
])->group('FILE-001');

test('paths outside the workspace are refused', function (string $path) {
    $this->actingAs($this->user)
        ->getJson(route('projects.files.show', [$this->project, 'path' => $path]))
        ->assertStatus(422);

    expect($this->provider->executed)->toBe([]);
})->with(['parent' => ['../etc/passwd'], 'nested parent' => ['src/../../etc/passwd'], 'absolute' => ['/etc/passwd']])->group('FILE-001');

test('other users cannot list or read files', function () {
    $other = User::factory()->has(AgentConnection::factory())->create();

    $this->actingAs($other)->getJson(route('projects.files.index', $this->project))->assertForbidden();
    $this->actingAs($other)->getJson(route('projects.files.show', [$this->project, 'path' => 'a.txt']))->assertForbidden();

    expect($this->provider->executed)->toBe([]);
})->group('FILE-001');

test('a stopped sandbox reports why files are unavailable', function () {
    $this->project->sandbox->update(['status' => SandboxStatus::Failed]);

    $this->actingAs($this->user)
        ->getJson(route('projects.files.index', $this->project))
        ->assertStatus(409)
        ->assertJson(['message' => "The project's sandbox isn't running."]);
})->group('FILE-001');

test('a failed read returns an error message', function () {
    $this->provider->execUsing = fn () => new ExecResult(1, '', 'No such file');

    $this->actingAs($this->user)
        ->getJson(route('projects.files.show', [$this->project, 'path' => 'missing.txt']))
        ->assertStatus(502)
        ->assertJson(['message' => "Couldn't open missing.txt."]);
})->group('FILE-001');
