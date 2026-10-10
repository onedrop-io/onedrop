<?php

use App\Enums\DeploymentStatus;
use App\Enums\MessageRole;
use App\Enums\PublishStatus;
use App\Enums\TurnOutcome;
use App\Jobs\AutoDeploy;
use App\Models\AgentConnection;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Hosting\HostingChanges;
use App\Sandbox\PreviewErrors;
use App\Sandbox\Publishing\FakePublisher;
use App\Sandbox\Publishing\Publisher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config([
        'hosting.providers.fly' => ['enabled' => true, 'api_token' => 'fly-platform', 'org_slug' => 'onedrop', 'region' => 'iad', 'base_image' => 'ghcr.io/onedrop-io/onedrop-sandbox:latest', 'memory_mb' => 1024, 'volume_gb' => 1],
        'hosting.providers.cloudflare.enabled' => false,
        'sandbox.snapshot_disk' => 'releases',
        'filesystems.disks.releases' => ['driver' => 's3'],
    ]);
    Storage::fake('releases', ['serve' => true]);
    Storage::disk('releases')->buildTemporaryUploadUrlsUsing(fn (string $path) => ['url' => "https://bucket.test/{$path}?signed", 'headers' => []]);
    Storage::disk('releases')->buildTemporaryUrlsUsing(fn (string $path) => "https://bucket.test/{$path}?read");
    app()->instance(Publisher::class, new FakePublisher);
    $this->sandboxes = hostingSandbox();
    fakeHostingProviders();

    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->project = Project::factory()->for($this->user)->create(['name' => 'Bake Sale']);
    Sandbox::factory()->for($this->project)->create(['external_id' => 'sbx-1']);

    $this->actingAs($this->user)
        ->post(route('projects.publication.store', $this->project), ['visibility' => 'public', 'target' => 'hosting'])
        ->assertSessionHasNoErrors();
});

test('a deploy records the commit it shipped, and the panel lists what changed since', function () {
    expect($this->project->deployments()->first()->commit)->toBe($this->sandboxes->head);

    $this->sandboxes->changes = "2\nabc1234\tAdd a dark mode\ndef5678\tFix the dose reminder";
    app(HostingChanges::class)->refresh($this->project->fresh());

    $this->actingAs($this->user)
        ->get(route('projects.show', $this->project))
        ->assertInertia(fn ($page) => $page
            ->where('publication.hosting.changes.count', 2)
            ->where('publication.hosting.changes.commits.0', ['sha' => 'abc1234', 'message' => 'Add a dark mode'])
            ->where('publication.hosting.changes.commits.1.message', 'Fix the dose reminder'));

    // Asked about the commits since the live deploy's.
    expect(collect($this->sandboxes->executed)->pluck('env.ONEDROP_FROM')->filter()->last())->toBe($this->sandboxes->head);
})->group('HOST-004');

test('a project that leaves its changes uncommitted ships a checkpoint of its files, and counts checkpoints since', function () {
    $this->project->update(['commit_turns' => false]);
    $this->sandboxes->head = 'cccccccccccccccccccccccccccccccccccccccc';

    $this->actingAs($this->user)->post(route('projects.publication.store', $this->project), ['visibility' => 'public', 'target' => 'hosting']);
    app(HostingChanges::class)->refresh($this->project->fresh());

    $snapshot = collect($this->sandboxes->executed)->first(fn (array $run) => str_contains($run['command'][2] ?? '', 'checkpoint --snapshot'));

    expect($snapshot)->not->toBeNull()
        ->and($this->project->deployments()->latest('id')->first()->commit)->toBe('cccccccccccccccccccccccccccccccccccccccc')
        ->and(collect($this->sandboxes->executed)->pluck('env.ONEDROP_TO')->filter()->last())->toBe('refs/onedrop/checkpoints');
})->group('HOST-004', 'SCM-003');

