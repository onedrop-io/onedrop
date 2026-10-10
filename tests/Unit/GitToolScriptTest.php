<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    // No background `git gc --auto`: it can still be writing in .git/objects while the test deletes the folder.
    putenv('GIT_CONFIG_COUNT=1');
    putenv('GIT_CONFIG_KEY_0=gc.auto');
    putenv('GIT_CONFIG_VALUE_0=0');

    $this->workspace = sys_get_temp_dir().'/onedrop-git-tool-'.uniqid();
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
    putenv('GIT_CONFIG_COUNT');
    putenv('GIT_CONFIG_KEY_0');
    putenv('GIT_CONFIG_VALUE_0');
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
    Process::path($this->workspace)->env(['ONEDROP_WORKSPACE' => $this->workspace])->input('Build a timer')->run([dirname(__DIR__, 2).'/docker/sandbox/checkpoint']);

    expect(($this->data)(['op' => 'log'])['commits'][0])->toMatchArray(['subject' => 'Build a timer', 'author' => 'OneDrop', 'agent' => true]);
})->group('GIT-001');

test('uncommitted changes are listed with their state and the lines added and removed', function () {
    ($this->write)('kept.txt', "a\nb\n");
    ($this->write)('gone.txt', "a\n");
    ($this->write)('logo.png', "\x89PNG\0");
    ($this->data)(['op' => 'commit', 'message' => 'Start']);
    ($this->write)('kept.txt', "a\nc\nd\n");
    File::delete("{$this->workspace}/gone.txt");
    ($this->write)('logo.png', "\x89PNG\0\0");
    ($this->write)('new.txt', "one\ntwo\nthree");

    expect(($this->data)(['op' => 'status'])['changes'])->toEqualCanonicalizing([
        ['path' => 'kept.txt', 'status' => 'M', 'additions' => 2, 'deletions' => 1, 'binary' => false],
        ['path' => 'gone.txt', 'status' => 'D', 'additions' => 0, 'deletions' => 1, 'binary' => false],
        ['path' => 'logo.png', 'status' => 'M', 'additions' => null, 'deletions' => null, 'binary' => true],
        ['path' => 'new.txt', 'status' => '?', 'additions' => 3, 'deletions' => 0, 'binary' => false],
    ]);
})->group('GIT-002', 'GIT-006');

test('staged files are committed and the rest stay unstaged; with nothing staged, everything is committed', function () {
    ($this->write)('a.txt', 'a');
    ($this->write)('b.txt', 'b');
    ($this->data)(['op' => 'commit', 'message' => 'Start']);
    ($this->write)('a.txt', 'changed');
    File::delete("{$this->workspace}/b.txt");
    ($this->write)('new.txt', 'new');

    $status = ($this->data)(['op' => 'stage', 'paths' => ['b.txt', 'new.txt']]);

    expect(array_column($status['staged'], 'status', 'path'))->toBe(['b.txt' => 'D', 'new.txt' => 'A'])
        ->and(array_column($status['changes'], 'path'))->toBe(['a.txt']);

    $status = ($this->data)(['op' => 'commit', 'message' => 'Some of it']);

    expect($status['staged'])->toBe([])
        ->and(array_column($status['changes'], 'path'))->toBe(['a.txt'])
        ->and(explode("\n", ($this->git)('show', '--name-status', '--format=', 'HEAD')))->toEqualCanonicalizing(["D\tb.txt", "A\tnew.txt"])
        ->and(($this->tool)(['op' => 'stage', 'paths' => ['elsewhere.txt']]))->toBe(['ok' => false, 'error' => 'Some of those files have no changes.'])
        ->and(($this->tool)(['op' => 'stage', 'paths' => []]))->toBe(['ok' => false, 'error' => 'Pick at least one file.']);

    expect(($this->data)(['op' => 'commit', 'message' => 'The rest'])['changes'])->toBe([])
        ->and(($this->git)('show', 'HEAD:a.txt'))->toBe('changed');
})->group('GIT-006', 'SCM-001');

