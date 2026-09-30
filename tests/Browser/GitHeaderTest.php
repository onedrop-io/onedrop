<?php

use App\Jobs\SyncGitRemote;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Agents\OneOffPrompt;
use App\Sandbox\ExecResult;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use Illuminate\Support\Facades\Queue;

/** A stylesheet edited in two places: lines 1–3 (index 4–8) and 20–22 (index 10–13). */
const GIT_HEADER_TWO_HUNKS = "diff --git a/resources/css/app.css b/resources/css/app.css\nindex 1111111..2222222 100644\n--- a/resources/css/app.css\n+++ b/resources/css/app.css\n@@ -1,3 +1,4 @@\n .timer {\n-  color: black;\n+  color: blue;\n+  font-weight: bold;\n@@ -20,2 +21,2 @@ .button {\n   padding: 4px;\n-  margin: 0;\n+  margin: 8px;\n";

/** A stylesheet whose only change is indentation. */
const GIT_HEADER_SPACING = "diff --git a/resources/css/spacing.css b/resources/css/spacing.css\n--- a/resources/css/spacing.css\n+++ b/resources/css/spacing.css\n@@ -3,3 +3,3 @@\n .grid {\n-  gap: 2px;\n+    gap: 2px;\n }\n";

/**
 * The dev user's project, whose sandbox's git reports $changes and $tracking, and records each request in $requests.
 *
 * @param  list<array{path: string, status: string}>  $changes
 * @param  array{ahead: int, behind: int}|null  $tracking
 * @param  list<array<string, mixed>>  $requests
 */
function gitHeaderProject(array $changes, ?array $tracking, array &$requests, ?string $remote = null, string $branch = 'main', ?int $unpushed = null): Project
{
    $commits = [
        ['sha' => str_repeat('b', 40), 'subject' => 'Make the timer blue', 'author' => 'OneDrop', 'email' => 'agent@onedrop.io', 'date' => now()->toIso8601String(), 'agent' => true],
        ['sha' => str_repeat('a', 40), 'subject' => 'Build a timer', 'author' => 'OneDrop', 'email' => 'agent@onedrop.io', 'date' => now()->toIso8601String(), 'agent' => true],
    ];
    $provider = new FakeSandboxProvider;
    $provider->execUsing = function (array $command, array $env) use (&$changes, &$requests, &$branch, &$unpushed, &$commits, $tracking) {
        if (! isset($env['APP_GIT_REQUEST'])) {
            return new ExecResult(0, '');
        }

        $request = json_decode($env['APP_GIT_REQUEST'], true);
        $requests[] = $request;

        if ($request['op'] === 'switch') {
            $branch = $request['branch'];
        }

        if ($request['op'] === 'discard_hunk') {
            $changes = array_map(fn (array $change) => $change['path'] === $request['path'] ? [...$change, 'additions' => 1, 'deletions' => 1] : $change, $changes);
        }

        if ($request['op'] === 'undo_commit') {
            array_shift($commits);
        }

        if ($request['op'] === 'combine') {
            $unpushed = 1;
        }

        if ($request['op'] === 'commit') {
            $changes = array_values(array_filter($changes, fn (array $change) => $request['paths'] !== null && ! in_array($change['path'], $request['paths'], true)));
        }

        $data = match ($request['op']) {
            'log' => ['commits' => $commits, 'more' => false],
            'combine_preview' => ['commits' => [
                ['sha' => str_repeat('c', 40), 'subject' => 'Add plans', 'author' => 'OneDrop', 'email' => 'agent@onedrop.io', 'date' => now()->toIso8601String(), 'agent' => true],
                ['sha' => str_repeat('b', 40), 'subject' => 'Add a pricing page', 'author' => 'OneDrop', 'email' => 'agent@onedrop.io', 'date' => now()->toIso8601String(), 'agent' => true],
            ], 'patch' => '+plans', 'truncated' => false],
            'compare' => ['base' => $request['base'], 'commits' => [['sha' => str_repeat('b', 40), 'subject' => 'Add a pricing page', 'author' => 'OneDrop', 'email' => 'agent@onedrop.io', 'date' => now()->toIso8601String(), 'agent' => true]], 'more' => false, 'patch' => '+plans', 'truncated' => false],
            'changes_diff' => ['patch' => '+color: blue;', 'new_files' => [], 'truncated' => false],
            'change_diff' => ['path' => $request['path'], 'truncated' => false, 'files' => null, ...match ($request['path']) {
                'public/logo.png' => ['patch' => '', 'binary' => true],
                'resources/css/app.css' => ['patch' => GIT_HEADER_TWO_HUNKS, 'hash' => sha1(GIT_HEADER_TWO_HUNKS), 'binary' => false],
                'resources/css/spacing.css' => ['patch' => GIT_HEADER_SPACING, 'hash' => sha1(GIT_HEADER_SPACING), 'binary' => false],
                default => ['patch' => "diff --git a/x b/x\n@@ -1,2 +1,2 @@\n const start = 0;\n-color: black;\n+color: blue;\n", 'binary' => false],
            }],
            default => [
                'initialized' => true, 'branch' => $branch, 'branches' => array_values(array_unique(['main', $branch])), 'head' => str_repeat('a', 40),
                'changes' => $changes, 'more_changes' => false, 'tracking' => $tracking, 'unpushed' => $unpushed, 'state' => null,
            ],
        };

        return new ExecResult(0, json_encode(['ok' => true, 'data' => $data]));
    };
    app()->instance(SandboxProvider::class, $provider);

    $user = User::factory()->has(AgentConnection::factory())->create(['name' => 'Dev User', 'email' => 'dev@example.com']);
    $project = Project::factory()->for($user)->create($remote ? ['git_remote_url' => $remote, 'git_remote_token' => 'secret-token'] : []);
    Sandbox::factory()->for($project)->create(['preview_url' => null]);
    test()->actingAs($user);

    return $project;
}