test('updating clears the changes once the new deploy is live', function () {
    $this->project->update(['hosting_changes' => ['count' => 1, 'commits' => [['sha' => 'abc1234', 'message' => 'Add a dark mode']]]]);
    $this->sandboxes->head = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    $this->actingAs($this->user)->post(route('projects.publication.store', $this->project), ['visibility' => 'public', 'target' => 'hosting']);

    expect($this->project->fresh()->hosting_changes)->toBeNull()
        ->and($this->project->deployments()->latest('id')->first()->commit)->toBe('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');
})->group('HOST-004');

test('a project not published to hosting has no changes to show', function () {
    $this->actingAs($this->user)->delete(route('projects.publication.destroy', $this->project));
    $this->sandboxes->changes = "1\nabc1234\tAdd a dark mode";

    app(HostingChanges::class)->refresh($this->project->fresh());

    expect($this->project->fresh()->hosting_changes)->toBeNull();
})->group('HOST-004');

test('an earlier deploy can be put back without building again', function () {
    $first = $this->project->deployments()->first();
    $this->actingAs($this->user)->post(route('projects.publication.store', $this->project), ['visibility' => 'public', 'target' => 'hosting']);
    $packs = count($this->sandboxes->hostingCommands());

    $this->actingAs($this->user)
        ->post(route('projects.hosting.roll-back', [$this->project, $first]))
        ->assertSessionHasNoErrors();

    $latest = $this->project->deployments()->latest('id')->first();

    expect($latest->number)->toBe(3)
        ->and($latest->status)->toBe(DeploymentStatus::Live)
        ->and($latest->image)->toBe($first->image)
        ->and($latest->commit)->toBe($first->commit)
        ->and($latest->log)->toContain('Putting deploy #1 back')
        ->and($this->project->fresh()->publish_status)->toBe(PublishStatus::Live)
        ->and(count($this->sandboxes->hostingCommands()))->toBe($packs);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/machines/machine_app')
        && $request['config']['image'] === $first->image
        && ! isset($request['config']['env']['ONEDROP_SEED_URL']));

    $this->actingAs($this->user)->get(route('projects.show', $this->project))
        ->assertInertia(fn ($page) => $page
            ->where('publication.hosting.history.0.number', 3)
            ->where('publication.hosting.history.0.live', true)
            ->where('publication.hosting.history.1.live', false));
})->group('HOST-005');

test("another project's deploy, or one that never went live, can't be put back", function () {
    $other = Deployment::factory()->live()->create();
    $failed = Deployment::factory()->for($this->project)->create(['number' => 9, 'status' => DeploymentStatus::Failed, 'kind' => 'server', 'image' => 'registry.fly.io/x:deployment-9']);

    $this->actingAs($this->user)->post(route('projects.hosting.roll-back', [$this->project, $other]))->assertNotFound();
    $this->actingAs($this->user)->post(route('projects.hosting.roll-back', [$this->project, $failed]))
        ->assertSessionHasErrors(['publish' => 'Only an earlier deploy of this app with a server can be put back.']);
})->group('HOST-005');

test('auto-deploy can be turned on and off', function () {
    $this->actingAs($this->user)->patch(route('projects.hosting.update', $this->project), ['auto_deploy' => true])->assertSessionHasNoErrors();
    expect($this->project->fresh()->auto_deploy)->toBeTrue();

    $this->actingAs($this->user)->patch(route('projects.hosting.update', $this->project), ['auto_deploy' => false]);
    expect($this->project->fresh()->auto_deploy)->toBeFalse();
})->group('HOST-006');

test('with auto-deploy on, a turn that went well updates the hosted app', function () {
    $this->project->update(['auto_deploy' => true]);
    $turn = $this->project->messages()->create(['role' => MessageRole::User, 'content' => 'Add a dark mode']);
    $this->sandboxes->changes = "1\nabc1234\tAdd a dark mode";

    (new AutoDeploy($this->project, now()->getTimestampMs(), $turn->id))->handle(app(PreviewErrors::class), app(HostingChanges::class));

    expect($this->project->deployments()->count())->toBe(2)
        ->and($this->project->fresh()->publish_status)->toBe(PublishStatus::Live);
})->group('HOST-006');

