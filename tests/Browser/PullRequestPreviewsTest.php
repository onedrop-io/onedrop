<?php

use App\Enums\SandboxStatus;
use App\Enums\TaskStage;
use App\Jobs\CatchUpPullRequests;
use App\Jobs\UpdatePullRequestPreview;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\Task;
use App\Models\User;
use App\Sandbox\Agents\AgentRunner;
use App\Sandbox\Agents\FakeAgentRunner;
use App\Sandbox\Gateway;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

test('the dev user turns on previewing every pull request, copies a preview link and makes a new one', function () {
    Queue::fake();
    config(['sandbox.task_copies' => true, 'sandbox.gateway_domain' => 'onedrop.example.com']);
    app()->instance(SandboxProvider::class, new FakeSandboxProvider);
    app()->instance(AgentRunner::class, new FakeAgentRunner);
    $sha = str_repeat('a', 40);

    Http::fake([
        'api.github.com/graphql' => Http::response(['data' => ['repository' => ['pullRequests' => ['nodes' => [[
            'number' => 12, 'title' => 'Fix login', 'state' => 'OPEN', 'isDraft' => false, 'url' => 'https://github.com/acme/app/pull/12',
            'updatedAt' => now()->subHour()->toIso8601String(), 'headRefName' => 'fix-login', 'baseRefName' => 'main', 'reviewDecision' => null,
            'author' => ['login' => 'sam', 'avatarUrl' => null], 'headRepository' => ['nameWithOwner' => 'acme/app'],
            'comments' => ['totalCount' => 0], 'commits' => ['nodes' => [['commit' => ['oid' => $sha, 'statusCheckRollup' => null]]]],
        ]]]]]]),
    ]);

    $user = User::factory()->has(AgentConnection::factory())->create(['email' => 'dev@example.com']);
    $project = Project::factory()->for($user)->create(['name' => 'Shop', 'git_remote_url' => 'https://github.com/acme/app.git', 'git_remote_token' => 'ghp_token']);
    Sandbox::factory()->for($project)->create(['preview_url' => null, 'status' => SandboxStatus::Running]);
    $task = Task::factory()->for($project)->create([
        'title' => '#12 Fix login', 'stage' => TaskStage::Review, 'pull_request_number' => 12, 'pull_request_branch' => 'fix-login',
        'pull_request_base' => 'main', 'pull_request_head_sha' => $sha, 'preview_key' => 'key-one',
    ]);
    $task->sandbox()->create(['project_id' => $project->id, 'provider' => 'fake', 'external_id' => 'copy-1', 'status' => SandboxStatus::Running, 'preview_url' => null]);
    $this->actingAs($user);
    $link = app(Gateway::class)->pullRequestLink($task);

    // The Pull requests page: previewing every pull request is on, and the pull request's preview is a click away (GIT-026, GIT-027).
    $page = visit("/projects/{$project->id}/pull-requests")
        ->resize(1500, 1000)
        ->assertSee('Fix login')
        // Off until turned on (GIT-026).
        ->assertNotChecked('@pulls-preview-every')
        ->assertDontSee('Anyone with a preview link can open the app')
        ->assertAttribute('@pull-row-preview', 'href', $link)
        ->click('@pulls-preview-every')
        ->assertChecked('@pulls-preview-every')
        ->assertSeeIn('@pulls-preview-note', 'Anyone with a preview link can open the app')
        ->assertNoJavaScriptErrors();

    expect($project->fresh()->preview_pull_requests)->toBeTrue();
    Queue::assertPushed(CatchUpPullRequests::class);

    // The task's bar: the link, copied, and a new one that stops the old.
    $page->navigate("/projects/{$project->id}/tasks/{$task->id}")
        ->assertAttribute('@task-preview-open', 'href', $link)
        ->assertSeeIn('@task-preview', 'Anyone with the link can open it')
        ->click('@task-preview-copy')
        ->assertSeeIn('@task-preview-copy', 'Copied');

    $page->script('window.confirm = () => true');
    $page->click('@task-preview-new-link')
        ->assertSee('Made a new preview link')
        ->assertNoJavaScriptErrors();

    expect($task->fresh()->preview_key)->not->toBe('key-one');
    Queue::assertPushed(UpdatePullRequestPreview::class);
})->group('GIT-026', 'GIT-027');
