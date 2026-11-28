<?php

use App\Enums\SandboxStatus;
use App\Enums\TaskStage;
use App\Enums\TaskSyncStatus;
use App\Jobs\OpenTaskPullRequest;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\Task;
use App\Models\User;
use App\Sandbox\Agents\AgentRunner;
use App\Sandbox\Agents\FakeAgentRunner;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

test('the dev user opens the Pull requests page, reads a diff, opens a pull request from a task, and sees a merged one on the board', function () {
    Queue::fake();
    config(['sandbox.task_copies' => true]);
    app()->instance(SandboxProvider::class, new FakeSandboxProvider);
    app()->instance(AgentRunner::class, new FakeAgentRunner);
    $sha = str_repeat('a', 40);

    Http::fake([
        'api.github.com/graphql' => Http::response(['data' => ['repository' => ['pullRequests' => ['nodes' => [[
            'number' => 12, 'title' => 'Fix login', 'state' => 'OPEN', 'isDraft' => false, 'url' => 'https://github.com/acme/app/pull/12',
            'updatedAt' => now()->subHour()->toIso8601String(), 'headRefName' => 'fix-login', 'baseRefName' => 'main', 'reviewDecision' => null,
            'author' => ['login' => 'sam', 'avatarUrl' => null], 'headRepository' => ['nameWithOwner' => 'acme/app'],
            'comments' => ['totalCount' => 0], 'commits' => ['nodes' => [['commit' => ['oid' => $sha, 'statusCheckRollup' => ['state' => 'SUCCESS']]]]],
        ]]]]]]),
        'api.github.com/repos/acme/app/pulls/12' => Http::response([
            'number' => 12, 'title' => 'Fix login', 'body' => 'Fixes the login form.', 'state' => 'open', 'draft' => false, 'merged' => false,
            'html_url' => 'https://github.com/acme/app/pull/12', 'user' => ['login' => 'sam', 'avatar_url' => null],
            'created_at' => now()->subDays(2)->toIso8601String(), 'updated_at' => now()->subHour()->toIso8601String(),
            'head' => ['ref' => 'fix-login', 'sha' => $sha, 'repo' => ['full_name' => 'acme/app']], 'base' => ['ref' => 'main'],
            'mergeable' => true, 'commits' => 1, 'additions' => 2, 'deletions' => 1, 'changed_files' => 1, 'comments' => 0, 'review_comments' => 0,
        ]),
        'api.github.com/repos/acme/app/issues/12/comments*' => Http::response([]),
        'api.github.com/repos/acme/app/pulls/12/reviews*' => Http::response([]),
        'api.github.com/repos/acme/app/pulls/12/comments*' => Http::response([]),
        'api.github.com/repos/acme/app/pulls/12/files*' => Http::response([
            ['filename' => 'app/Login.php', 'status' => 'modified', 'additions' => 2, 'deletions' => 1, 'changes' => 3, 'patch' => "@@ -1,2 +1,3 @@\n-return false;\n+return true;\n+// checked"],
        ]),
        "api.github.com/repos/acme/app/commits/{$sha}/check-runs*" => Http::response(['check_runs' => []]),
        "api.github.com/repos/acme/app/commits/{$sha}/status" => Http::response(['statuses' => []]),
    ]);

    $user = User::factory()->has(AgentConnection::factory())->create(['email' => 'dev@example.com']);
    $project = Project::factory()->for($user)->create(['name' => 'Shop', 'git_remote_url' => 'https://github.com/acme/app.git', 'git_remote_token' => 'ghp_token']);
    Sandbox::factory()->for($project)->create(['preview_url' => null, 'status' => SandboxStatus::Running]);
    $task = Task::factory()->for($project)->create(['title' => 'Add dark mode', 'stage' => TaskStage::Review]);
    $task->sandbox()->create(['project_id' => $project->id, 'provider' => 'fake', 'external_id' => 'copy-1', 'status' => SandboxStatus::Running, 'preview_url' => null]);
    Task::factory()->for($project)->create([
        'title' => '#9 Old header', 'stage' => TaskStage::Done, 'pull_request_number' => 9, 'pull_request_branch' => 'old-header',
        'pull_request_base' => 'main', 'pull_request_state' => 'merged',
    ]);
    $this->actingAs($user);

    // The Pull requests page, from the project's menu and beside Board in the opened project's sidebar (GIT-022).
    $page = visit("/projects/{$project->id}")
        ->resize(1500, 1000)
        ->click('@project-menu')
        ->click('@project-menu-pull-requests')
        ->assertPathIs("/projects/{$project->id}/pull-requests")
        ->click('@project-menu')
        ->click('@project-menu-open')
        ->click('@open-project-pull-requests')
        ->assertPathIs("/projects/{$project->id}/pull-requests")
        ->assertSee('Fix login')
        ->click('[data-test="pull-row"]')
        ->assertSeeIn('@pull-title', 'Fix login')
        ->click('@pull-tab-files')
        ->assertScript('new URL(location.href).searchParams.get("pr_tab")', 'files')
        ->refresh()
        ->click('@pull-file')
        ->assertSeeIn('@pull-files', 'return true;')
        ->assertNoJavaScriptErrors();

    // Open a pull request from a task (GIT-024).
    $page->navigate("/projects/{$project->id}/tasks/{$task->id}")
        ->assertSeeIn('@task-open-pull-request', 'Open pull request')
        ->click('@task-open-pull-request')
        ->assertSeeIn('@task-copy-status', 'Pushing to GitHub…');

    Queue::assertPushed(OpenTaskPullRequest::class);
    expect($task->fresh()->sync_status)->toBe(TaskSyncStatus::Pushing);

    // The push finished: GitHub's page, filled in, is a click away.
    $task->update([
        'sync_status' => null, 'pull_request_branch' => "onedrop/task-{$task->id}-add-dark-mode", 'pull_request_base' => 'main',
        'pull_request_draft' => ['title' => 'Add dark mode', 'body' => '', 'url' => 'https://github.com/acme/app/compare/main...dark?quick_pull=1'],
    ]);
    $page->refresh()
        ->assertSeeIn('@task-copy-status', "Pushed onedrop/task-{$task->id}-add-dark-mode")
        ->assertAttribute('@task-create-pull-request', 'href', 'https://github.com/acme/app/compare/main...dark?quick_pull=1')
        ->assertSeeIn('@task-open-pull-request', 'Push again');

    // Linked once GitHub has it: the pull request's bar, with Files changed.
    $task->update(['pull_request_number' => 12, 'pull_request_draft' => null]);
    $page->refresh()
        ->assertSeeIn('@task-pull-request-bar', '#12')
        ->click('@task-pull-request-open')
        ->click('@pull-tab-files')
        ->assertScript('new URL(location.href).searchParams.get("pr_tab")', 'files')
        ->click('@pull-file')
        ->assertSeeIn('@pull-files', 'return true;');

    // Switching to the Preview and back shows the same pull request and tab (GIT-013).
    $page->click('@tab-preview')
        ->assertScript('new URL(location.href).searchParams.get("pr")', null)
        ->click('@tab-tools')
        ->assertScript('new URL(location.href).searchParams.get("pr")', '12')
        ->assertScript('new URL(location.href).searchParams.get("pr_tab")', 'files')
        ->assertSeeIn('@pull-title', 'Fix login');

    // A merged pull request's task is in Done, marked merged (GIT-023).
    $page->navigate("/projects/{$project->id}/board")
        ->assertSeeIn('@board-column-done', '#9 Old header')
        ->assertSeeIn('@board-card-pull-request-state', 'Merged')
        ->assertNoJavaScriptErrors();
})->group('GIT-013', 'GIT-022', 'GIT-023', 'GIT-024');