test('the header commits and pushes the changes as the dev user', function () {
    Queue::fake();
    $requests = [];
    $project = gitHeaderProject([
        ['path' => 'resources/js/app.tsx', 'status' => 'M', 'additions' => 3, 'deletions' => 1, 'binary' => false],
        ['path' => 'public/logo.png', 'status' => '?', 'additions' => null, 'deletions' => null, 'binary' => true],
    ], ['ahead' => 0, 'behind' => 0], $requests, 'https://github.com/dev/timer.git');

    visit("/projects/{$project->id}")
        ->resize(1500, 1000)
        ->assertSeeIn('@git-actions-primary', 'Commit & push')
        ->click('@git-actions-primary')
        ->assertVisible('@git-actions-dialog')
        ->assertScript('document.activeElement?.dataset.test', 'git-actions-message')
        ->assertSeeIn('@git-actions-branch', 'main')
        ->assertVisible('@git-actions-default-branch')
        ->assertSeeIn('@git-actions-files', 'app.tsx')
        ->assertSeeIn('@git-actions-files', '+3 −1')
        ->assertSeeIn('@git-actions-files', 'binary')
        ->assertSeeIn('@git-actions-totals', '+3 −1')
        ->type('@git-actions-message', 'Tweak the start button')
        ->click('@git-actions-submit')
        ->assertMissing('@git-actions-dialog')
        ->assertSeeIn('@git-actions-primary', 'Pushing…')
        ->assertNoJavaScriptErrors();

    expect(collect($requests)->firstWhere('op', 'commit'))->toMatchArray(['message' => 'Tweak the start button', 'paths' => null]);
    Queue::assertPushed(SyncGitRemote::class);
})->group('GIT-006');

