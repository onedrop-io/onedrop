<?php

use App\Enums\ProjectStatus;
use App\Enums\SandboxStatus;
use App\Enums\TaskStage;
use App\Enums\TaskSyncStatus;
use App\Jobs\CatchUpPullRequests;
use App\Jobs\ForkTaskSandbox;
use App\Jobs\HandlePullRequestEvent;
use App\Jobs\RemovePullRequestCopy;
use App\Jobs\SyncPullRequestTask;
use App\Jobs\UpdatePullRequestPreview;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\Task;
use App\Models\User;
use App\Sandbox\Gateway;
use App\Sandbox\GitHubPreviews;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\PullRequestPreviews;
use App\Sandbox\SandboxProvider;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['sandbox.task_copies' => true, 'sandbox.gateway_domain' => 'onedrop.example.com', 'services.github_app.webhook_secret' => 'hook-secret']);
    Http::preventStrayRequests();
    Queue::fake();
    app()->instance(SandboxProvider::class, new FakeSandboxProvider);

    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->project = Project::factory()->for($this->user)->create([
        'name' => 'Shop',
        'git_remote_url' => 'https://github.com/acme/app.git',
        'git_remote_token' => 'ghp_token',
        'preview_pull_requests' => true,
    ]);
    Sandbox::factory()->for($this->project)->create(['external_id' => 'main-1', 'status' => SandboxStatus::Running]);
    $this->actingAs($this->user);
    $this->sha = str_repeat('a', 40);
    $this->pull = ['number' => 12, 'title' => 'Fix login', 'state' => 'open', 'head' => 'fix-login', 'head_sha' => $this->sha, 'base' => 'main', 'fork' => false];
});

/**
 * A pull request's task with a running copy of the app.
 */
function previewTask(Project $project, array $attributes = []): Task
{
    $task = Task::factory()->for($project)->create([
        'title' => '#12 Fix login', 'stage' => TaskStage::Review, 'pull_request_number' => 12, 'pull_request_branch' => 'fix-login',
        'pull_request_base' => 'main', 'pull_request_head_sha' => str_repeat('a', 40), 'preview_key' => 'key-one', ...$attributes,
    ]);
    $task->sandbox()->create(['project_id' => $project->id, 'provider' => 'fake', 'external_id' => 'copy-1', 'status' => SandboxStatus::Running, 'preview_url' => 'http://127.0.0.1:41000']);

    return $task;
}

/**
 * A signed pull_request webhook from the GitHub App.
 */
function pullRequestWebhook(array $payload, string $secret = 'hook-secret', string $event = 'pull_request')
{
    $body = json_encode($payload);

    return test()->call('POST', route('github.webhook'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_GITHUB_EVENT' => $event,
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret),
    ], $body);
}

function webhookPayload(string $action = 'opened', array $pull = [], int $installation = 77): array
{
    return [
        'action' => $action,
        'installation' => ['id' => $installation],
        'repository' => ['full_name' => 'acme/app'],
        'pull_request' => [
            'number' => 12, 'title' => 'Fix login', 'state' => 'open', 'draft' => false, 'merged' => false,
            'head' => ['ref' => 'fix-login', 'sha' => str_repeat('a', 40), 'repo' => ['full_name' => 'acme/app']],
            'base' => ['ref' => 'main'], ...$pull,
        ],
    ];
}

test('the webhook queues pull request events for the projects connected to that repository through the app', function () {
    $this->project->update(['github_installation_id' => 77, 'git_remote_token' => null]);
    $other = Project::factory()->for($this->user)->create(['git_remote_url' => 'https://github.com/acme/other.git', 'github_installation_id' => 77]);

    pullRequestWebhook(webhookPayload())->assertStatus(202);

    Queue::assertPushed(HandlePullRequestEvent::class, 1);
    Queue::assertPushed(HandlePullRequestEvent::class, fn ($job) => $job->project->is($this->project) && $job->pull === $this->pull);
    expect($other->exists)->toBeTrue();
})->group('GIT-026');

