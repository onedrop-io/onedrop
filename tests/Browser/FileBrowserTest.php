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

test('on a phone the files panel covers the workspace from the ⋯ menu, and opening a file closes it', function () {
    $provider = new FakeSandboxProvider;
    $provider->execUsing = fn (array $command) => $command[0] === 'find'
        ? new ExecResult(0, "f package.json\n")
        : new ExecResult(0, "{\"name\": \"app\"}\n");
    app()->instance(SandboxProvider::class, $provider);
    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['preview_url' => null]);
    $this->actingAs($user);

    visit("/projects/{$project->id}")
        ->resize(1600, 900)
        ->navigate("/projects/{$project->id}")
        ->assertVisible('@files-panel')
        ->resize(390, 844)
        ->navigate("/projects/{$project->id}")
        ->click('@mobile-tab-workspace')
        ->assertMissing('@files-panel')
        ->click('@pane-more')
        ->click('@pane-more-files')
        ->assertVisible('@files-panel')
        ->click('[data-test="file-package.json"]')
        ->assertMissing('@files-panel')
        ->assertSeeIn('@file-viewer', '"name": "app"')
        // The editor is still settling: a click on "⋯" right away doesn't open it.
        ->wait(1)
        ->click('@pane-more')
        ->click('@pane-more-files')
        ->click('@files-panel-close')
        ->assertMissing('@files-panel')
        ->assertNoJavaScriptErrors();
})->group('FILE-001', 'LAYOUT-007');

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
        ->assertMissing('@file-restart-prompt')
        ->assertNoJavaScriptErrors();

    $write = collect($provider->executed)->firstWhere('command.0', 'sh');
    expect($write['env']['APP_CONTENT'])->toContain('// edited');
})->group('FILE-002', 'FILE-007');

test('saving a compose file asks to restart the preview', function () {
    $provider = new FakeSandboxProvider;
    $provider->execUsing = fn (array $command) => $command[0] === 'find'
        ? new ExecResult(0, "f docker-compose.yml\n")
        : new ExecResult(0, "services: {}\n");
    app()->instance(SandboxProvider::class, $provider);

    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['preview_url' => null]);
    $this->actingAs($user);

    visit("/projects/{$project->id}")
        ->resize(1600, 900)
        ->navigate("/projects/{$project->id}")
        ->click('[data-test="file-docker-compose.yml"]')
        ->assertSeeIn('@file-viewer', 'services: {}')
        ->click('[data-test="file-viewer"] .cm-content')
        ->keys('[data-test="file-viewer"] .cm-content', 'ControlOrMeta+End')
        ->typeSlowly('[data-test="file-viewer"] .cm-content', '# edited', 10)
        ->assertMissing('@file-restart-prompt')
        ->keys('[data-test="file-viewer"] .cm-content', 'ControlOrMeta+s')
        ->assertSeeIn('@file-restart-prompt', 'Restart the preview to use the change?')
        ->click('@file-restart')
        ->assertSeeIn('@file-restart-prompt', 'The preview is restarting')
        ->click('@file-restart-dismiss')
        ->assertMissing('@file-restart-prompt')
        ->assertNoJavaScriptErrors();

    expect(collect($provider->executed)->pluck('command.0'))->toContain('/opt/onedrop/restart');
})->group('FILE-007');

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

test('the files panel shows files made outside the agent once the sandbox reports them', function () {
    $listing = "f package.json\n";
    $provider = new FakeSandboxProvider;
    $provider->execUsing = function (array $command) use (&$listing) {
        return new ExecResult(0, $listing);
    };
    app()->instance(SandboxProvider::class, $provider);

    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    $sandbox = Sandbox::factory()->for($project)->create(['preview_url' => null, 'files_version' => 1]);
    $this->actingAs($user);

    $page = visit("/projects/{$project->id}")
        ->resize(1600, 900)
        ->navigate("/projects/{$project->id}")
        ->assertSeeIn('@files-panel', 'package.json')
        ->assertDontSee('notes.md');

    // Made in the Shell: the file watcher reports it, and the panel picks it up without a click.
    $listing = "f notes.md\nf package.json\n";
    $sandbox->increment('files_version');

    $page->assertSeeIn('@files-panel', 'notes.md')
        ->assertNoJavaScriptErrors();
})->group('FILE-004');