test("auto-deploy waits when the preview shows errors, the agent is waiting on the user, there's nothing new, or it's off", function (Closure $setUp) {
    $this->project->update(['auto_deploy' => true]);
    $turn = $this->project->messages()->create(['role' => MessageRole::User, 'content' => 'Add a dark mode']);
    $this->sandboxes->changes = "1\nabc1234\tAdd a dark mode";
    $setUp($this);

    (new AutoDeploy($this->project, now()->subMinute()->getTimestampMs(), $turn->id))->handle(app(PreviewErrors::class), app(HostingChanges::class));

    expect($this->project->deployments()->count())->toBe(1);
})->with([
    'preview errors' => [fn ($test) => $test->sandboxes->previewErrors = json_encode(['t' => now()->getTimestampMs(), 'k' => 'server', 's' => 500, 'm' => 'GET', 'p' => '/doses', 'text' => 'Undefined variable $dose'])],
    'waiting on the user' => [fn ($test) => $test->project->update(['turn_outcome' => TurnOutcome::Question])],
    'nothing new' => [fn ($test) => $test->sandboxes->changes = ''],
    'turned off' => [fn ($test) => $test->project->update(['auto_deploy' => false])],
    'another turn since' => [fn ($test) => $test->project->messages()->create(['role' => MessageRole::User, 'content' => 'And bigger buttons'])],
])->group('HOST-006');

test('auto-deploy is only scheduled after a turn for a hosted project that has it on', function () {
    Queue::fake();

    AutoDeploy::afterTurn($this->project->fresh());
    Queue::assertNotPushed(AutoDeploy::class);

    $this->project->update(['auto_deploy' => true]);
    AutoDeploy::afterTurn($this->project->fresh());
    Queue::assertPushed(AutoDeploy::class);
})->group('HOST-006');

test('a new machine size restarts the live version on it, without building again', function () {
    $live = $this->project->deployments()->first();
    $packs = count($this->sandboxes->hostingCommands());

    $this->actingAs($this->user)
        ->patch(route('projects.hosting.update', $this->project), ['size' => 'large'])
        ->assertSessionHasNoErrors();

    $latest = $this->project->deployments()->latest('id')->first();

    expect($this->project->fresh()->hosting_size)->toBe('large')
        ->and($this->project->fresh()->publish_status)->toBe(PublishStatus::Live)
        ->and($latest->image)->toBe($live->image)
        ->and($latest->log)->toContain('Moving to a new machine size (Large: 2 dedicated CPUs, 4 GB)')
        ->and(count($this->sandboxes->hostingCommands()))->toBe($packs);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/machines/machine_app')
        && $request['config']['guest'] === ['cpu_kind' => 'performance', 'cpus' => 2, 'memory_mb' => 4096]);

    $this->actingAs($this->user)->get(route('projects.show', $this->project))
        ->assertInertia(fn ($page) => $page->where('publication.hosting.size', 'large')->has('publication.hosting.sizes', 4));
})->group('HOST-010');

test('small machines take the install memory setting, and a size that does not exist is refused', function () {
    $machine = Http::recorded(fn (Request $request) => ($request['config']['metadata']['onedrop'] ?? null) === 'app')->last()[0];

    expect($machine['config']['guest'])->toBe(['cpu_kind' => 'shared', 'cpus' => 1, 'memory_mb' => 1024]);

    $this->actingAs($this->user)->patch(route('projects.hosting.update', $this->project), ['size' => 'huge'])->assertSessionHasErrors('size');
})->group('HOST-010');

test('choosing a size before the app is hosted keeps it for the first deploy', function () {
    $this->actingAs($this->user)->delete(route('projects.publication.destroy', $this->project));
    $deployments = $this->project->deployments()->count();

    $this->actingAs($this->user)->patch(route('projects.hosting.update', $this->project), ['size' => 'medium'])->assertSessionHasNoErrors();

    expect($this->project->fresh()->hosting_size)->toBe('medium')
        ->and($this->project->deployments()->count())->toBe($deployments);
})->group('HOST-010');
