<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->workspace = sys_get_temp_dir().'/onedrop-checkpoint-'.uniqid();
    File::ensureDirectoryExists($this->workspace);

    $this->checkpoint = fn (string $message = 'Build a timer') => Process::path($this->workspace)
        ->env(['ONEDROP_WORKSPACE' => $this->workspace])
        ->input($message)
        ->run([dirname(__DIR__, 2).'/docker/sandbox/checkpoint']);

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

test('a repository in the middle of a merge is left alone', function () {
    File::put("{$this->workspace}/index.html", 'hi');
    ($this->checkpoint)();
    File::put("{$this->workspace}/.git/MERGE_HEAD", ($this->git)('rev-parse', 'HEAD'));
    File::put("{$this->workspace}/index.html", 'changed');

    ($this->checkpoint)('Mid-merge');

    expect(($this->git)('rev-list', '--count', 'HEAD'))->toBe('1');
})->group('SBX-006');
