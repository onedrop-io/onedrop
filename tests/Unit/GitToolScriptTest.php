<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->workspace = sys_get_temp_dir().'/zap-git-tool-'.uniqid();
    File::ensureDirectoryExists($this->workspace);
    $sandbox = dirname(__DIR__, 2).'/docker/sandbox';

    $this->tool = function (array $request) use ($sandbox) {
        $result = Process::env([
            'APP_WORKSPACE' => $this->workspace,
            'APP_CHECKPOINT' => "{$sandbox}/checkpoint",
            'APP_GIT_REQUEST' => json_encode($request),
        ])->run(['php', "{$sandbox}/git.php"]);

        return json_decode($result->output(), true);
    };
    $this->data = fn (array $request) => ($this->tool)($request)['data'];
    $this->git = fn (string ...$args) => trim(Process::path($this->workspace)->run(['git', ...$args])->output());
    $this->write = fn (string $path, string $content) => File::put("{$this->workspace}/{$path}", $content);
});

afterEach(function () {
    File::deleteDirectory($this->workspace);
});

test('a project without a repository reports it, and its first commit starts one on main as the user', function () {
    expect(($this->data)(['op' => 'status'])['initialized'])->toBeFalse();

    ($this->write)('index.html', 'hi');
    $status = ($this->data)(['op' => 'commit', 'message' => 'First page', 'name' => 'Dev User', 'email' => 'dev@example.com']);
    $commits = ($this->data)(['op' => 'log'])['commits'];

    expect($status)->toMatchArray(['initialized' => true, 'branch' => 'main', 'branches' => ['main'], 'changes' => []])
        ->and($commits)->toHaveCount(1)
        ->and($commits[0])->toMatchArray(['subject' => 'First page', 'author' => 'Dev User', 'email' => 'dev@example.com', 'agent' => false]);
})->group('GIT-001');

test('the agent\'s checkpoints are marked as the agent\'s', function () {
    ($this->write)('index.html', 'hi');
    Process::path($this->workspace)->env(['ZAP_WORKSPACE' => $this->workspace])->input('Build a timer')->run([dirname(__DIR__, 2).'/docker/sandbox/checkpoint']);

    expect(($this->data)(['op' => 'log'])['commits'][0])->toMatchArray(['subject' => 'Build a timer', 'author' => 'OneDrop', 'agent' => true]);
})->group('GIT-001');

test('uncommitted changes are listed with their state', function () {
    ($this->write)('kept.txt', 'a');
    ($this->write)('gone.txt', 'a');
    ($this->data)(['op' => 'commit', 'message' => 'Start']);
    ($this->write)('kept.txt', 'b');
    File::delete("{$this->workspace}/gone.txt");
    ($this->write)('new.txt', 'c');

    expect(($this->data)(['op' => 'status'])['changes'])->toEqualCanonicalizing([
        ['path' => 'kept.txt', 'status' => 'M'],
        ['path' => 'gone.txt', 'status' => 'D'],
        ['path' => 'new.txt', 'status' => '?'],
    ]);
})->group('GIT-002');

test('committing with nothing changed or no message is refused', function () {
    ($this->write)('index.html', 'hi');
    ($this->data)(['op' => 'commit', 'message' => 'Start']);

    expect(($this->tool)(['op' => 'commit', 'message' => 'Again']))->toBe(['ok' => false, 'error' => "There's nothing to commit."])
        ->and(($this->tool)(['op' => 'commit', 'message' => ' ']))->toBe(['ok' => false, 'error' => 'Write a message for the commit.']);
})->group('GIT-002');

test('one file or every change can be discarded, keeping ignored files', function () {
    ($this->write)('a.txt', 'a');
    ($this->write)('b.txt', 'b');
    ($this->data)(['op' => 'commit', 'message' => 'Start']);
    ($this->write)('a.txt', 'changed');
    ($this->write)('b.txt', 'changed');
    ($this->write)('new.txt', 'new');
    ($this->write)('.env', 'SECRET=1');

    ($this->data)(['op' => 'discard', 'path' => 'a.txt']);
    expect(File::get("{$this->workspace}/a.txt"))->toBe('a')
        ->and(File::get("{$this->workspace}/b.txt"))->toBe('changed');

    $status = ($this->data)(['op' => 'discard', 'path' => null]);

    expect($status['changes'])->toBe([])
        ->and(File::get("{$this->workspace}/b.txt"))->toBe('b')
        ->and(File::exists("{$this->workspace}/new.txt"))->toBeFalse()
        ->and(File::get("{$this->workspace}/.env"))->toBe('SECRET=1')
        ->and(($this->tool)(['op' => 'discard', 'path' => '../outside'])['error'])->toBe("That isn't a file in this project.");
})->group('GIT-002');