test('some of the files are committed on a new branch with a message the AI writes', function () {
    Queue::fake();
    $requests = [];
    $project = gitHeaderProject([
        ['path' => 'resources/js/app.tsx', 'status' => 'M', 'additions' => 3, 'deletions' => 1, 'binary' => false],
        ['path' => 'notes.txt', 'status' => '?', 'additions' => 10, 'deletions' => 0, 'binary' => false],
    ], null, $requests);
    $ai = Mockery::mock(OneOffPrompt::class);
    $ai->shouldReceive('ask')->andReturn('Make the start button blue');
    app()->instance(OneOffPrompt::class, $ai);

    visit("/projects/{$project->id}")
        ->resize(1500, 1000)
        ->assertSeeIn('@git-actions-primary', 'Commit')
        ->click('@git-actions-primary')
        ->assertSeeIn('@git-actions-totals', '+13 −1')
        ->click('@git-actions-edit-files')
        ->click('[data-test="git-actions-file"]:has-text("notes.txt") [data-test="git-actions-file-toggle"]')
        ->assertSeeIn('@git-actions-file-count', '1 of 2')
        ->assertSeeIn('@git-actions-totals', '+3 −1')
        ->click('@git-actions-on-new-branch')
        ->assertScript('document.activeElement?.dataset.test', 'git-actions-new-branch')
        ->type('@git-actions-new-branch', 'blue-button')
        ->assertSeeIn('@git-actions-submit', 'Commit to new branch')
        ->click('@git-actions-submit')
        ->assertMissing('@git-actions-dialog')
        ->assertSee('Committed “Make the start button blue”')
        ->assertNoJavaScriptErrors();

    expect(collect($requests)->firstWhere('op', 'switch'))->toMatchArray(['branch' => 'blue-button', 'create' => true])
        ->and(collect($requests)->firstWhere('op', 'commit'))->toMatchArray(['message' => 'Make the start button blue', 'paths' => ['resources/js/app.tsx']]);
    Queue::assertNotPushed(SyncGitRemote::class);
})->group('GIT-006');

test('on the default branch, Create PR asks for a new branch, and without a remote the menu opens Tools → Git', function () {
    $requests = [];
    $project = gitHeaderProject([], ['ahead' => 0, 'behind' => 0], $requests, 'https://github.com/dev/timer.git');

    visit("/projects/{$project->id}")
        ->resize(1500, 1000)
        ->assertAttribute('@git-actions-primary', 'disabled', '')
        ->click('@git-actions-menu')
        ->assertAttribute('@git-actions-commit', 'data-disabled', '')
        ->assertAttribute('@git-actions-push', 'data-disabled', '')
        ->assertAttribute('@git-actions-pr', 'data-disabled', '')
        ->assertSeeIn('@git-actions-pr-problem', 'Commit on a new branch first');

    $project->update(['git_remote_url' => null, 'git_remote_token' => null]);

    visit("/projects/{$project->id}")
        ->resize(1500, 1000)
        ->assertSeeIn('@git-actions-primary', 'Commit')
        ->click('@git-actions-menu')
        ->assertSeeIn('@git-actions-pr-problem', 'Needs a GitHub repository')
        ->click('@git-actions-push')
        ->assertVisible('@git-panel')
        ->assertVisible('@git-no-remote')
        ->assertNoJavaScriptErrors();
})->group('GIT-006', 'GIT-007');

test('a pushed branch opens a pull request on GitHub with the title and description the AI wrote', function () {
    $requests = [];
    $project = gitHeaderProject([], ['ahead' => 0, 'behind' => 0], $requests, 'https://github.com/dev/timer.git', 'pricing');
    $ai = Mockery::mock(OneOffPrompt::class);
    $ai->shouldReceive('ask')->andReturn("Title: Add a pricing page\n\nAdds plans.");
    app()->instance(OneOffPrompt::class, $ai);

    $page = visit("/projects/{$project->id}")->resize(1500, 1000);
    $page->script('window.open = (url) => { window.openedUrl = url; }');
    $page->click('@git-actions-menu')
        ->assertMissing('@git-actions-pr-problem')
        ->click('@git-actions-pr')
        ->assertVisible('@git-pr-dialog')
        ->assertSeeIn('@git-pr-base', 'main')
        ->assertValue('@git-pr-title', 'Add a pricing page')
        ->assertValue('@git-pr-body', 'Adds plans.')
        ->assertSeeIn('@git-pr-commits', '1 commit')
        ->assertScript('document.activeElement?.dataset.test', 'git-pr-title')
        ->type('@git-pr-title', 'Pricing page')
        ->click('@git-pr-open')
        ->assertMissing('@git-pr-dialog')
        ->assertScript('window.openedUrl', 'https://github.com/dev/timer/compare/main...pricing?expand=1&title=Pricing+page&body=Adds+plans.')
        ->assertNoJavaScriptErrors();

    expect(collect($requests)->firstWhere('op', 'compare')['base'])->toBe('main');
})->group('GIT-007');