test('the webhook refuses a wrong or missing signature, and ignores other events and actions', function () {
    $this->project->update(['github_installation_id' => 77]);

    pullRequestWebhook(webhookPayload(), 'not-the-secret')->assertUnauthorized();
    config(['services.github_app.webhook_secret' => null]);
    pullRequestWebhook(webhookPayload(), '')->assertUnauthorized();

    config(['services.github_app.webhook_secret' => 'hook-secret']);
    pullRequestWebhook(['zen' => 'Keep it logically awesome.'], event: 'ping')->assertStatus(202);
    pullRequestWebhook(webhookPayload('labeled'))->assertStatus(202);
    pullRequestWebhook(webhookPayload(installation: 99))->assertStatus(202);

    Queue::assertNotPushed(HandlePullRequestEvent::class);
})->group('GIT-026');

test('a webhook names a merged pull request, and one from a fork, as such', function () {
    $this->project->update(['github_installation_id' => 77]);

    pullRequestWebhook(webhookPayload('closed', ['state' => 'closed', 'merged' => true]))->assertStatus(202);
    pullRequestWebhook(webhookPayload('opened', ['head' => ['ref' => 'main', 'sha' => 'b', 'repo' => null]]))->assertStatus(202);

    Queue::assertPushed(HandlePullRequestEvent::class, fn ($job) => $job->pull['state'] === 'merged');
    Queue::assertPushed(HandlePullRequestEvent::class, fn ($job) => $job->pull['fork'] === true);
})->group('GIT-026');

test('previewing every pull request is off until it\'s turned on, so a new project checks nothing out by itself', function () {
    $project = Project::factory()->for($this->user)->create(['git_remote_url' => 'https://github.com/acme/new.git', 'git_remote_token' => 'ghp_token']);

    expect($project->fresh()->preview_pull_requests)->toBeFalse()
        ->and(PullRequestPreviews::enabled($project))->toBeFalse();

    app(PullRequestPreviews::class)->opened($project, $this->pull);
    expect(Task::count())->toBe(0);
    Queue::assertNotPushed(ForkTaskSandbox::class);
})->group('GIT-026');

test('an opened pull request is checked out as a task by itself, unless it comes from a fork, previews are off, or no copy is free', function () {
    app(PullRequestPreviews::class)->opened($this->project, $this->pull);

    $task = Task::sole();
    expect($task->only('title', 'pull_request_number', 'pull_request_branch', 'stage', 'sync_status'))
        ->toBe(['title' => '#12 Fix login', 'pull_request_number' => 12, 'pull_request_branch' => 'fix-login', 'stage' => TaskStage::Review, 'sync_status' => TaskSyncStatus::Forking])
        ->and($task->sandbox->status)->toBe(SandboxStatus::Creating);
    Queue::assertPushed(ForkTaskSandbox::class, fn ($job) => $job->checkOutOnly);
    Queue::assertPushed(UpdatePullRequestPreview::class);

    app(PullRequestPreviews::class)->opened($this->project, [...$this->pull, 'number' => 13, 'fork' => true]);
    $this->project->update(['preview_pull_requests' => false]);
    app(PullRequestPreviews::class)->opened($this->project, [...$this->pull, 'number' => 14]);
    $this->project->update(['preview_pull_requests' => true]);
    config(['sandbox.max_task_copies' => 1]);
    app(PullRequestPreviews::class)->opened($this->project->fresh(), [...$this->pull, 'number' => 15]);

    expect(Task::count())->toBe(1);
})->group('GIT-026');

test('new commits on GitHub are brought into the copy, unless the agent is working', function () {
    $task = previewTask($this->project);
    $newer = [...$this->pull, 'head_sha' => str_repeat('b', 40)];

    app(PullRequestPreviews::class)->opened($this->project, $this->pull);
    Queue::assertNotPushed(SyncPullRequestTask::class);

    $task->update(['status' => ProjectStatus::Working]);
    app(PullRequestPreviews::class)->opened($this->project, $newer);
    Queue::assertNotPushed(SyncPullRequestTask::class);

    $task->update(['status' => ProjectStatus::Idle]);
    app(PullRequestPreviews::class)->opened($this->project, $newer);

    expect($task->fresh()->sync_status)->toBe(TaskSyncStatus::Pulling);
    Queue::assertPushed(SyncPullRequestTask::class, fn ($job) => $job->direction === TaskSyncStatus::Pulling);
    Queue::assertPushed(UpdatePullRequestPreview::class);
})->group('GIT-026');

