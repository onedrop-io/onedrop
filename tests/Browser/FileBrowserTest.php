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

test('the files panel remembers being hidden or shown across reloads', function () {
    app()->instance(SandboxProvider::class, new FakeSandboxProvider);
    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['preview_url' => null]);
    $this->actingAs($user);

    visit("/projects/{$project->id}")
        ->resize(1600, 900)
        ->navigate("/projects/{$project->id}")
        ->assertVisible('@files-panel')
        ->click('@toggle-files')
        ->assertMissing('@files-panel')
        ->navigate("/projects/{$project->id}")
        ->assertMissing('@files-panel')
        ->resize(1280, 800)
        ->click('@toggle-files')
        ->assertVisible('@files-panel')
        ->navigate("/projects/{$project->id}")
        ->assertVisible('@files-panel')
        ->assertNoJavaScriptErrors();
})->group('FILE-001');

test('an open file can be edited with highlighting and saved', function () {
    $provider = new FakeSandboxProvider;
    $provider->execUsing = fn (array $command) => $command[0] === 'find'
        ? new ExecResult(0, "f app.js\n")
        : new ExecResult(0, "const answer = 1;\n");
    app()->instance(SandboxProvider::class, $provider);

    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['preview_url' => null]);
    $this->actingAs($user);

    visit("/projects/{$project->id}")
        ->resize(1600, 900)
        ->navigate("/projects/{$project->id}")
        ->click('[data-test="file-app.js"]')
        ->assertSeeIn('@file-viewer', 'const answer = 1;')
        ->assertPresent('[data-test="file-viewer"] .cm-line span')
        ->click('[data-test="file-viewer"] .cm-content')
        ->keys('[data-test="file-viewer"] .cm-content', 'ControlOrMeta+End')
        ->typeSlowly('[data-test="file-viewer"] .cm-content', '// edited', 10)
        ->assertVisible('@file-dirty')
        ->assertSeeIn('@file-save-state', 'Unsaved changes')
        ->keys('[data-test="file-viewer"] .cm-content', 'ControlOrMeta+s')
        ->assertMissing('@file-dirty')
        ->assertNoJavaScriptErrors();

    $write = collect($provider->executed)->firstWhere('command.0', 'sh');
    expect($write['env']['APP_CONTENT'])->toContain('// edited');
})->group('FILE-002');

test('the files menu hides dotfiles, creates a file and closes the panel', function () {
    $provider = new FakeSandboxProvider;
    $provider->execUsing = fn (array $command) => $command[0] === 'find'
        ? new ExecResult(0, "f .env\nf package.json\n")
        : new ExecResult(0, '');
    app()->instance(SandboxProvider::class, $provider);

    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['preview_url' => null]);
    $this->actingAs($user);

    visit("/projects/{$project->id}")
        ->resize(1600, 900)
        ->navigate("/projects/{$project->id}")
        ->assertSeeIn('@files-panel', '.env')
        ->click('@files-menu')
        ->click('@files-toggle-hidden')
        ->assertDontSeeIn('@files-panel', '.env')
        ->assertSeeIn('@files-panel', 'package.json')
        ->navigate("/projects/{$project->id}")
        ->assertDontSeeIn('@files-panel', '.env')
        ->click('@files-menu')
        ->click('@files-new-file')
        ->assertVisible('@files-new-dialog')
        ->assertScript('document.activeElement?.dataset.test', 'files-new-path')
        ->type('@files-new-path', 'src/notes.md')
        ->click('@files-new-submit')
        ->assertMissing('@files-new-dialog')
        ->assertSeeIn('@sandbox-status', 'src/notes.md')
        ->click('@files-menu')
        ->click('@files-new-folder')
        ->assertVisible('@files-new-dialog')
        ->assertScript('document.activeElement?.dataset.test', 'files-new-path')
        ->keys('@files-new-path', 'Escape')
        ->assertMissing('@files-new-dialog')
        ->click('@files-menu')
        ->click('@files-close')
        ->assertMissing('@files-panel')
        ->assertNoJavaScriptErrors();

    $create = collect($provider->executed)->first(fn (array $exec) => str_contains($exec['command'][2] ?? '', 'exit 3'));
    expect($create['command'][4])->toBe('/workspace/src/notes.md');
})->group('FILE-003');