test('clicking a file shows its diff beside the list, and the arrow keys move between files', function () {
    $requests = [];
    $project = gitHeaderProject([
        ['path' => 'resources/js/app.tsx', 'status' => 'M', 'additions' => 1, 'deletions' => 1, 'binary' => false],
        ['path' => 'public/logo.png', 'status' => '?', 'additions' => null, 'deletions' => null, 'binary' => true],
    ], null, $requests);

    visit("/projects/{$project->id}")
        ->resize(1500, 1000)
        ->click('@git-actions-primary')
        ->assertMissing('@git-actions-diff')
        ->click('[data-test="git-actions-file"]:first-child [data-test="git-actions-file-open"]')
        ->assertSeeIn('@git-actions-diff-path', 'resources/js/app.tsx')
        ->assertSeeIn('@git-diff', '+color: blue;')
        ->assertDontSeeIn('@git-diff', 'diff --git')
        ->keys('[data-test="git-actions-file"]:first-child [data-test="git-actions-file-open"]', 'ArrowDown')
        ->assertSeeIn('@git-actions-diff-path', 'public/logo.png')
        ->assertSeeIn('@git-actions-diff', 'Binary file')
        ->keys('[data-test="git-actions-file"]:last-child [data-test="git-actions-file-open"]', 'Escape')
        ->assertMissing('@git-actions-diff')
        ->assertVisible('@git-actions-dialog')
        ->assertNoJavaScriptErrors();

    expect(collect($requests)->where('op', 'change_diff')->pluck('path')->all())->toBe(['resources/js/app.tsx', 'public/logo.png']);
})->group('GIT-006');

test('the commits waiting to be pushed are combined into one with a message the AI wrote', function () {
    $requests = [];
    $project = gitHeaderProject([], ['ahead' => 2, 'behind' => 0], $requests, 'https://github.com/dev/timer.git', 'main', 2);
    $ai = Mockery::mock(OneOffPrompt::class);
    $ai->shouldReceive('ask')->andReturn('Add a pricing page with plans');
    app()->instance(OneOffPrompt::class, $ai);

    visit("/projects/{$project->id}")
        ->resize(1500, 1000)
        ->click('@git-actions-menu')
        ->assertSeeIn('@git-actions-combine', 'Combine 2 commits')
        ->click('@git-actions-combine')
        ->assertVisible('@git-combine-dialog')
        ->assertSeeIn('@git-combine-commits', 'Add a pricing page')
        ->assertValue('@git-combine-message', "Add a pricing page with plans\n\n- Add a pricing page\n- Add plans")
        ->assertScript('document.activeElement?.dataset.test', 'git-combine-message')
        ->click('@git-combine-submit')
        ->assertMissing('@git-combine-dialog')
        ->assertSee('Combined 2 commits')
        ->click('@git-actions-menu')
        ->assertMissing('@git-actions-combine')
        ->assertNoJavaScriptErrors();

    expect(collect($requests)->firstWhere('op', 'combine')['message'])->toBe("Add a pricing page with plans\n\n- Add a pricing page\n- Add plans");
})->group('GIT-008');

test('lines and parts of a file are left out of the commit, and a part is discarded', function () {
    Queue::fake();
    $requests = [];
    $project = gitHeaderProject([
        ['path' => 'resources/css/app.css', 'status' => 'M', 'additions' => 3, 'deletions' => 2, 'binary' => false],
        ['path' => 'resources/js/app.tsx', 'status' => 'M', 'additions' => 1, 'deletions' => 1, 'binary' => false],
    ], null, $requests);

    visit("/projects/{$project->id}")
        ->resize(1500, 1000)
        ->click('@git-actions-primary')
        ->assertSeeIn('@git-actions-totals', '+4 −3')
        ->click('[data-test="git-actions-file"]:first-child [data-test="git-actions-file-open"]')
        ->assertCount('@git-actions-hunk', 2)
        ->click('[data-test="git-actions-line"]:has-text("font-weight: bold;")')
        ->assertSeeIn('[data-test="git-actions-file"]:first-child', 'part')
        ->assertSeeIn('[data-test="git-actions-file"]:first-child', '+2 −2')
        ->click('[data-test="git-actions-hunk"]:last-child [data-test="git-actions-hunk-toggle"]')
        ->assertSeeIn('[data-test="git-actions-file"]:first-child', '+1 −1')
        ->assertSeeIn('@git-actions-totals', '+2 −2')
        ->assertSeeIn('@git-actions-file-count', '2 of 2')
        ->click('[data-test="git-actions-hunk"]:first-child [data-test="git-actions-hunk-discard"]')
        ->assertSee('Discard this part?')
        ->click('@git-actions-hunk-discard-confirm')
        ->assertSee('Discarded that part')
        ->assertSeeIn('[data-test="git-actions-file"]:first-child', '+1 −1')
        ->assertDontSeeIn('[data-test="git-actions-file"]:first-child', 'part')
        ->click('[data-test="git-actions-line"]:has-text("margin: 0;")')
        ->type('@git-actions-message', 'Bigger margin only')
        ->click('@git-actions-submit')
        ->assertMissing('@git-actions-dialog')
        ->assertNoJavaScriptErrors();

    expect(collect($requests)->firstWhere('op', 'discard_hunk'))->toMatchArray(['path' => 'resources/css/app.css', 'hash' => sha1(GIT_HEADER_TWO_HUNKS), 'hunk' => 0])
        ->and(collect($requests)->firstWhere('op', 'commit'))->toMatchArray([
            'message' => 'Bigger margin only',
            'paths' => ['resources/js/app.tsx'],
            'partials' => [['path' => 'resources/css/app.css', 'hash' => sha1(GIT_HEADER_TWO_HUNKS), 'excluded' => [11]]],
        ]);
})->group('GIT-009');