test('a closed pull request settles its task, and its copy goes a day later unless it was reopened', function () {
    $task = previewTask($this->project);

    app(PullRequestPreviews::class)->closed($this->project, 12, 'merged');

    expect($task->fresh()->only('stage', 'pull_request_state'))->toBe(['stage' => TaskStage::Done, 'pull_request_state' => 'merged']);
    Queue::assertPushed(UpdatePullRequestPreview::class);
    Queue::assertPushed(RemovePullRequestCopy::class, fn ($job) => $job->delay !== null);

    // Reopened before the day is up: kept.
    app(PullRequestPreviews::class)->opened($this->project, $this->pull);
    (new RemovePullRequestCopy($task))->handle();
    expect($task->fresh()->pull_request_state)->toBeNull()
        ->and($task->sandbox()->exists())->toBeTrue();

    $task->update(['pull_request_state' => 'closed']);
    (new RemovePullRequestCopy($task))->handle();
    expect($task->sandbox()->exists())->toBeFalse();

    // Reopened after: a new copy is made.
    app(PullRequestPreviews::class)->opened($this->project, $this->pull);
    expect($task->fresh()->sandbox->status)->toBe(SandboxStatus::Creating);
    Queue::assertPushed(ForkTaskSandbox::class);
})->group('GIT-026');

test('listing the pull requests catches up on ones no webhook told about, and turning previews on catches up too', function () {
    Http::fake(['api.github.com/graphql' => Http::response(['data' => ['repository' => ['pullRequests' => ['nodes' => [[
        'number' => 12, 'title' => 'Fix login', 'state' => 'OPEN', 'isDraft' => false, 'url' => 'https://github.com/acme/app/pull/12',
        'updatedAt' => '2026-11-24T10:00:00Z', 'headRefName' => 'fix-login', 'baseRefName' => 'main', 'reviewDecision' => null, 'author' => null,
        'headRepository' => ['nameWithOwner' => 'acme/app'], 'comments' => ['totalCount' => 0], 'commits' => ['nodes' => [['commit' => ['oid' => $this->sha, 'statusCheckRollup' => null]]]],
    ]]]]]])]);

    $this->getJson(route('projects.pulls.index', $this->project))
        ->assertOk()
        ->assertJsonPath('preview_pull_requests', true)
        ->assertJsonPath('pulls.0.head_sha', $this->sha)
        ->assertJsonPath('tasks.12', Task::sole()->id);

    $this->patchJson(route('projects.pulls.settings', $this->project), ['preview_pull_requests' => false])->assertOk();
    expect($this->project->fresh()->preview_pull_requests)->toBeFalse();
    Queue::assertNotPushed(CatchUpPullRequests::class);

    $this->patchJson(route('projects.pulls.settings', $this->project), ['preview_pull_requests' => true])->assertOk();
    Queue::assertPushed(CatchUpPullRequests::class);

    $this->actingAs(User::factory()->has(AgentConnection::factory())->create())
        ->patchJson(route('projects.pulls.settings', $this->project), ['preview_pull_requests' => false])->assertForbidden();
})->group('GIT-026');

test('the catch-up runs hourly for every previewing project, and every 5 minutes for those the webhook can\'t reach', function () {
    $app = Project::factory()->for($this->user)->create(['git_remote_url' => 'https://github.com/acme/web.git', 'github_installation_id' => 77, 'preview_pull_requests' => true]);
    Project::factory()->for($this->user)->create(['git_remote_url' => 'https://github.com/acme/off.git', 'git_remote_token' => 't', 'preview_pull_requests' => false]);

    $this->artisan('pull-requests:catch-up --unhooked')->assertSuccessful();
    Queue::assertPushed(CatchUpPullRequests::class, 1);
    Queue::assertPushed(CatchUpPullRequests::class, fn ($job) => $job->project->is($this->project));

    $this->artisan('pull-requests:catch-up')->assertSuccessful();
    Queue::assertPushed(CatchUpPullRequests::class, fn ($job) => $job->project->is($app));
    Queue::assertPushed(CatchUpPullRequests::class, 3);
})->group('GIT-026');