test('branches can be created and switched, and bad names are refused', function () {
    ($this->write)('a.txt', 'a');
    ($this->data)(['op' => 'commit', 'message' => 'Start']);

    expect(($this->data)(['op' => 'switch', 'branch' => 'feature/pricing', 'create' => true])['branch'])->toBe('feature/pricing')
        ->and(($this->data)(['op' => 'switch', 'branch' => 'main'])['branches'])->toBe(['feature/pricing', 'main'])
        ->and(($this->tool)(['op' => 'switch', 'branch' => '--force'])['error'])->toBe("That isn't a valid branch name.");
})->group('GIT-002');

test('restoring an earlier version is a new commit, after committing what was uncommitted', function () {
    ($this->write)('a.txt', 'first');
    ($this->data)(['op' => 'commit', 'message' => 'First']);
    $first = ($this->git)('rev-parse', 'HEAD');
    ($this->write)('a.txt', 'second');
    ($this->write)('b.txt', 'added later');
    ($this->data)(['op' => 'commit', 'message' => 'Second']);
    ($this->write)('a.txt', 'uncommitted');

    ($this->data)(['op' => 'restore', 'sha' => $first, 'name' => 'Dev User', 'email' => 'dev@example.com']);

    expect(File::get("{$this->workspace}/a.txt"))->toBe('first')
        ->and(File::exists("{$this->workspace}/b.txt"))->toBeFalse()
        ->and(($this->git)('log', '--format=%an|%s'))->toBe(implode("\n", [
            'Dev User|Restore "First" ('.substr($first, 0, 7).')',
            'Dev User|Changes before restoring '.substr($first, 0, 7),
            'OneDrop|Second',
            'OneDrop|First',
        ]))
        ->and(($this->tool)(['op' => 'restore', 'sha' => str_repeat('a', 40)])['error'])->toBe("That version isn't in this project's history.");
})->group('GIT-003');

test('what the platform pushed and pulled is tracked, and pulls only fast-forward', function () {
    ($this->write)('a.txt', 'a');
    ($this->data)(['op' => 'commit', 'message' => 'Start']);
    $start = ($this->git)('rev-parse', 'HEAD');
    ($this->write)('a.txt', 'b');
    ($this->data)(['op' => 'commit', 'message' => 'Local']);

    expect(($this->data)(['op' => 'pushed', 'branch' => 'main', 'sha' => $start])['tracking'])->toBe(['ahead' => 1, 'behind' => 0]);

    // The remote gains a commit on top of what we have: a fast-forward.
    $remote = sys_get_temp_dir().'/zap-git-remote-'.uniqid();
    Process::run(['git', 'clone', '-q', $this->workspace, $remote])->throw();
    Process::path($remote)->run('echo c > c.txt && git add c.txt && git -c user.name=Me -c user.email=me@example.com commit -q -m "From the remote" && git bundle create -q /tmp/zap-test-pull.bundle main')->throw();

    $status = ($this->data)(['op' => 'pulled', 'branch' => 'main', 'bundle' => '/tmp/zap-test-pull.bundle']);

    expect(File::get("{$this->workspace}/c.txt"))->toBe("c\n")
        ->and($status['tracking'])->toBe(['ahead' => 0, 'behind' => 0])
        ->and(File::exists('/tmp/zap-test-pull.bundle'))->toBeFalse();

    // Both sides move on: refused, for the agent to merge.
    ($this->write)('a.txt', 'local again');
    ($this->data)(['op' => 'commit', 'message' => 'Local again']);
    Process::path($remote)->run('echo d > d.txt && git add d.txt && git -c user.name=Me -c user.email=me@example.com commit -q -m "Remote again" && git bundle create -q /tmp/zap-test-pull.bundle main')->throw();

    expect(($this->tool)(['op' => 'pulled', 'branch' => 'main', 'bundle' => '/tmp/zap-test-pull.bundle'])['error'])
        ->toBe('This branch and the remote both have new commits. Ask the agent to merge them.');

    File::deleteDirectory($remote);
})->group('GIT-004');