test('the last commit is undone from the header, after showing which one', function () {
    Queue::fake();
    $requests = [];
    $project = gitHeaderProject([], null, $requests);

    visit("/projects/{$project->id}")
        ->resize(1500, 1000)
        ->click('@git-actions-menu')
        ->assertSeeIn('@git-actions-undo', 'Make the timer blue')
        ->click('@git-actions-undo')
        ->assertSeeIn('@git-undo-commit', 'Make the timer blue')
        ->click('@git-undo-confirm')
        ->assertMissing('@git-undo-dialog')
        ->assertSee('Undid “Make the timer blue”')
        ->click('@git-actions-menu')
        ->assertAttribute('@git-actions-undo', 'data-disabled', '')
        ->assertNoJavaScriptErrors();

    expect(collect($requests)->firstWhere('op', 'undo_commit'))->toBe(['op' => 'undo_commit', 'sha' => str_repeat('b', 40)]);
})->group('GIT-010');

test('diffs show line numbers and the words that changed, hide spacing, and send a part to the chat to ask about', function () {
    $requests = [];
    $project = gitHeaderProject([
        ['path' => 'resources/css/app.css', 'status' => 'M', 'additions' => 3, 'deletions' => 2, 'binary' => false],
        ['path' => 'resources/css/spacing.css', 'status' => 'M', 'additions' => 1, 'deletions' => 1, 'binary' => false],
    ], null, $requests);

    visit("/projects/{$project->id}")
        ->resize(1500, 1000)
        ->click('@git-actions-primary')
        ->click('[data-test="git-actions-file"]:first-child [data-test="git-actions-file-open"]')
        ->assertSeeIn('[data-test="git-actions-line"]:has-text("margin: 8px;")', '22')
        ->assertSeeIn('[data-test="git-actions-line"]:has-text("color: blue;") [data-test="git-diff-word"]', 'blue')
        ->assertSeeIn('[data-test="git-actions-line"]:has-text("margin: 0;") [data-test="git-diff-word"]', '0')
        ->assertPresent('[data-test="git-diff"] [class*="tok-"]')
        ->click('[data-test="git-actions-file"]:last-child [data-test="git-actions-file-open"]')
        ->assertCount('@git-actions-line', 2)
        ->click('@git-actions-hide-whitespace')
        ->assertCount('@git-actions-line', 0)
        ->assertSeeIn('@git-diff', 'gap: 2px;')
        ->assertSee('Show whitespace to pick or discard parts.')
        ->click('[data-test="git-actions-file"]:first-child [data-test="git-actions-file-open"]')
        ->click('[data-test="git-actions-hunk"]:first-child [data-test="git-actions-hunk-ask"]')
        ->assertMissing('@git-actions-dialog')
        ->assertScript('document.activeElement?.id', 'composer-content')
        ->assertValue('#composer-content', "About this uncommitted change to `resources/css/app.css`:\n\n```diff\n@@ -1,3 +1,4 @@\n .timer {\n-  color: black;\n+  color: blue;\n+  font-weight: bold;\n```\n\n")
        ->assertNoJavaScriptErrors();
})->group('GIT-011', 'GIT-012');