test("a pull request's preview address opens for anyone with its link, until a new link is made", function () {
    $task = previewTask($this->project);
    $host = 'pr-12-shop-'.$this->project->id.'.onedrop.example.com';
    $gateway = app(Gateway::class);

    expect($gateway->pullRequestLink($task))->toBe("https://{$host}/__onedrop/enter?key=key-one&path=%2F")
        ->and($gateway->parse($host))->toBe(['kind' => 'pr', 'sandbox_id' => $task->sandbox->id, 'task_id' => $task->id])
        // The name is only for reading: a renamed project's old links still work.
        ->and($gateway->parse('pr-12-old-name-'.$this->project->id.'.onedrop.example.com')['task_id'])->toBe($task->id)
        ->and($gateway->parse('pr-13-shop-'.$this->project->id.'.onedrop.example.com'))->toBeNull();

    auth()->logout();
    $this->get("https://{$host}/__onedrop/enter?key=wrong&path=/cart")->assertUnauthorized()->assertSee('Open this preview from its pull request');
    $this->get("https://{$host}/__onedrop/enter?key=key-one&path=/cart")->assertRedirect("https://{$host}/cart")->assertCookie(Gateway::COOKIE);

    $pass = $gateway->pullRequestPass($task);
    $authorize = fn (?string $cookie) => $this->withHeaders(['X-Forwarded-Host' => $host])
        ->withCookie(Gateway::COOKIE, (string) $cookie)
        ->get(route('sandbox-gateway.authorize'));

    $authorize($pass)->assertOk()->assertHeader('X-OneDrop-Upstream', '127.0.0.1:41000');
    $authorize(null)->assertUnauthorized();

    // A new link: the old one's visitors are turned away at once.
    $this->actingAs($this->user)->post(route('projects.tasks.preview-link', [$this->project, $task]))->assertRedirect();
    expect($task->fresh()->preview_key)->not->toBe('key-one');
    Queue::assertPushed(UpdatePullRequestPreview::class);
    auth()->logout();
    $authorize($pass)->assertUnauthorized();
    $this->get("https://{$host}/__onedrop/enter?key=key-one")->assertUnauthorized();
})->group('GIT-027');

test('certificates are issued for preview addresses, and a published project named like one keeps clear of them', function () {
    $task = previewTask($this->project);

    $this->get(route('sandbox-gateway.certificate', ['domain' => 'pr-12-shop-'.$this->project->id.'.onedrop.example.com']))->assertOk();
    $this->get(route('sandbox-gateway.certificate', ['domain' => 'pr-99-shop-'.$this->project->id.'.onedrop.example.com']))->assertNotFound();

    $named = Project::factory()->for($this->user)->create(['name' => 'PR 12 shop']);
    expect(app(Gateway::class)->publishedHost($named))->toBe("app-pr-12-shop-{$named->id}.onedrop.example.com")
        ->and($task->exists)->toBeTrue();
})->group('GIT-027');

test("the task's page and the pull request's page show the preview link, and GitHub's refusal", function () {
    config(['inertia.ssr.enabled' => false]);
    $task = previewTask($this->project, ['preview_error' => 'GitHub refused: the app or token needs the Deployments: Read and write permission.']);
    $link = app(Gateway::class)->pullRequestLink($task);

    $this->get(route('projects.tasks.show', [$this->project, $task]))
        ->assertInertia(fn ($page) => $page->where('task.pull_request.preview_url', $link)->where('task.pull_request.preview_error', $task->preview_error));

    // Someone who can't change the project can't make a new link.
    $this->actingAs(User::factory()->has(AgentConnection::factory())->create())
        ->post(route('projects.tasks.preview-link', [$this->project, $task]))->assertForbidden();
})->group('GIT-027', 'GIT-028');