test('each file and folder has a menu to rename, search, open the shell in, and delete it', function () {
    $listing = "d src\nf src/App.tsx\nf src/util.ts\nf package.json\n";
    $provider = new FakeSandboxProvider;
    $provider->execUsing = function (array $command) use (&$listing) {
        return new ExecResult(0, $command[0] === 'find' ? $listing : '');
    };
    app()->instance(SandboxProvider::class, $provider);

    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['preview_url' => null, 'shell_url' => 'http://127.0.0.1:7681']);
    $this->actingAs($user);

    $page = visit("/projects/{$project->id}")
        ->resize(1600, 900)
        ->navigate("/projects/{$project->id}")
        ->click('@file-src')
        ->click('[data-test="file-menu-src/App.tsx"]')
        ->click('@file-action-rename')
        ->assertVisible('@file-rename-dialog')
        ->assertScript('document.activeElement?.dataset.test', 'file-rename-name')
        ->type('@file-rename-name', 'Main.tsx');

    $listing = "d src\nf src/Main.tsx\nf src/util.ts\nf package.json\n";

    $page->click('@file-rename-submit')
        ->assertMissing('@file-rename-dialog')
        ->assertPresent('[data-test="file-src/Main.tsx"]')
        ->type('@files-search', 'package')
        ->assertSeeIn('@files-panel', 'package.json')
        ->assertMissing('[data-test="file-src/util.ts"]')
        ->keys('@files-search', 'Escape')
        ->assertPresent('[data-test="file-src/util.ts"]')
        ->click('@file-menu-src')
        ->click('@file-action-search')
        ->assertScript('document.activeElement?.dataset.test', 'files-search')
        ->assertSeeIn('@files-search-folder', 'src')
        ->type('@files-search', 't')
        ->assertSeeIn('@files-panel', 'util.ts')
        ->assertDontSeeIn('@files-panel', 'package.json')
        ->keys('@files-search', 'Escape')
        ->assertMissing('@files-search-folder')
        ->assertSeeIn('@files-panel', 'package.json')
        ->click('@file-menu-src')
        ->click('@file-action-shell')
        ->assertVisible('@shell-frame-2')
        ->assertScript('document.querySelector(\'[data-test="shell-frame-2"]\').getAttribute("src").startsWith("http://127.0.0.1:7681/?arg=cd&arg=src&arg=session&arg=")', true)
        ->click('[data-test="file-menu-package.json"]')
        ->click('@file-action-delete')
        ->assertVisible('@file-delete-dialog');

    $listing = "d src\nf src/Main.tsx\nf src/util.ts\n";

    $page->click('@file-delete-submit')
        ->assertMissing('@file-delete-dialog')
        ->assertDontSeeIn('@files-panel', 'package.json')
        ->assertNoJavaScriptErrors();

    $commands = collect($provider->executed)->pluck('command');

    expect($commands->first(fn (array $command) => str_contains($command[2] ?? '', 'mv --')))
        ->toContain('/workspace/src/App.tsx', '/workspace/src/Main.tsx')
        ->and($commands)->toContain(['rm', '-rf', '--', '/workspace/package.json']);
})->group('FILE-005');

test('cmd+p goes to a file by typing letters of its name', function () {
    $provider = new FakeSandboxProvider;
    $provider->execUsing = fn (array $command) => $command[0] === 'find'
        ? new ExecResult(0, "d app\nd app/Http\nf app/Http/UserController.php\nf app/User.php\nf .env\nf package.json\n")
        : new ExecResult(0, "<?php // the controller\n");
    app()->instance(SandboxProvider::class, $provider);

    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['preview_url' => null]);
    $this->actingAs($user);

    visit("/projects/{$project->id}")
        ->resize(1600, 900)
        ->navigate("/projects/{$project->id}")
        ->assertSeeIn('@files-panel', 'package.json')
        ->click('@toggle-files')
        ->assertMissing('@files-panel')
        ->keys('@toggle-files', 'Meta+p')
        ->assertVisible('@quick-open')
        ->assertScript('document.activeElement?.dataset.test', 'quick-open-input')
        ->assertPresent('[data-test="quick-open-.env"]')
        ->type('@quick-open-input', 'usctl')
        ->assertPresent('[data-test="quick-open-app/Http/UserController.php"]')
        ->assertMissing('[data-test="quick-open-app/User.php"]')
        ->keys('@quick-open-input', 'Enter')
        ->assertMissing('@quick-open')
        ->assertSeeIn('@file-viewer', 'the controller')
        ->assertSeeIn('@sandbox-status', 'app/Http/UserController.php')
        ->keys('[data-test="file-viewer"] .cm-content', 'Control+p')
        ->assertVisible('@quick-open')
        ->assertScript(
            'document.querySelector(\'[role="option"]\')?.dataset.test',
            'quick-open-app/Http/UserController.php',
        )
        ->type('@quick-open-input', 'zzz')
        ->assertSeeIn('@quick-open', 'No files match.')
        ->keys('@quick-open-input', 'Escape')
        ->assertMissing('@quick-open')
        ->assertNoJavaScriptErrors();
})->group('FILE-006');

