<?php

use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Jobs\CheckPreviewErrors;
use App\Jobs\RunAgentTask;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Agents\AgentQueue;
use App\Sandbox\ExecResult;
use App\Sandbox\PreviewErrors;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->provider = new FakeSandboxProvider;
    app()->instance(SandboxProvider::class, $this->provider);

    $this->project = Project::factory()->create(['status' => ProjectStatus::Idle]);
    $this->sandbox = Sandbox::factory()->for($this->project)->create(['external_id' => 'ctr-1']);
    $this->turn = $this->project->messages()->create(['role' => MessageRole::User, 'content' => 'Add a dashboard']);
    $this->since = 1_700_000_000_000;

    /** The preview's probe answers $status and errors.log holds $entries. */
    $this->recorded = function (string $status, array $entries): void {
        $log = implode("\n", array_map(fn (array $entry) => json_encode($entry), $entries));
        $this->provider->execUsing = fn () => new ExecResult(0, "{$status}\n{$log}\n");
    };
});

test('new preview errors after a turn are sent to the agent once', function () {
    Queue::fake([RunAgentTask::class]);
    ($this->recorded)('500', [
        ['t' => $this->since - 1000, 'k' => 'server', 's' => 500, 'm' => 'GET', 'p' => '/old', 'pub' => false],
        ['t' => $this->since + 10, 'k' => 'server', 's' => 500, 'm' => 'GET', 'p' => '/', 'text' => 'ViteManifestNotFoundException Vite manifest not found', 'pub' => false],
        ['t' => $this->since + 20, 'k' => 'browser', 'type' => 'error', 'msg' => 'x is not defined', 'page' => '/dashboard', 'pub' => false],
    ]);

    (new CheckPreviewErrors($this->project, $this->since, $this->turn->id))->handle(app(PreviewErrors::class), app(AgentQueue::class));

    $request = $this->project->messages()->reorder()->latest('id')->first();

    expect($request->role)->toBe(MessageRole::User)
        ->and($request->content)
        ->toContain('Server error 500 on GET /: ViteManifestNotFoundException Vite manifest not found')
        ->toContain('Browser error on /dashboard: x is not defined')
        ->toContain('/workspace/.zap/errors.log')
        ->not->toContain('/old')
        ->and($this->project->fresh()->status)->toBe(ProjectStatus::Working)
        ->and($this->provider->executed[0]['command'][2])->toContain('http://127.0.0.1:${PROXY_PORT:-8081}/');
    Queue::assertPushed(RunAgentTask::class);

    // The run that request starts doesn't get another automatic one.
    Queue::fake([CheckPreviewErrors::class]);
    CheckPreviewErrors::afterTurn($this->project);
    Queue::assertNotPushed(CheckPreviewErrors::class);
})->group('ERR-001');

test('published-address errors, console output and a restart the app recovered from are ignored', function () {
    ($this->recorded)('200', [
        ['t' => $this->since + 10, 'k' => 'server', 's' => 500, 'm' => 'GET', 'p' => '/', 'pub' => true],
        ['t' => $this->since + 20, 'k' => 'browser', 'type' => 'console', 'msg' => 'Warning: each child needs a key', 'pub' => false],
        ['t' => $this->since + 30, 'k' => 'down', 'm' => 'GET', 'p' => '/', 'pub' => false],
    ]);

    expect(app(PreviewErrors::class)->check($this->sandbox, $this->since))->toBe([]);
})->group('ERR-001');

test('the app not answering after a turn counts as an error', function () {
    ($this->recorded)('502', [['t' => $this->since + 30, 'k' => 'down', 'm' => 'GET', 'p' => '/', 'pub' => false]]);

    expect(PreviewErrors::request(app(PreviewErrors::class)->check($this->sandbox, $this->since)))
        ->toContain("The app didn't answer on GET /");
})->group('ERR-001');

test('nothing is sent when the preview is fine, the agent is busy again, or another turn has ended since', function (Closure $setUp) {
    ($this->recorded)('200', [['t' => $this->since + 10, 'k' => 'browser', 'type' => 'error', 'msg' => 'boom', 'pub' => false]]);
    $setUp->call($this);
    $count = $this->project->messages()->count();

    (new CheckPreviewErrors($this->project, $this->since, $this->turn->id))->handle(app(PreviewErrors::class), app(AgentQueue::class));

    expect($this->project->messages()->count())->toBe($count);
})->with([
    'no errors' => [function () {
        ($this->recorded)('200', []);
    }],
    'busy' => [function () {
        $this->project->update(['status' => ProjectStatus::Working]);
    }],
    'autofix off' => [function () {
        $this->project->update(['autofix' => false]);
    }],
    'newer turn' => [function () {
        $this->project->messages()->create(['role' => MessageRole::User, 'content' => 'Something else']);
    }],
])->group('ERR-001');

test('a successful turn schedules the check, a failed one does not', function (int $code, bool $scheduled) {
    Queue::fake([CheckPreviewErrors::class]);
    $this->project->update(['status' => ProjectStatus::Working]);

    $this->withToken($this->sandbox->issueEventsToken())
        ->postJson(route('sandbox-events.store', $this->sandbox), ['events' => [['type' => 'zap.exit', 'code' => $code, 'stderr' => '']]])
        ->assertOk();

    $scheduled
        ? Queue::assertPushed(CheckPreviewErrors::class, fn (CheckPreviewErrors $job) => $job->turn === $this->turn->id)
        : Queue::assertNotPushed(CheckPreviewErrors::class);
})->with([
    'success' => [0, true],
    'failure' => [1, false],
])->group('ERR-001');

test('a project with autofix off is not checked after a turn', function () {
    Queue::fake([CheckPreviewErrors::class]);
    $this->project->update(['autofix' => false]);

    CheckPreviewErrors::afterTurn($this->project);

    Queue::assertNotPushed(CheckPreviewErrors::class);
})->group('ERR-001');

test('the owner can turn autofix off and on; others cannot', function () {
    AgentConnection::factory()->for($this->project->user)->create();

    expect($this->project->fresh()->autofix)->toBeTrue();

    $this->actingAs($this->project->user)
        ->patch(route('projects.agent.autofix', $this->project), ['autofix' => false])
        ->assertRedirect(route('projects.show', $this->project));
    expect($this->project->fresh()->autofix)->toBeFalse();

    $this->actingAs($this->project->user)
        ->patch(route('projects.agent.autofix', $this->project), ['autofix' => true]);
    expect($this->project->fresh()->autofix)->toBeTrue();

    $this->actingAs(User::factory()->has(AgentConnection::factory())->create())
        ->patch(route('projects.agent.autofix', $this->project), ['autofix' => false])
        ->assertForbidden();
    expect($this->project->fresh()->autofix)->toBeTrue();
})->group('ERR-001');
