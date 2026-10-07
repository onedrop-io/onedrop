<?php

use App\Enums\SandboxStatus;
use App\Jobs\ForkTaskSandbox;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

test('pull requests are listed, read, checked out as a task, and followed from it as the dev user', function () {
    Queue::fake();
    config(['sandbox.task_copies' => true]);
    $sha = str_repeat('a', 40);

    Http::fake([
        'api.github.com/graphql' => Http::response(['data' => ['repository' => ['pullRequests' => ['nodes' => [[
            'number' => 12, 'title' => 'Fix login', 'state' => 'OPEN', 'isDraft' => false, 'url' => 'https://github.com/acme/app/pull/12',
            'updatedAt' => now()->subHour()->toIso8601String(), 'headRefName' => 'fix-login', 'baseRefName' => 'main', 'reviewDecision' => 'CHANGES_REQUESTED',
            'author' => ['login' => 'sam', 'avatarUrl' => null], 'headRepository' => ['nameWithOwner' => 'acme/app'],
            'comments' => ['totalCount' => 1], 'commits' => ['nodes' => [['commit' => ['oid' => $sha, 'statusCheckRollup' => ['state' => 'FAILURE']]]]],
        ], [
            'number' => 9, 'title' => 'Dark mode', 'state' => 'OPEN', 'isDraft' => true, 'url' => 'https://github.com/acme/app/pull/9',
            'updatedAt' => now()->subDay()->toIso8601String(), 'headRefName' => 'dark', 'baseRefName' => 'main', 'reviewDecision' => null,
            'author' => ['login' => 'dev', 'avatarUrl' => null], 'headRepository' => ['nameWithOwner' => 'acme/app'],
            'comments' => ['totalCount' => 0], 'commits' => ['nodes' => [['commit' => ['oid' => 'b', 'statusCheckRollup' => ['state' => 'SUCCESS']]]]],
        ]]]]]]),
        'api.github.com/repos/acme/app/pulls/12' => Http::response([
            'number' => 12, 'title' => 'Fix login', 'body' => 'Fixes the **login** form.', 'state' => 'open', 'draft' => false, 'merged' => false,
            'html_url' => 'https://github.com/acme/app/pull/12', 'user' => ['login' => 'sam', 'avatar_url' => null],
            'created_at' => now()->subDays(2)->toIso8601String(), 'updated_at' => now()->subHour()->toIso8601String(),
            'head' => ['ref' => 'fix-login', 'sha' => $sha, 'repo' => ['full_name' => 'acme/app']], 'base' => ['ref' => 'main'],
            'mergeable' => true, 'commits' => 1, 'additions' => 2, 'deletions' => 1, 'changed_files' => 1, 'comments' => 1, 'review_comments' => 0,
        ]),
        'api.github.com/repos/acme/app/issues/12/comments*' => Http::response([
            ['user' => ['login' => 'dev', 'avatar_url' => null], 'body' => 'Needs a test for the error.', 'created_at' => now()->subDay()->toIso8601String()],
        ]),
        'api.github.com/repos/acme/app/pulls/12/reviews*' => Http::response([]),
        'api.github.com/repos/acme/app/pulls/12/comments*' => Http::response([]),
        'api.github.com/repos/acme/app/pulls/12/commits*' => Http::response([
            ['sha' => $sha, 'commit' => ['message' => 'Fix the login form', 'author' => ['name' => 'Sam', 'date' => now()->subDays(2)->toIso8601String()]], 'author' => ['login' => 'sam'], 'html_url' => 'https://github.com/acme/app/commit/a'],
        ]),
        'api.github.com/repos/acme/app/pulls/12/files*' => Http::response([
            ['filename' => 'app/Login.php', 'status' => 'modified', 'additions' => 2, 'deletions' => 1, 'changes' => 3, 'patch' => "@@ -1,2 +1,3 @@\n-return false;\n+return true;\n+// checked"],
        ]),
        "api.github.com/repos/acme/app/commits/{$sha}/check-runs*" => Http::response(['check_runs' => [
            ['id' => 501, 'name' => 'tests', 'status' => 'completed', 'conclusion' => 'failure', 'app' => ['slug' => 'github-actions'], 'started_at' => now()->subMinutes(5)->toIso8601String(), 'completed_at' => now()->subMinutes(2)->toIso8601String(), 'html_url' => 'https://github.com/acme/app/actions/runs/1/job/501', 'output' => []],
            ['id' => 502, 'name' => 'lint', 'status' => 'completed', 'conclusion' => 'success', 'app' => ['slug' => 'github-actions'], 'started_at' => null, 'completed_at' => null, 'html_url' => null, 'output' => []],
        ]]),
        "api.github.com/repos/acme/app/commits/{$sha}/status" => Http::response(['statuses' => []]),
        'api.github.com/repos/acme/app/actions/jobs/501/logs' => Http::response("FAILED Tests\\LoginTest\nExpected 200, got 500"),
    ]);

    $user = User::factory()->has(AgentConnection::factory())->create(['email' => 'dev@example.com']);
    $project = Project::factory()->for($user)->create(['git_remote_url' => 'https://github.com/acme/app.git', 'git_remote_token' => 'ghp_token']);
    Sandbox::factory()->for($project)->create(['preview_url' => null, 'status' => SandboxStatus::Running]);
    $this->actingAs($user);

    $page = visit("/projects/{$project->id}?tab=tools&tool=pulls")
        ->resize(1500, 1000)
        ->assertVisible('@pulls-list')
        ->assertSee('Fix login')
        ->assertSee('Changes requested')
        ->assertSee('Dark mode')
        ->type('@pulls-search', 'dark')
        ->assertDontSee('Fix login')
        ->clear('@pulls-search')
        ->click('li:first-child > [data-test="pull-row"]')
        ->assertSeeIn('@pull-title', 'Fix login')
        ->assertSeeIn('@pull-state', 'Open')
        ->assertSeeIn('@pull-conversation', 'Fixes the login form.')
        ->assertSeeIn('@pull-conversation', 'Needs a test for the error.')
        ->click('@pull-tab-commits')
        ->assertSeeIn('@pull-commits', 'Fix the login form')
        ->click('@pull-tab-files')
        ->click('@pull-file')
        ->assertSeeIn('@pull-files', 'return true;')
        ->click('@pull-tab-checks')
        ->assertSeeIn('@pull-checks', 'Failed in 3m 0s')
        ->assertSeeIn('@pull-checks', 'lint')
        ->click('[data-test="pull-check"]:first-child button')
        ->assertSeeIn('@pull-check-log', 'Expected 200, got 500')
        ->assertVisible('@pull-fix')
        ->assertScript('new URL(location.href).searchParams.get("pr")', '12')
        ->click('@pull-check-out');

    $task = Task::sole();
    $page->assertPathIs("/projects/{$project->id}/tasks/{$task->id}")
        ->assertVisible('@task-pull-request-bar')
        ->assertSeeIn('@task-pull-request-bar', '#12')
        ->assertSeeIn('@task-pull-request-bar', 'fix-login')
        ->assertSeeIn('@task-pull-request-status', 'Checking out…')
        // Beside the task, its pull request's page, now with Open task.
        ->assertSeeIn('@pull-title', 'Fix login')
        ->assertVisible('@pull-open-task')
        ->assertScript('new URL(location.href).searchParams.get("pr")', '12')
        ->assertNoJavaScriptErrors();

    expect($task->title)->toBe('#12 Fix login')
        ->and($task->pull_request_branch)->toBe('fix-login');
    Queue::assertPushed(ForkTaskSandbox::class);
})->group('GIT-013', 'GIT-014', 'GIT-015');
