<?php

use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\ExecResult;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;

test('the files panel lists the workspace and opens a file next to the preview', function () {
    $provider = new FakeSandboxProvider;
    $provider->execUsing = fn (array $command) => $command[0] === 'find'
        ? new ExecResult(0, "d src\nf src/App.tsx\nf package.json\nd node_modules\n")
        : new ExecResult(0, "export default function App() {}\n");
    app()->instance(SandboxProvider::class, $provider);

    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['preview_url' => null]);
    $this->actingAs($user);

    visit("/projects/{$project->id}")
        ->resize(1600, 900)
        ->navigate("/projects/{$project->id}")
        ->assertVisible('@files-panel')
        ->assertSeeIn('@files-panel', 'package.json')
        ->assertDontSee('App.tsx')
        ->click('@file-src')
        ->click('[data-test="file-src/App.tsx"]')
        ->assertSeeIn('@file-viewer', 'export default function App() {}')
        ->assertSeeIn('@sandbox-status', 'src/App.tsx')
        ->click('Preview')
        ->assertDontSee('export default function App() {}')
        ->click('@toggle-files')
        ->assertMissing('@files-panel')
        ->assertNoJavaScriptErrors();
})->group('FILE-001');

test('the files panel starts hidden on narrower screens', function () {
    app()->instance(SandboxProvider::class, new FakeSandboxProvider);
    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['preview_url' => null]);
    $this->actingAs($user);

    visit("/projects/{$project->id}")
        ->resize(1280, 800)
        ->navigate("/projects/{$project->id}")
        ->assertMissing('@files-panel')
        ->click('@toggle-files')
        ->assertVisible('@files-panel')
        ->assertNoJavaScriptErrors();
})->group('FILE-001');