test('the preview goes on the pull request as a Deployment of its commit and one comment, edited in place', function () {
    $task = previewTask($this->project, ['preview_key' => null]);
    $calls = [];
    Http::fake(function (HttpRequest $request) use (&$calls) {
        $calls[] = [$request->method(), $request->url(), $request->data()];

        return match (true) {
            str_ends_with($request->url(), '/deployments') => Http::response(['id' => 501], 201),
            str_contains($request->url(), '/statuses') => Http::response(['id' => 1], 201),
            str_ends_with($request->url(), '/issues/12/comments') => Http::response(['id' => 901], 201),
            default => Http::response(['id' => 901]),
        };
    });

    app(GitHubPreviews::class)->update($task);

    $task->refresh();
    $link = app(Gateway::class)->pullRequestLink($task);
    expect($task->preview_key)->not->toBeNull()
        ->and($task->only('preview_deployment_id', 'preview_deployment_sha', 'preview_comment_id', 'preview_error'))
        ->toBe(['preview_deployment_id' => 501, 'preview_deployment_sha' => $this->sha, 'preview_comment_id' => 901, 'preview_error' => null])
        ->and($calls[0][2])->toMatchArray(['ref' => $this->sha, 'environment' => 'OneDrop preview #12', 'transient_environment' => true, 'production_environment' => false, 'required_contexts' => []])
        ->and($calls[1][1])->toBe('https://api.github.com/repos/acme/app/deployments/501/statuses')
        ->and($calls[1][2])->toMatchArray(['state' => 'success', 'environment_url' => $link])
        ->and($calls[2][2]['body'])->toContain(GitHubPreviews::MARKER)->toContain("[Open the preview]({$link})")->toContain('Running `aaaaaaa`')->toContain('Anyone with this link');

    // The same commit again: no new Deployment, and the comment is edited.
    $calls = [];
    $task->update(['sync_status' => TaskSyncStatus::Pulling]);
    app(GitHubPreviews::class)->update($task);
    expect(array_column($calls, 0))->toBe(['POST', 'PATCH'])
        ->and($calls[0][2]['state'])->toBe('in_progress')
        ->and($calls[1][1])->toBe('https://api.github.com/repos/acme/app/issues/comments/901');

    // Merged: inactive, and the comment says so without the link.
    $calls = [];
    $task->update(['sync_status' => null, 'pull_request_state' => 'merged']);
    app(GitHubPreviews::class)->update($task);
    expect($calls[0][2])->toMatchArray(['state' => 'inactive'])->not->toHaveKey('environment_url')
        ->and($calls[1][2]['body'])->toContain('Removed: this pull request was merged.')->not->toContain('Open the preview');
})->group('GIT-028');

test('a new commit gets its own Deployment, a deleted comment is posted again, and GitHub refusing is noted on the task', function () {
    $task = previewTask($this->project, ['preview_deployment_id' => 500, 'preview_deployment_sha' => str_repeat('0', 40), 'preview_comment_id' => 900]);
    $refuse = false;
    Http::fake(function (HttpRequest $request) use (&$refuse) {
        return match (true) {
            $refuse => Http::response(['message' => 'Resource not accessible by integration'], 403),
            str_ends_with($request->url(), '/deployments') => Http::response(['id' => 502], 201),
            str_contains($request->url(), '/statuses') => Http::response(['id' => 1], 201),
            $request->method() === 'PATCH' => Http::response(['message' => 'Not Found'], 404),
            default => Http::response(['id' => 902], 201),
        };
    });

    app(GitHubPreviews::class)->update($task);
    expect($task->fresh()->only('preview_deployment_id', 'preview_deployment_sha', 'preview_comment_id'))
        ->toBe(['preview_deployment_id' => 502, 'preview_deployment_sha' => $this->sha, 'preview_comment_id' => 902]);

    $refuse = true;
    $task->update(['pull_request_head_sha' => str_repeat('c', 40)]);
    app(GitHubPreviews::class)->update($task);
    expect($task->fresh()->preview_error)->toContain('Deployments: Read and write');
})->group('GIT-028');

test("a copy that couldn't be made shows as failed, and one being made as in progress", function () {
    $task = previewTask($this->project);

    expect(GitHubPreviews::state($task))->toBe('success');
    $task->sandbox()->update(['status' => SandboxStatus::Creating]);
    expect(GitHubPreviews::state($task))->toBe('in_progress');
    $task->sandbox()->update(['status' => SandboxStatus::Failed]);
    expect(GitHubPreviews::state($task))->toBe('failure');

    // Without a gateway there's no address to link to, so nothing goes on GitHub.
    config(['sandbox.gateway_domain' => null]);
    UpdatePullRequestPreview::for($task);
    Queue::assertNotPushed(UpdatePullRequestPreview::class);
})->group('GIT-028');
