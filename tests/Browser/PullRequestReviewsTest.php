<?php

use App\Enums\MessageRole;
use App\Enums\SandboxStatus;
use App\Enums\TaskStage;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\ReviewReply;
use App\Models\Sandbox;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

test('review comments are read, addressed, and the agent\'s reply posted as the dev user', function () {
    Queue::fake();
    config(['sandbox.task_copies' => true]);
    $sha = str_repeat('a', 40);
    $posted = [];
    $thread = fn (string $id, int $comment, string $path, string $body, string $login, bool $bot) => [
        'id' => $id, 'isResolved' => false, 'isOutdated' => false, 'path' => $path, 'line' => 4, 'startLine' => null, 'originalLine' => 4, 'originalStartLine' => null,
        'comments' => ['nodes' => [[
            'databaseId' => $comment, 'body' => $body, 'createdAt' => now()->subHour()->toIso8601String(), 'url' => "https://github.com/acme/app/pull/12#discussion_r{$comment}",
            'authorAssociation' => $bot ? 'NONE' : 'MEMBER', 'diffHunk' => "@@ -1,3 +1,4 @@\n # API\n+POST /session",
            'author' => ['__typename' => $bot ? 'Bot' : 'User', 'login' => $login, 'avatarUrl' => null],
        ]]],
    ];
    $threads = [
        $thread('PRRT_docs', 101, 'docs/api.md', 'Document that POST /tokens is gone.', 'copilot-pull-request-reviewer', true),
        $thread('PRRT_name', 201, 'app/Tokens.php', 'Rename this to `$sessionId`.', 'sam', false),
    ];

    Http::fake([
        'api.github.com/graphql' => function (HttpRequest $request) use ($threads, $sha, &$posted) {
            $query = (string) $request['query'];

            if (str_contains($query, 'addPullRequestReviewThreadReply')) {
                $posted[] = $request['variables']['body'];

                return Http::response(['data' => ['addPullRequestReviewThreadReply' => ['comment' => ['databaseId' => 900]]]]);
            }

            if (str_contains($query, 'resolveReviewThread')) {
                return Http::response(['data' => ['resolveReviewThread' => ['thread' => ['id' => 'PRRT_docs']]]]);
            }

            if (str_contains($query, 'pullRequest(number: $number)')) {
                return Http::response(['data' => ['repository' => ['pullRequest' => ['reviewThreads' => ['nodes' => $threads]]]]]);
            }

            return Http::response(['data' => ['repository' => ['pullRequests' => ['nodes' => [[
                'number' => 12, 'title' => 'Close the /tokens hole', 'state' => 'OPEN', 'isDraft' => false, 'url' => 'https://github.com/acme/app/pull/12',
                'updatedAt' => now()->subHour()->toIso8601String(), 'headRefName' => 'tokens', 'baseRefName' => 'main', 'reviewDecision' => null,
                'author' => ['login' => 'dev', 'avatarUrl' => null], 'headRepository' => ['nameWithOwner' => 'acme/app'],
                'comments' => ['totalCount' => 0], 'reviewThreads' => ['nodes' => [['isResolved' => false], ['isResolved' => false]]],
                'commits' => ['nodes' => [['commit' => ['oid' => $sha, 'statusCheckRollup' => ['state' => 'SUCCESS']]]]],
            ]]]]]]);
        },
        'api.github.com/repos/acme/app/pulls/12' => Http::response([
            'number' => 12, 'title' => 'Close the /tokens hole', 'body' => 'Removes POST /tokens.', 'state' => 'open', 'draft' => false, 'merged' => false,
            'html_url' => 'https://github.com/acme/app/pull/12', 'user' => ['login' => 'dev', 'avatar_url' => null],
            'created_at' => now()->subDay()->toIso8601String(), 'updated_at' => now()->subHour()->toIso8601String(),
            'head' => ['ref' => 'tokens', 'sha' => $sha, 'repo' => ['full_name' => 'acme/app']], 'base' => ['ref' => 'main'],
            'mergeable' => true, 'commits' => 1, 'additions' => 2, 'deletions' => 9, 'changed_files' => 2, 'comments' => 0, 'review_comments' => 2,
        ]),
        'api.github.com/repos/acme/app/issues/12/comments*' => Http::response([]),
        'api.github.com/repos/acme/app/pulls/12/reviews*' => Http::response([]),
        'api.github.com/repos/acme/app/pulls/12/comments*' => Http::response([]),
        "api.github.com/repos/acme/app/commits/{$sha}/check-runs*" => Http::response(['check_runs' => []]),
        "api.github.com/repos/acme/app/commits/{$sha}/status" => Http::response(['statuses' => []]),
    ]);

    $user = User::factory()->has(AgentConnection::factory())->create(['email' => 'dev@example.com']);
    $project = Project::factory()->for($user)->create(['git_remote_url' => 'https://github.com/acme/app.git', 'git_remote_token' => 'ghp_token']);
    Sandbox::factory()->for($project)->create(['preview_url' => null, 'status' => SandboxStatus::Running]);
    $task = Task::factory()->for($project)->create([
        'title' => '#12 Close the /tokens hole', 'stage' => TaskStage::Review,
        'pull_request_number' => 12, 'pull_request_branch' => 'tokens', 'pull_request_base' => 'main', 'pull_request_head_sha' => $sha,
    ]);
    $task->sandbox()->create(['project_id' => $project->id, 'provider' => 'fake', 'external_id' => 'copy-1', 'status' => SandboxStatus::Running]);
    // The agent already fixed the docs thread; its reply waits to be posted.
    ReviewReply::factory()->for($task)->create(['thread_id' => 'PRRT_docs', 'path' => 'docs/api.md', 'body' => 'Documented that POST /tokens is gone.', 'commit_sha' => str_repeat('c', 40)]);
    $this->actingAs($user);

    $page = visit("/projects/{$project->id}/pull-requests")
        ->resize(1500, 1000)
        ->assertSeeIn('@pull-row-open-threads', '2')
        // Copilot's comments go to the agent by themselves from now on.
        ->click('@pulls-review-choice')
        ->click('@pulls-review-bots')
        ->click('@pull-row')
        ->assertVisible('@review-threads')
        ->assertSeeIn('@review-threads', 'Document that POST /tokens is gone.')
        ->assertSeeIn('@review-threads', 'copilot-pull-request-reviewer')
        ->assertSeeIn('@review-reply-outcome', 'Fixed')
        ->assertSeeIn('@pull-address', 'Address 2 comments')
        // Edit the agent's reply, then post it.
        ->clear('@review-reply-text')
        ->type('@review-reply-text', 'Documented the removal in docs/api.md.')
        ->click('@review-reply-post')
        ->assertSeeIn('@review-threads', 'Posted on GitHub')
        // Leave out the docs thread, now answered: only Sam's goes to the agent.
        ->click('[data-test="review-thread"]:nth-child(1) [data-test="review-thread-choose"]')
        ->assertSeeIn('@review-threads-address', 'Address 1 comment')
        ->click('@review-threads-address');

    $page->assertPathIs("/projects/{$project->id}/tasks/{$task->id}")
        ->assertVisible('@task-pull-request-bar')
        ->assertVisible('@task-pull-request-address-reviews')
        ->assertNoJavaScriptErrors();

    expect($project->fresh()->address_review_comments)->toBe('bots')
        ->and($posted)->toBe(["Documented the removal in docs/api.md.\n\nFixed in ".str_repeat('c', 40).'.'])
        ->and(ReviewReply::sole()->status)->toBe('posted')
        ->and(array_keys($task->fresh()->pull_request_review_batch))->toBe(['T201'])
        ->and($task->queuedMessages()->count() + $task->messages()->where('role', MessageRole::User)->count())->toBe(1);
})->group('GIT-031');
