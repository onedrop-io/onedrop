<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->workspace = sys_get_temp_dir().'/onedrop-checkpoint-'.uniqid();
    File::ensureDirectoryExists($this->workspace);

    $this->checkpoint = fn (string $message = 'Build a timer', array $env = [], array $args = []) => Process::path($this->workspace)
        ->env(['ONEDROP_WORKSPACE' => $this->workspace, ...$env])
        ->input($message)
        ->run([dirname(__DIR__, 2).'/docker/sandbox/checkpoint', ...$args]);

    $this->git = fn (string ...$args) => trim(Process::path($this->workspace)->run(['git', ...$args])->output());
});

afterEach(function () {
    File::deleteDirectory($this->workspace);
});

test('a checkpoint starts a repository on main and commits the turn with the prompt', function () {
    File::put("{$this->workspace}/index.html", '<h1>Timer</h1>');

    expect(($this->checkpoint)("Build a timer\n\nWith a start button.")->successful())->toBeTrue()
        ->and(($this->git)('branch', '--show-current'))->toBe('main')
        ->and(($this->git)('log', '--format=%an|%B'))->toBe("OneDrop|Build a timer\n\nWith a start button.")
        ->and(($this->git)('ls-files'))->toBe('index.html');
})->group('SBX-006');

test('dependencies, caches and secrets are never committed', function () {
    foreach (['node_modules/react/index.js', 'vendor/autoload.php', '.cache/x', '.env', '.env.local', '.env.example', 'app.js'] as $file) {
        File::ensureDirectoryExists(dirname("{$this->workspace}/{$file}"));
        File::put("{$this->workspace}/{$file}", 'x');
    }

    ($this->checkpoint)();

    expect(explode("\n", ($this->git)('ls-files')))->toBe(['.env.example', 'app.js']);
})->group('SBX-006');

test('a turn that changed nothing makes no commit', function () {
    File::put("{$this->workspace}/index.html", 'hi');
    ($this->checkpoint)();

    ($this->checkpoint)('Nothing to do');

    expect(($this->git)('rev-list', '--count', 'HEAD'))->toBe('1');
})->group('SBX-006');

test('an existing repository keeps its branch and history', function () {
    Process::path($this->workspace)->run('git init -q -b develop && git -c user.name=Me -c user.email=me@example.com commit -q --allow-empty -m "First"');
    File::put("{$this->workspace}/index.html", 'hi');

    ($this->checkpoint)('Add a page');

    expect(($this->git)('branch', '--show-current'))->toBe('develop')
        ->and(($this->git)('log', '--format=%s'))->toBe("Add a page\nFirst");
})->group('SBX-006');

test('a repository in the middle of a merge gets no commit', function () {
    File::put("{$this->workspace}/index.html", 'hi');
    ($this->checkpoint)();
    File::put("{$this->workspace}/.git/MERGE_HEAD", ($this->git)('rev-parse', 'HEAD'));
    File::put("{$this->workspace}/index.html", 'changed');

    ($this->checkpoint)('Mid-merge');

    expect(($this->git)('rev-list', '--count', 'HEAD'))->toBe('1');
})->group('SBX-006');

test('each turn is also saved as a private checkpoint, which the branch never sees', function () {
    File::put("{$this->workspace}/index.html", 'hi');
    ($this->checkpoint)('Build a timer');
    File::put("{$this->workspace}/index.html", 'changed');
    ($this->checkpoint)('Change it');

    expect(($this->git)('log', '--format=%s|%(trailers:key=Onedrop-Kind,valueonly,separator=)', 'refs/onedrop/checkpoints'))->toBe("Change it|turn\nBuild a timer|turn")
        ->and(($this->git)('rev-parse', 'refs/onedrop/checkpoints^{tree}'))->toBe(($this->git)('rev-parse', 'HEAD^{tree}'))
        ->and(($this->git)('branch', '--contains', 'refs/onedrop/checkpoints'))->toBe('');
})->group('SBX-006', 'SCM-002');

test('with turn commits off, a turn is only a private checkpoint: the changes stay uncommitted and unstaged', function () {
    Process::path($this->workspace)->run('git init -q -b main && git -c user.name=Me -c user.email=me@example.com commit -q --allow-empty -m "First"');
    File::put("{$this->workspace}/index.html", 'hi');
    File::put("{$this->workspace}/.env", 'SECRET=1');

    ($this->checkpoint)('Add a page', ['ONEDROP_COMMIT_TURNS' => '0']);

    expect(($this->git)('log', '--format=%s'))->toBe('First')
        ->and(($this->git)('status', '--porcelain'))->toBe('?? index.html')
        ->and(($this->git)('ls-tree', '--name-only', 'refs/onedrop/checkpoints'))->toBe('index.html')
        ->and(($this->git)('rev-parse', 'refs/onedrop/checkpoints^'))->toBe(($this->git)('rev-parse', 'HEAD'))
        ->and(($this->git)('log', '-1', '--format=%an|%(trailers:key=Onedrop-Head,valueonly,separator=)', 'refs/onedrop/checkpoints'))->toBe('OneDrop|'.($this->git)('rev-parse', 'HEAD'));
})->group('SBX-006', 'SCM-002');

test('a snapshot saves edits made outside the agent only when files changed, and prints the newest checkpoint', function () {
    File::put("{$this->workspace}/index.html", 'hi');
    ($this->checkpoint)('Build a timer', ['ONEDROP_COMMIT_TURNS' => '0']);
    $turn = ($this->git)('rev-parse', 'refs/onedrop/checkpoints');

    expect(trim(($this->checkpoint)('', [], ['--snapshot'])->output()))->toBe($turn);

    File::put("{$this->workspace}/index.html", 'edited in the shell');
    $edits = trim(($this->checkpoint)('', [], ['--snapshot'])->output());

    expect($edits)->not->toBe($turn)
        ->and(($this->git)('log', '-1', '--format=%s|%(trailers:key=Onedrop-Kind,valueonly,separator=)', $edits))->toBe('Changes outside the agent|edits')
        ->and(($this->git)('rev-parse', "{$edits}^"))->toBe($turn)
        ->and(($this->git)('rev-parse', '--verify', '-q', 'HEAD'))->toBe('');
})->group('SBX-006', 'SCM-002');

test('a turn mid-merge is still saved as a checkpoint', function () {
    File::put("{$this->workspace}/index.html", 'hi');
    ($this->checkpoint)();
    File::put("{$this->workspace}/.git/MERGE_HEAD", ($this->git)('rev-parse', 'HEAD'));
    File::put("{$this->workspace}/index.html", 'changed');

    ($this->checkpoint)('Mid-merge');

    expect(($this->git)('log', '-1', '--format=%s', 'refs/onedrop/checkpoints'))->toBe('Mid-merge');
})->group('SBX-006', 'SCM-002');