test('a commit shows its full message, author, parents and changed files, and each file\'s diff', function () {
    ($this->write)('a.txt', "a\nb\n");
    File::put("{$this->workspace}/logo.bin", "\x00\x01\x02");
    ($this->data)(['op' => 'commit', 'message' => "First\n\nWith a longer body.", 'name' => 'Dev User', 'email' => 'dev@example.com']);
    $first = ($this->git)('rev-parse', 'HEAD');
    ($this->write)('a.txt', "a\nc\n");
    File::delete("{$this->workspace}/logo.bin");
    ($this->data)(['op' => 'commit', 'message' => 'Second']);
    $second = ($this->git)('rev-parse', 'HEAD');

    expect(($this->data)(['op' => 'show', 'sha' => $first]))->toMatchArray([
        'sha' => $first, 'subject' => 'First', 'body' => 'With a longer body.', 'author' => 'Dev User', 'email' => 'dev@example.com', 'parents' => [],
        'files' => [
            ['path' => 'a.txt', 'status' => 'A', 'additions' => 2, 'deletions' => 0, 'binary' => false],
            ['path' => 'logo.bin', 'status' => 'A', 'additions' => null, 'deletions' => null, 'binary' => true],
        ],
    ])
        ->and(($this->data)(['op' => 'show', 'sha' => $second]))->toMatchArray([
            'parents' => [$first],
            'files' => [
                ['path' => 'a.txt', 'status' => 'M', 'additions' => 1, 'deletions' => 1, 'binary' => false],
                ['path' => 'logo.bin', 'status' => 'D', 'additions' => null, 'deletions' => null, 'binary' => true],
            ],
        ]);

    $diff = ($this->data)(['op' => 'diff', 'sha' => $second, 'path' => 'a.txt']);

    expect($diff['patch'])->toContain("@@ -1,2 +1,2 @@\n a\n-b\n+c\n")
        ->and($diff['truncated'])->toBeFalse()
        ->and(($this->tool)(['op' => 'diff', 'sha' => $second, 'path' => '../etc/passwd'])['error'])->toBe("That isn't a file in this project.");
})->group('GIT-001');

test('pulling into a project with no repository yet brings the repository in on its branch', function () {
    $remote = sys_get_temp_dir().'/zap-git-import-'.uniqid();
    File::ensureDirectoryExists($remote);
    Process::path($remote)->run('git init -q -b trunk && echo hi > README.md && git add . && git -c user.name=Me -c user.email=me@example.com commit -q -m "Imported" && git bundle create -q /tmp/zap-test-import.bundle trunk')->throw();

    $status = ($this->data)(['op' => 'pulled', 'branch' => 'trunk', 'bundle' => '/tmp/zap-test-import.bundle']);

    expect($status)->toMatchArray(['initialized' => true, 'branch' => 'trunk', 'changes' => [], 'tracking' => ['ahead' => 0, 'behind' => 0]])
        ->and(File::get("{$this->workspace}/README.md"))->toBe("hi\n")
        ->and(($this->git)('log', '--format=%s'))->toBe('Imported');

    File::deleteDirectory($remote);
})->group('GIT-005');

test('the history is searched by message, author or id across every commit, a page at a time', function () {
    foreach (['Build a timer' => 'OneDrop', 'Make the button blue' => 'Dev User', 'Add a reports page' => 'Sam', "Fix the header\n\nThe TIMER overlapped it." => 'Dev User'] as $message => $author) {
        ($this->write)('a.txt', $message);
        ($this->data)(['op' => 'commit', 'message' => $message, 'name' => $author, 'email' => 'x@example.com']);
    }

    $subjects = fn (array $request) => array_column(($this->data)(['op' => 'log', ...$request])['commits'], 'subject');
    $sha = ($this->git)('rev-list', '--max-count=1', '--grep=reports', 'HEAD');

    expect($subjects(['query' => 'timer']))->toBe(['Fix the header', 'Build a timer'])
        ->and($subjects(['query' => 'dev user']))->toBe(['Fix the header', 'Make the button blue'])
        ->and($subjects(['query' => 'agent']))->toBe(['Build a timer'])
        ->and($subjects(['query' => substr($sha, 0, 7)]))->toBe(['Add a reports page'])
        ->and($subjects(['query' => 'nothing like this']))->toBe([])
        ->and(($this->data)(['op' => 'log', 'limit' => 2]))->toMatchArray(['more' => true])
        ->and($subjects(['limit' => 2, 'offset' => 2]))->toBe(['Make the button blue', 'Build a timer'])
        ->and(($this->data)(['op' => 'log', 'limit' => 2, 'offset' => 2])['more'])->toBeFalse();
})->group('GIT-001');