test('files are staged and unstaged, one at a time or all, without changing them', function () {
    ($this->write)('a.txt', 'a');
    ($this->data)(['op' => 'commit', 'message' => 'Start']);
    ($this->write)('a.txt', 'changed');
    ($this->write)('new.txt', 'new');
    ($this->write)('.env', 'SECRET=1');

    $all = ($this->data)(['op' => 'stage']);

    expect(array_column($all['staged'], 'path'))->toEqualCanonicalizing(['a.txt', 'new.txt'])
        ->and($all['changes'])->toBe([]);

    $one = ($this->data)(['op' => 'unstage', 'paths' => ['new.txt']]);

    expect(array_column($one['staged'], 'path'))->toBe(['a.txt'])
        ->and(array_column($one['changes'], 'status', 'path'))->toBe(['new.txt' => '?']);

    ($this->write)('a.txt', 'changed again');
    $both = ($this->data)(['op' => 'status']);

    expect(array_column($both['staged'], 'path'))->toBe(['a.txt'])
        ->and(array_column($both['changes'], 'path'))->toEqualCanonicalizing(['a.txt', 'new.txt'])
        ->and(($this->data)(['op' => 'unstage'])['staged'])->toBe([])
        ->and(File::get("{$this->workspace}/a.txt"))->toBe('changed again');
})->group('SCM-001');

test('the first commit can be staged and unstaged before there is any history', function () {
    ($this->write)('a.txt', 'a');
    ($this->write)('b.txt', 'b');

    ($this->data)(['op' => 'stage']);
    $status = ($this->data)(['op' => 'unstage', 'paths' => ['b.txt']]);

    expect(array_column($status['staged'], 'path'))->toBe(['a.txt'])
        ->and(($this->data)(['op' => 'commit', 'message' => 'Only a'])['changes'])->toHaveCount(1)
        ->and(($this->git)('ls-files'))->toBe('a.txt');
})->group('SCM-001');

test('what would be committed can be read as a diff for writing a commit message', function () {
    ($this->write)('a.txt', "old\n");
    ($this->write)('b.txt', "b\n");
    ($this->data)(['op' => 'commit', 'message' => 'Start']);
    ($this->write)('a.txt', "new\n");
    ($this->write)('b.txt', "bb\n");
    ($this->write)('new.txt', 'new');

    $all = ($this->data)(['op' => 'changes_diff']);
    ($this->data)(['op' => 'stage', 'paths' => ['a.txt']]);
    $staged = ($this->data)(['op' => 'changes_diff']);

    expect($all['patch'])->toContain('+new')->toContain('+bb')
        ->and($all['new_files'])->toBe(['new.txt'])
        ->and($all['truncated'])->toBeFalse()
        ->and($staged['patch'])->toContain('+new')->not->toContain('+bb')
        ->and($staged['new_files'])->toBe([]);
})->group('GIT-006', 'SCM-001');

test('committing with nothing changed or no message is refused', function () {
    ($this->write)('index.html', 'hi');
    ($this->data)(['op' => 'commit', 'message' => 'Start']);

    expect(($this->tool)(['op' => 'commit', 'message' => 'Again']))->toBe(['ok' => false, 'error' => "There's nothing to commit."])
        ->and(($this->tool)(['op' => 'commit', 'message' => ' ']))->toBe(['ok' => false, 'error' => 'Write a message for the commit.']);
})->group('GIT-002');

test('unstaged changes to some files or all of them are discarded, keeping staged changes and ignored files', function () {
    ($this->write)('a.txt', 'a');
    ($this->write)('b.txt', 'b');
    ($this->write)('c.txt', 'c');
    ($this->data)(['op' => 'commit', 'message' => 'Start']);
    ($this->write)('a.txt', 'changed');
    ($this->write)('b.txt', 'changed');
    ($this->write)('c.txt', 'staged');
    ($this->data)(['op' => 'stage', 'paths' => ['c.txt']]);
    ($this->write)('c.txt', 'staged, then changed');
    ($this->write)('new.txt', 'new');
    ($this->write)('.env', 'SECRET=1');

    ($this->data)(['op' => 'discard', 'paths' => ['a.txt']]);
    expect(File::get("{$this->workspace}/a.txt"))->toBe('a')
        ->and(File::get("{$this->workspace}/b.txt"))->toBe('changed');

    $status = ($this->data)(['op' => 'discard']);

    expect($status['changes'])->toBe([])
        ->and(array_column($status['staged'], 'path'))->toBe(['c.txt'])
        ->and(File::get("{$this->workspace}/c.txt"))->toBe('staged')
        ->and(File::get("{$this->workspace}/b.txt"))->toBe('b')
        ->and(File::exists("{$this->workspace}/new.txt"))->toBeFalse()
        ->and(File::get("{$this->workspace}/.env"))->toBe('SECRET=1')
        ->and(($this->tool)(['op' => 'discard', 'paths' => ['../outside']])['error'])->toBe('Some of those files have no changes.')
        // What was thrown away is in the Timeline.
        ->and(($this->data)(['op' => 'checkpoints'])['checkpoints'][0]['subject'])->toBe('Before discarding changes')
        ->and(($this->git)('show', 'refs/onedrop/checkpoints:new.txt'))->toBe('new');
})->group('GIT-002', 'SCM-001');