test('cmd or ctrl+p in a shell opens go to file', function () {
    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();

    // A Shell on another origin running the sandbox's real shell-keys.js, the way ttyd's page does.
    $shell = '<!doctype html><html><head><script>'.file_get_contents(base_path('docker/sandbox/shell-keys.js'))
        .'</script></head><body><textarea id="terminal"></textarea></body></html>';
    Sandbox::factory()->for($project)->create(['preview_url' => null, 'shell_url' => 'data:text/html,'.rawurlencode($shell)]);
    $this->actingAs($user);

    $page = visit("/projects/{$project->id}")
        ->click('@tab-shell')
        ->assertMissing('@quick-open');

    $page->withinFrame('[data-test="shell-frame"]', function ($frame) {
        $frame->keys('#terminal', PHP_OS_FAMILY === 'Darwin' ? 'Meta+p' : 'Control+p')
            ->assertValue('#terminal', '');
    });

    $page->assertVisible('@quick-open')
        ->assertNoJavaScriptErrors();
})->group('FILE-006');

test('cmd+shift+f searches inside files and opens a match at its line', function () {
    $match = fn (string $path, int $line, string $text, int $start) => json_encode(['type' => 'match', 'data' => [
        'path' => ['text' => $path],
        'lines' => ['text' => $text."\n"],
        'line_number' => $line,
        'submatches' => [['match' => ['text' => 'useState'], 'start' => $start, 'end' => $start + 8]],
    ]]);

    $provider = new FakeSandboxProvider;
    $provider->execUsing = fn (array $command) => match (true) {
        $command[0] === 'find' => new ExecResult(0, "d src\nf src/App.tsx\nf src/Counter.tsx\nf package.json\n"),
        $command[0] === 'sh' && in_array('--json', $command, true) => new ExecResult(0, implode("\n", [
            $match('src/App.tsx', 1, "import { useState } from 'react';", 9),
            $match('src/Counter.tsx', 3, '    const [n, setN] = useState(0);', 22),
        ])."\n", "rg-exit:0\n"),
        default => new ExecResult(0, "line one\nline two\n    const [n, setN] = useState(0);\n"),
    };
    app()->instance(SandboxProvider::class, $provider);

    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['preview_url' => null]);
    $this->actingAs($user);

    visit("/projects/{$project->id}")
        ->resize(1600, 900)
        ->navigate("/projects/{$project->id}")
        ->assertSeeIn('@files-panel', 'package.json')
        ->click('@toggle-files')
        ->assertMissing('@files-panel')
        ->keys('@toggle-files', 'Meta+Shift+f')
        ->assertVisible('@files-panel')
        ->assertScript('document.activeElement?.dataset.test', 'content-search')
        ->type('@content-search', 'useState')
        ->assertSeeIn('@content-search-results', '2 results in 2 files')
        ->assertSeeIn('[data-test="content-result-src/Counter.tsx"]', 'Counter.tsx')
        ->click('@content-search-word')
        ->assertScript('document.querySelector(\'[data-test="content-search-word"]\').getAttribute("aria-pressed")', 'true')
        // Searches run once typing or toggling pauses.
        ->wait(0.5)
        ->click('[data-test="content-result-src/Counter.tsx"] + ul [data-test="content-match"]')
        ->assertSeeIn('@file-viewer', 'line two')
        ->assertSeeIn('@sandbox-status', 'src/Counter.tsx')
        ->assertScript('getSelection()?.toString()', 'useState')
        ->keys('@content-search', 'Escape')
        ->assertSeeIn('@files-panel', 'package.json')
        ->assertNoJavaScriptErrors();

    $search = collect($provider->executed)->last(fn (array $exec) => in_array('--json', $exec['command'], true));
    expect($search['command'])->toContain('--word-regexp');
})->group('FILE-008');