test('branches can be created and switched, and bad names are refused', function () {
    ($this->write)('a.txt', 'a');
    ($this->data)(['op' => 'commit', 'message' => 'Start']);

    expect(($this->data)(['op' => 'switch', 'branch' => 'feature/pricing', 'create' => true])['branch'])->toBe('feature/pricing')
        ->and(($this->data)(['op' => 'switch', 'branch' => 'main'])['branches'])->toBe(['feature/pricing', 'main'])
        ->and(($this->tool)(['op' => 'switch', 'branch' => '--force'])['error'])->toBe("That isn't a valid branch name.");
})->group('GIT-002');

test('restoring an earlier version is a new commit, with what was uncommitted kept in a checkpoint', function () {
    ($this->write)('a.txt', 'first');
    ($this->data)(['op' => 'commit', 'message' => 'First']);
    $first = ($this->git)('rev-parse', 'HEAD');
    ($this->write)('a.txt', 'second');
    ($this->write)('b.txt', 'added later');
    ($this->data)(['op' => 'commit', 'message' => 'Second']);
    ($this->write)('a.txt', 'uncommitted');
    ($this->write)('c.txt', 'new and uncommitted');

    $status = ($this->data)(['op' => 'restore', 'sha' => $first, 'name' => 'Dev User', 'email' => 'dev@example.com']);
    $checkpoint = ($this->data)(['op' => 'checkpoints'])['checkpoints'][0];

    expect(File::get("{$this->workspace}/a.txt"))->toBe('first')
        ->and(File::exists("{$this->workspace}/b.txt"))->toBeFalse()
        ->and(File::exists("{$this->workspace}/c.txt"))->toBeFalse()
        ->and($status['changes'])->toBe([])
        ->and(($this->git)('log', '--format=%an|%s'))->toBe(implode("\n", [
            'Dev User|Restore "First" ('.substr($first, 0, 7).')',
            'OneDrop|Second',
            'OneDrop|First',
        ]))
        ->and($checkpoint)->toMatchArray(['subject' => 'Before restoring '.substr($first, 0, 7), 'kind' => 'edits'])
        ->and(($this->git)('show', "{$checkpoint['sha']}:c.txt"))->toBe('new and uncommitted')
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
    $remote = sys_get_temp_dir().'/onedrop-git-remote-'.uniqid();
    Process::run(['git', 'clone', '-q', $this->workspace, $remote])->throw();
    Process::path($remote)->run('echo c > c.txt && git add c.txt && git -c user.name=Me -c user.email=me@example.com commit -q -m "From the remote" && git bundle create -q /tmp/onedrop-test-pull.bundle main')->throw();

    $status = ($this->data)(['op' => 'pulled', 'branch' => 'main', 'bundle' => '/tmp/onedrop-test-pull.bundle']);

    expect(File::get("{$this->workspace}/c.txt"))->toBe("c\n")
        ->and($status['tracking'])->toBe(['ahead' => 0, 'behind' => 0])
        ->and(File::exists('/tmp/onedrop-test-pull.bundle'))->toBeFalse();

    // Both sides move on: refused, for the agent to merge.
    ($this->write)('a.txt', 'local again');
    ($this->data)(['op' => 'commit', 'message' => 'Local again']);
    Process::path($remote)->run('echo d > d.txt && git add d.txt && git -c user.name=Me -c user.email=me@example.com commit -q -m "Remote again" && git bundle create -q /tmp/onedrop-test-pull.bundle main')->throw();

    expect(($this->tool)(['op' => 'pulled', 'branch' => 'main', 'bundle' => '/tmp/onedrop-test-pull.bundle'])['error'])
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
    $remote = sys_get_temp_dir().'/onedrop-git-import-'.uniqid();
    File::ensureDirectoryExists($remote);
    Process::path($remote)->run('git init -q -b trunk && echo hi > README.md && git add . && git -c user.name=Me -c user.email=me@example.com commit -q -m "Imported" && git bundle create -q /tmp/onedrop-test-import.bundle trunk')->throw();

    $status = ($this->data)(['op' => 'pulled', 'branch' => 'trunk', 'bundle' => '/tmp/onedrop-test-import.bundle']);

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

test('one uncommitted change can be read as a diff: edits, new files, binary files and new folders', function () {
    ($this->write)('a.txt', "old\nsame\n");
    ($this->write)('logo.png', "\x89PNG\0");
    ($this->data)(['op' => 'commit', 'message' => 'Start']);
    ($this->write)('a.txt', "new\nsame\n");
    ($this->write)('logo.png', "\x89PNG\0\0");
    ($this->write)('new.txt', "one\ntwo");
    File::ensureDirectoryExists("{$this->workspace}/docs");
    ($this->write)('docs/one.md', 'one');
    ($this->write)('docs/two.md', 'two');

    $edited = ($this->data)(['op' => 'change_diff', 'path' => 'a.txt']);
    $new = ($this->data)(['op' => 'change_diff', 'path' => 'new.txt']);
    ($this->data)(['op' => 'stage', 'paths' => ['a.txt']]);
    ($this->write)('a.txt', "newer\nsame\n");

    expect($edited['patch'])->toContain("-old\n+new\n same")
        ->and(($this->data)(['op' => 'change_diff', 'path' => 'a.txt', 'staged' => true])['patch'])->toContain("-old\n+new\n same")
        ->and(($this->data)(['op' => 'change_diff', 'path' => 'a.txt'])['patch'])->toContain("-new\n+newer\n same")
        ->and($new['patch'])->toBe("diff --git a/new.txt b/new.txt\nnew file mode 100644\n--- /dev/null\n+++ b/new.txt\n@@ -0,0 +1,2 @@\n+one\n+two\n\\ No newline at end of file\n")
        ->and($new['hash'])->toBe(sha1($new['patch']))
        ->and(($this->data)(['op' => 'change_diff', 'path' => 'logo.png']))->toMatchArray(['binary' => true, 'patch' => ''])
        ->and(($this->data)(['op' => 'change_diff', 'path' => 'docs/'])['files'])->toEqualCanonicalizing(['docs/one.md', 'docs/two.md'])
        ->and(($this->tool)(['op' => 'change_diff', 'path' => 'unchanged.txt']))->toBe(['ok' => false, 'error' => 'That file has no unstaged changes.'])
        ->and(($this->tool)(['op' => 'change_diff', 'path' => '../etc/passwd']))->toBe(['ok' => false, 'error' => "That isn't a file in this project."]);
})->group('GIT-006', 'SCM-001');

test('a branch is compared with its base: the commits it adds and their diff', function () {
    ($this->write)('a.txt', "one\n");
    ($this->data)(['op' => 'commit', 'message' => 'Start']);
    ($this->data)(['op' => 'switch', 'branch' => 'pricing', 'create' => true]);
    ($this->write)('a.txt', "two\n");
    ($this->data)(['op' => 'commit', 'message' => 'Add pricing']);
    ($this->write)('b.txt', "plans\n");
    ($this->data)(['op' => 'commit', 'message' => 'Add plans']);

    $compared = ($this->data)(['op' => 'compare', 'base' => 'main']);

    expect(array_column($compared['commits'], 'subject'))->toBe(['Add plans', 'Add pricing'])
        ->and($compared['patch'])->toContain('+two')->toContain('+plans')
        ->and($compared['more'])->toBeFalse()
        ->and(($this->tool)(['op' => 'compare', 'base' => 'nowhere']))->toBe(['ok' => false, 'error' => "That base branch isn't in this project."]);
})->group('GIT-007');

test('the commits no remote has yet are counted and combined into one, keeping the files', function () {
    ($this->write)('a.txt', "one\n");
    ($this->data)(['op' => 'commit', 'message' => 'Start']);

    expect(($this->data)(['op' => 'status'])['unpushed'])->toBeNull()
        ->and(($this->tool)(['op' => 'combine_preview'])['error'])->toBe('Push the branch once first; then commits made after that can be combined.');

    $pushed = ($this->git)('rev-parse', 'HEAD');
    ($this->data)(['op' => 'pushed', 'branch' => 'main', 'sha' => $pushed]);
    ($this->write)('a.txt', "two\n");
    ($this->data)(['op' => 'commit', 'message' => 'Make it two']);

    expect(($this->tool)(['op' => 'combine_preview'])['error'])->toBe("There's only one commit to push, so there's nothing to combine.");

    ($this->write)('b.txt', "bee\n");
    ($this->data)(['op' => 'commit', 'message' => 'Add a bee']);
    $preview = ($this->data)(['op' => 'combine_preview']);

    expect(($this->data)(['op' => 'status'])['unpushed'])->toBe(2)
        ->and(array_column($preview['commits'], 'subject'))->toBe(['Add a bee', 'Make it two'])
        ->and($preview['patch'])->toContain('+two')->toContain('+bee');

    ($this->write)('c.txt', 'staged');
    ($this->data)(['op' => 'stage', 'paths' => ['c.txt']]);
    expect(($this->tool)(['op' => 'combine', 'message' => 'Both']))->toBe(['ok' => false, 'error' => 'Commit or unstage your staged changes first.']);
    ($this->data)(['op' => 'unstage']);

    $status = ($this->data)(['op' => 'combine', 'message' => 'Two and a bee', 'name' => 'Dev User', 'email' => 'dev@example.com']);

    expect($status['unpushed'])->toBe(1)
        ->and(array_column($status['changes'], 'path'))->toBe(['c.txt'])
        ->and(($this->git)('log', '--format=%s|%an', "{$pushed}..HEAD"))->toBe('Two and a bee|Dev User')
        ->and(($this->git)('rev-parse', 'HEAD^'))->toBe($pushed)
        ->and(File::get("{$this->workspace}/a.txt"))->toBe("two\n")
        ->and(File::get("{$this->workspace}/b.txt"))->toBe("bee\n");
})->group('GIT-008');

/**
 * A 20-line file committed, then edited near the top (line 2) and the bottom (line 19): two hunks.
 */
function twoHunks(object $test): array
{
    $lines = array_map(fn (int $n) => "line {$n}", range(1, 20));
    ($test->write)('a.txt', implode("\n", $lines)."\n");
    ($test->data)(['op' => 'commit', 'message' => 'Start']);
    $lines[1] = 'line 2 changed';
    $lines[18] = 'line 19 changed';
    ($test->write)('a.txt', implode("\n", $lines)."\n");

    return ($test->data)(['op' => 'change_diff', 'path' => 'a.txt']);
}

/**
 * The indexes of a patch's lines that are exactly $wanted.
 *
 * @return list<int>
 */
function patchLines(string $patch, string ...$wanted): array
{
    return array_keys(array_filter(explode("\n", $patch), fn (string $line) => in_array($line, $wanted, true)));
}

test('one hunk is staged, then unstaged, leaving the file as it is', function () {
    $diff = twoHunks($this);

    $status = ($this->data)(['op' => 'stage_lines', 'path' => 'a.txt', 'hash' => $diff['hash'], 'hunk' => 0]);

    expect(($this->git)('show', ':a.txt'))->toContain('line 2 changed')->not->toContain('line 19 changed')
        ->and($status['staged'][0])->toMatchArray(['path' => 'a.txt', 'additions' => 1, 'deletions' => 1])
        ->and($status['changes'][0])->toMatchArray(['path' => 'a.txt', 'additions' => 1, 'deletions' => 1]);

    $staged = ($this->data)(['op' => 'change_diff', 'path' => 'a.txt', 'staged' => true]);
    $status = ($this->data)(['op' => 'stage_lines', 'path' => 'a.txt', 'hash' => $staged['hash'], 'hunk' => 0, 'staged' => true]);

    expect($status['staged'])->toBe([])
        ->and(File::get("{$this->workspace}/a.txt"))->toContain('line 2 changed')->toContain('line 19 changed');
})->group('GIT-009', 'SCM-001');

test('single lines are staged and unstaged: the others stay as they are', function () {
    ($this->write)('a.txt', "keep\nold\n");
    ($this->data)(['op' => 'commit', 'message' => 'Start']);
    ($this->write)('a.txt', "keep\nnew one\nnew two\n");
    $diff = ($this->data)(['op' => 'change_diff', 'path' => 'a.txt']);

    ($this->data)(['op' => 'stage_lines', 'path' => 'a.txt', 'hash' => $diff['hash'], 'lines' => patchLines($diff['patch'], '+new one')]);

    expect(($this->git)('show', ':a.txt'))->toBe("keep\nold\nnew one");

    ($this->data)(['op' => 'stage', 'paths' => ['a.txt']]);
    $staged = ($this->data)(['op' => 'change_diff', 'path' => 'a.txt', 'staged' => true]);
    ($this->data)(['op' => 'stage_lines', 'path' => 'a.txt', 'hash' => $staged['hash'], 'staged' => true, 'lines' => patchLines($staged['patch'], '-old', '+new two')]);

    expect(($this->git)('show', ':a.txt'))->toBe("keep\nold\nnew one")
        ->and(File::get("{$this->workspace}/a.txt"))->toBe("keep\nnew one\nnew two\n");
})->group('GIT-009', 'SCM-001');

test('parts of a file that changed since it was shown, or of new files, are refused', function () {
    $diff = twoHunks($this);
    ($this->write)('a.txt', "something else\n");
    ($this->write)('new.txt', "one\n");
    $new = ($this->data)(['op' => 'change_diff', 'path' => 'new.txt']);

    expect(($this->tool)(['op' => 'stage_lines', 'path' => 'a.txt', 'hash' => $diff['hash'], 'hunk' => 0]))
        ->toBe(['ok' => false, 'error' => 'This file changed. Review it again.'])
        ->and(($this->tool)(['op' => 'stage_lines', 'path' => 'new.txt', 'hash' => $new['hash'], 'hunk' => 0]))
        ->toBe(['ok' => false, 'error' => 'Only parts of edited text files can be staged.']);
})->group('GIT-009', 'SCM-001');

test('one hunk is discarded, putting just that part back', function () {
    $diff = twoHunks($this);

    $status = ($this->data)(['op' => 'discard_hunk', 'path' => 'a.txt', 'hash' => $diff['hash'], 'hunk' => 0]);

    expect(File::get("{$this->workspace}/a.txt"))->toContain("line 2\n")->toContain('line 19 changed')
        ->and($status['changes'][0])->toMatchArray(['additions' => 1, 'deletions' => 1])
        ->and(($this->tool)(['op' => 'discard_hunk', 'path' => 'a.txt', 'hash' => $diff['hash'], 'hunk' => 1]))
        ->toBe(['ok' => false, 'error' => 'This file changed. Review it again.']);
})->group('GIT-009');

test('the last commit is undone, its changes coming back uncommitted, unless it is pushed', function () {
    ($this->write)('a.txt', "one\n");
    ($this->data)(['op' => 'commit', 'message' => 'Start']);

    expect(($this->tool)(['op' => 'undo_commit', 'sha' => ($this->git)('rev-parse', 'HEAD')])['error'])->toBe("That's the first commit, so there's nothing before it to go back to.");

    $start = ($this->git)('rev-parse', 'HEAD');
    ($this->write)('a.txt', "two\n");
    ($this->write)('b.txt', "bee\n");
    ($this->data)(['op' => 'commit', 'message' => 'Oops']);
    $oops = ($this->git)('rev-parse', 'HEAD');

    expect(($this->tool)(['op' => 'undo_commit', 'sha' => $start])['error'])->toBe('The last commit changed. Look again before undoing it.');

    $status = ($this->data)(['op' => 'undo_commit', 'sha' => substr($oops, 0, 12)]);

    expect(($this->git)('rev-parse', 'HEAD'))->toBe($start)
        ->and($status['changes'])->toEqualCanonicalizing([
            ['path' => 'a.txt', 'status' => 'M', 'additions' => 1, 'deletions' => 1, 'binary' => false],
            ['path' => 'b.txt', 'status' => '?', 'additions' => 1, 'deletions' => 0, 'binary' => false],
        ])
        ->and(File::get("{$this->workspace}/a.txt"))->toBe("two\n");

    ($this->data)(['op' => 'commit', 'message' => 'Again']);
    $again = ($this->git)('rev-parse', 'HEAD');
    ($this->data)(['op' => 'pushed', 'branch' => 'main', 'sha' => $again]);

    expect(($this->tool)(['op' => 'undo_commit', 'sha' => $again])['error'])->toStartWith('That commit is already pushed');
})->group('GIT-010');

/**
 * Run the checkpoint script in the workspace as the agent's turn would, with $message as its prompt.
 */
function agentTurn(object $test, string $message, bool $commit = false): void
{
    Process::path($test->workspace)
        ->env(['ONEDROP_WORKSPACE' => $test->workspace, 'ONEDROP_COMMIT_TURNS' => $commit ? '1' : '0'])
        ->input($message)
        ->run([dirname(__DIR__, 2).'/docker/sandbox/checkpoint']);
}

test('the agent\'s turns are listed as checkpoints, newest first, with edits made between them', function () {
    ($this->write)('a.txt', "one\n");
    ($this->data)(['op' => 'commit', 'message' => 'Start']);
    ($this->write)('a.txt', "two\n");
    agentTurn($this, 'Make it two');
    ($this->write)('b.txt', "bee\n");
    Process::path($this->workspace)->env(['ONEDROP_WORKSPACE' => $this->workspace])->run([dirname(__DIR__, 2).'/docker/sandbox/checkpoint', '--snapshot']);
    ($this->write)('a.txt', "three\nfour\n");
    agentTurn($this, 'Make it four');

    $checkpoints = ($this->data)(['op' => 'checkpoints']);

    expect($checkpoints['more'])->toBeFalse()
        ->and(array_map(fn (array $checkpoint) => [$checkpoint['subject'], $checkpoint['kind'], $checkpoint['files'], $checkpoint['additions'], $checkpoint['deletions']], $checkpoints['checkpoints']))->toBe([
            ['Make it four', 'turn', 1, 2, 1],
            ['Changes outside the agent', 'edits', 1, 1, 0],
            ['Make it two', 'turn', 1, 1, 1],
        ])
        ->and(($this->git)('log', '--format=%s'))->toBe('Start')
        ->and(($this->data)(['op' => 'show', 'sha' => $checkpoints['checkpoints'][0]['sha']]))->toMatchArray(['subject' => 'Make it four', 'body' => '', 'agent' => true]);
})->group('SBX-006', 'SCM-002');

test('a project with no history yet still gets checkpoints, and has none before the first', function () {
    expect(($this->data)(['op' => 'checkpoints']))->toBe(['checkpoints' => [], 'more' => false]);

    ($this->write)('a.txt', 'a');
    agentTurn($this, 'Start');

    expect(($this->data)(['op' => 'checkpoints'])['checkpoints'][0])->toMatchArray(['subject' => 'Start', 'restorable_before' => false]);
})->group('SCM-002');

test('a checkpoint is restored, or the files as they were before it, without committing or touching what is staged', function () {
    ($this->write)('a.txt', "one\n");
    ($this->data)(['op' => 'commit', 'message' => 'Start']);
    ($this->write)('a.txt', "two\n");
    ($this->write)('b.txt', "bee\n");
    agentTurn($this, 'Two and a bee');
    $turn = ($this->data)(['op' => 'checkpoints'])['checkpoints'][0]['sha'];
    ($this->write)('a.txt', "three\n");
    ($this->write)('c.txt', "sea\n");
    ($this->data)(['op' => 'stage', 'paths' => ['c.txt']]);

    $status = ($this->data)(['op' => 'restore_checkpoint', 'sha' => $turn, 'before' => true]);

    expect(File::get("{$this->workspace}/a.txt"))->toBe("one\n")
        ->and(File::exists("{$this->workspace}/b.txt"))->toBeFalse()
        ->and(File::exists("{$this->workspace}/c.txt"))->toBeFalse()
        ->and(array_column($status['staged'], 'path'))->toBe(['c.txt'])
        ->and(($this->git)('log', '--format=%s'))->toBe('Start');

    $checkpoints = ($this->data)(['op' => 'checkpoints'])['checkpoints'];

    expect(array_column($checkpoints, 'kind'))->toBe(['restore', 'edits', 'turn'])
        ->and($checkpoints[0]['subject'])->toBe('Restore before "Two and a bee"');

    // The restore itself is undone from the checkpoint before it.
    ($this->data)(['op' => 'restore_checkpoint', 'sha' => $checkpoints[1]['sha']]);

    expect(File::get("{$this->workspace}/a.txt"))->toBe("three\n")
        ->and(File::get("{$this->workspace}/c.txt"))->toBe("sea\n")
        ->and(($this->tool)(['op' => 'restore_checkpoint', 'sha' => ($this->git)('rev-parse', 'HEAD')])['error'])->toBe("That isn't one of the project's checkpoints.");
})->group('SCM-002');
