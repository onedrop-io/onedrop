<?php

use App\Enums\MessageRole;
use App\Enums\MoveSource;
use App\Enums\OldSandboxStatus;
use App\Enums\ProjectStatus;
use App\Enums\SandboxMovePhase;
use App\Enums\SandboxStatus;
use App\Jobs\MoveProjectSandbox;
use App\Jobs\RecoverSandbox;
use App\Jobs\RunAgentTask;
use App\Jobs\TakeSnapshot;
use App\Jobs\UpdateSandbox;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\ProjectSnapshot;
use App\Models\Sandbox;
use App\Models\SandboxMove;
use App\Models\Task;
use App\Models\User;
use App\Sandbox\ExecResult;
use App\Sandbox\ProjectBackups;
use App\Sandbox\ProjectSnapshots;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\Providers\RoutingSandboxProvider;
use App\Sandbox\Publishing\FakePublisher;
use App\Sandbox\Publishing\Publisher;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxMover;
use App\Sandbox\SandboxProvider;
use App\Sandbox\SandboxProviders;
use App\Sandbox\SandboxSpec;
use App\Sandbox\SandboxTools;
use App\Sandbox\SandboxUpdater;
use App\Sandbox\SandboxWaitLimit;
use App\Sandbox\TemporarySandboxException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia;

/**
 * A provider whose sandboxes answer the snapshot tool, take and unpack snapshots in the background (done by the next
 * status check, or never with $stuck), and stop answering when listed in $dead.
 */
function movingProvider(): FakeSandboxProvider
{
    return new class extends FakeSandboxProvider
    {
        /** @var array<string, string> */
        public array $fingerprints = ['workspace' => 'w1', 'deps' => 'd1', 'home' => 'h1', 'storage' => 's1'];

        /** @var list<string> sandboxes that don't answer */
        public array $dead = [];

        /** Background work never finishes. */
        public bool $stuck = false;

        /** @var array<string, string> status output by work directory */
        public array $work = [];

        /** @var list<array{string, list<string>}> [sandbox, layers] taken */
        public array $taken = [];

        /** @var list<array{string, list<string>}> [sandbox, layers] unpacked */
        public array $unpacked = [];

        /** @var list<string> */
        public array $destroyed = [];

        public function exec(string $id, array $command, array $env = [], bool $detach = false, bool $root = false): ExecResult
        {
            if (in_array($id, $this->dead, true)) {
                throw new TemporarySandboxException("The sandbox didn't answer in time.");
            }

            $this->executed[] = ['id' => $id, 'command' => $command, 'env' => $env, 'detach' => $detach, 'root' => $root];
            $tool = SandboxTools::PATH.'/snapshot';

            if (($command[0] ?? null) !== $tool) {
                return str_contains($command[2] ?? '', 'ONEDROP_TOOL_PATHS')
                    ? new ExecResult(0, collect(app(SandboxTools::class)->expected())->map(fn ($hash, $path) => "{$hash}  {$path}")->implode("\n"))
                    : new ExecResult(0, '');
            }

            return match ($command[1]) {
                'fingerprint' => new ExecResult(0, collect($this->fingerprints)->map(fn ($hash, $layer) => "{$layer} {$hash}")->implode("\n")),
                'compression' => new ExecResult(0, "zst\n"),
                'begin' => tap(new ExecResult(0, ''), fn () => $this->work[$command[2]] = "running\n::error::\n"),
                'take' => $this->background($command[2], function () use ($id, $command) {
                    $this->taken[] = [$id, array_slice($command, 3)];

                    return collect(array_slice($command, 3))->map(fn ($layer) => "{$layer} zst 1048576")->implode("\n");
                }),
                'unpack' => $this->background($command[2], function () use ($id, $command) {
                    $this->unpacked[] = [$id, array_slice($command, 3)];

                    return '';
                }),
                'status' => new ExecResult(0, $this->work[$command[2]] ?? "none\n::error::\n"),
                default => new ExecResult(0, ''),
            };
        }

        /**
         * @param  Closure(): string  $work
         */
        protected function background(string $directory, Closure $work): ExecResult
        {
            if (! $this->stuck) {
                $this->work[$directory] = "done\n".$work()."\n::error::\n";
            }

            return new ExecResult(0, '');
        }

        public function destroy(string $id): void
        {
            $this->destroyed[] = $id;
            parent::destroy($id);
        }
    };
}

beforeEach(function () {
    Sleep::fake(syncWithCarbon: true);
    config(['filesystems.disks.local.driver' => 's3', 'sandbox.snapshot_disk' => 'local', 'sandbox.provider' => 'blaxel']);
    Storage::fake('local', ['serve' => true]);
    Storage::disk('local')->buildTemporaryUploadUrlsUsing(fn (string $path) => ['url' => "https://bucket.test/{$path}?put", 'headers' => []]);
    Storage::disk('local')->buildTemporaryUrlsUsing(fn (string $path) => "https://bucket.test/{$path}?get");
    app()->instance(Publisher::class, new FakePublisher);

    $this->runtime = movingProvider();
    $this->blaxel = movingProvider();
    app()->instance(SandboxProvider::class, new RoutingSandboxProvider([
        'runtime' => fn () => $this->runtime,
        'blaxel' => fn () => $this->blaxel,
    ], 'blaxel'));

    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->project = Project::factory()->for($this->user)->create(['agent_session_id' => 'ses_1']);
    $this->sandbox = Sandbox::factory()->for($this->project)->create(['provider' => 'runtime', 'external_id' => 'rt-1', 'last_active_at' => now()->subHour()]);
    $this->runtime->created['rt-1'] = new SandboxSpec('old');
});

test('a sandbox on a provider that is no longer where its project runs moves at once, through a fresh snapshot', function () {
    expect(app(SandboxMover::class)->moveMisplaced())->toBe(1);

    $move = $this->project->sandboxMoves()->sole();
    $sandbox = $this->sandbox->fresh();

    expect($move->phase)->toBe(SandboxMovePhase::Done)
        ->and($move->source)->toBe(MoveSource::Fresh)
        ->and($move->old_status)->toBe(OldSandboxStatus::Removed)
        ->and($sandbox->provider)->toBe('blaxel')
        ->and($sandbox->status)->toBe(SandboxStatus::Running)
        ->and($this->blaxel->created)->toHaveKey($sandbox->external_id)
        // Packed and uploaded by the old sandbox, unpacked by the new one, both in the background.
        ->and($this->runtime->taken)->toBe([['rt-1', ProjectSnapshots::LAYERS]])
        ->and($this->blaxel->unpacked)->toBe([[$sandbox->external_id, ProjectSnapshots::LAYERS]])
        ->and($this->runtime->paused)->toBe(['rt-1'])
        ->and($this->runtime->destroyed)->toBe(['rt-1'])
        ->and(collect($this->blaxel->executed)->pluck('command')->all())->toContain(['/opt/onedrop/restart'])
        ->and($move->snapshot->status)->toBe(ProjectSnapshot::READY)
        ->and($this->project->fresh()->agent_session_id)->toBe('ses_1')
        // Nobody was using it, so it doesn't keep running.
        ->and($this->blaxel->suspended)->toBe([$sandbox->external_id]);
})->group('SBX-005');

test('turning a provider off in Settings moves its sandboxes, without a command', function () {
    Queue::fake();
    fakeSandboxImages();
    config(['sandbox.provider' => 'runtime', 'sandbox.providers.runtime.api_key' => 'rt-key', 'sandbox.providers.blaxel.api_key' => 'bl-key', 'sandbox.providers.blaxel.workspace' => 'acme']);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->put(route('admin.sandboxes.update', 'blaxel'), ['enabled' => true]);
    $this->actingAs($admin)->put(route('admin.sandboxes.reorder'), ['providers' => ['runtime', 'blaxel', 'docker']]);

    expect(SandboxMove::query()->count())->toBe(0);

    $this->actingAs($admin)->put(route('admin.sandboxes.update', 'runtime'), ['enabled' => false])
        ->assertSessionHas('inertia.flash_data.toast.message', 'New projects now run on Blaxel. Moving 1 sandbox to Blaxel.');

    $move = $this->project->sandboxMoves()->sole();

    expect($move->to_provider)->toBe('blaxel')
        ->and($move->from_external_id)->toBe('rt-1');
    Queue::assertPushed(MoveProjectSandbox::class, fn (MoveProjectSandbox $job) => $job->move->is($move));
})->group('SBX-005', 'ADMIN-002');

test('putting another provider first moves sandboxes to it at once', function () {
    Queue::fake();
    fakeSandboxImages();
    config(['sandbox.provider' => 'runtime', 'sandbox.providers.runtime.api_key' => 'rt-key', 'sandbox.providers.blaxel.api_key' => 'bl-key', 'sandbox.providers.blaxel.workspace' => 'acme']);
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->put(route('admin.sandboxes.update', 'blaxel'), ['enabled' => true]);
    $this->actingAs($admin)->put(route('admin.sandboxes.reorder'), ['providers' => ['runtime', 'blaxel', 'docker']]);

    $this->actingAs($admin)->put(route('admin.sandboxes.reorder'), ['providers' => ['blaxel', 'runtime', 'docker']]);

    expect($this->project->sandboxMoves()->sole()->to_provider)->toBe('blaxel');
})->group('SBX-005', 'ADMIN-002');

test('each attempt works for under a minute, then puts itself back while the sandbox works in the background', function () {
    $this->runtime->stuck = true;
    $move = app(SandboxMover::class)->start($this->sandbox, 'provider', queue: false);
    $job = (new MoveProjectSandbox($move))->withFakeQueueInteractions();

    app()->call([$job, 'handle']);

    $job->assertReleased(delay: MoveProjectSandbox::CHECK_SECONDS);
    expect($move->fresh()->phase)->toBe(SandboxMovePhase::Snapshotting)
        ->and(collect($this->runtime->executed)->where('command.1', 'take')->sole()['detach'])->toBeTrue()
        ->and(MoveProjectSandbox::STEP_SECONDS + 2 * MoveProjectSandbox::CHECK_SECONDS)->toBeLessThan(90)
        ->and($this->sandbox->fresh()->external_id)->toBe('rt-1');

    // The sandbox finishes; the next attempt carries on from there.
    $this->runtime->stuck = false;
    $this->runtime->work = array_map(fn ($status) => "done\nworkspace zst 1\ndeps zst 1\nhome zst 1\nstorage zst 1\n::error::\n", $this->runtime->work);
    app()->call([(new MoveProjectSandbox($move))->withFakeQueueInteractions(), 'handle']);

    expect($move->fresh()->phase)->toBe(SandboxMovePhase::Done)
        ->and($this->sandbox->fresh()->provider)->toBe('blaxel');
})->group('SBX-005');

test('a move waits for the agent to finish its turn in a sandbox that answers', function () {
    $this->project->update(['status' => ProjectStatus::Working]);
    $move = app(SandboxMover::class)->start($this->sandbox, 'provider', queue: false);

    expect(app(SandboxMover::class)->step($move))->toBeFalse()
        ->and($move->fresh()->phase)->toBe(SandboxMovePhase::Starting)
        ->and($move->fresh()->messages)->toBe(['Waiting for the agent to finish its turn.'])
        ->and($this->runtime->taken)->toBe([]);

    $this->project->update(['status' => ProjectStatus::Idle]);

    expect(app(SandboxMover::class)->run($move)->phase)->toBe(SandboxMovePhase::Done);
})->group('SBX-005');

test('a message sent while its sandbox moves runs once the move is done', function () {
    Queue::fake();
    SandboxMove::query()->create(['project_id' => $this->project->id, 'sandbox_id' => $this->sandbox->id, 'reason' => 'provider', 'phase' => SandboxMovePhase::Restoring]);
    $message = $this->project->messages()->create(['role' => MessageRole::User, 'content' => 'Add a page']);

    app()->call([new RunAgentTask($this->project, $message), 'handle']);

    expect($this->project->fresh()->status)->not->toBe(ProjectStatus::Working);
    Queue::assertPushed(RunAgentTask::class, fn (RunAgentTask $job) => $job->message->is($message) && $job->delay !== null);
})->group('SBX-005');

test('a sandbox that doesn\'t answer moves from the latest snapshot, and is kept and checked on', function () {
    Queue::fake([RecoverSandbox::class]);
    $earlier = ProjectSnapshot::factory()->for($this->project)->create(['created_at' => now()->subHours(4)]);
    $this->runtime->dead = ['rt-1'];
    $this->project->update(['status' => ProjectStatus::Working]);

    app(SandboxMover::class)->moveMisplaced();

    $move = $this->project->sandboxMoves()->sole();
    $sandbox = $this->sandbox->fresh();

    expect($move->phase)->toBe(SandboxMovePhase::Done)
        ->and($move->from_answered)->toBeFalse()
        ->and($move->source)->toBe(MoveSource::Snapshot)
        ->and($move->snapshot_id)->toBe($earlier->id)
        ->and($move->old_status)->toBe(OldSandboxStatus::Waiting)
        ->and($sandbox->provider)->toBe('blaxel')
        ->and($this->blaxel->unpacked)->toBe([[$sandbox->external_id, ProjectSnapshots::LAYERS]])
        // Never deleted while it may hold newer files.
        ->and($this->runtime->destroyed)->toBe([])
        // A turn it never finished is over, and the chat says where the files came from.
        ->and($this->project->fresh()->status)->toBe(ProjectStatus::Idle)
        ->and($this->project->messages()->latest('id')->first()->content)->toContain('Runtime Cloud stopped answering')
        ->and($this->project->messages()->latest('id')->first()->content)->toContain('the snapshot from');
    Queue::assertPushed(RecoverSandbox::class, fn (RecoverSandbox $job) => $job->move->is($move) && $job->delay !== null);
})->group('SBX-013');

test('without a snapshot, a sandbox that doesn\'t answer moves with its code backup; with neither it stays', function () {
    Queue::fake([RecoverSandbox::class]);
    $this->runtime->dead = ['rt-1'];

    app(SandboxMover::class)->moveMisplaced();

    $move = $this->project->sandboxMoves()->sole();

    expect($move->phase)->toBe(SandboxMovePhase::Failed)
        ->and($move->error)->toContain('no snapshot or backup')
        ->and($this->sandbox->fresh()->external_id)->toBe('rt-1')
        ->and($this->blaxel->created)->toBe([]);

    Storage::disk('local')->put("backups/projects/{$this->project->id}/repo.bundle", 'bundle');
    app()->instance(ProjectBackups::class, Mockery::mock(ProjectBackups::class, function ($mock) {
        $mock->shouldReceive('exists')->andReturnTrue();
        $mock->shouldReceive('restore')->once()->andReturnTrue();
    }));
    app()->forgetInstance(SandboxMover::class);

    app(SandboxMover::class)->moveMisplaced();

    expect($this->project->sandboxMoves()->latest('id')->first()->source)->toBe(MoveSource::Backup)
        ->and($this->sandbox->fresh()->provider)->toBe('blaxel')
        ->and($this->project->fresh()->agent_session_id)->toBeNull();
})->group('SBX-013');

test('when the old sandbox answers again, its newer files are saved and it is deleted; an admin can restore them', function () {
    Queue::fake([RecoverSandbox::class]);
    ProjectSnapshot::factory()->for($this->project)->create(['layers' => collect(ProjectSnapshots::LAYERS)->mapWithKeys(fn ($layer) => [$layer => ['path' => "p/{$layer}", 'fingerprint' => "{$layer[0]}1", 'compression' => 'zst', 'size' => 1]])->all()]);
    $this->runtime->dead = ['rt-1'];
    app(SandboxMover::class)->moveMisplaced();
    $move = $this->project->sandboxMoves()->sole();

    // Still down: checked again in 15 minutes.
    expect(app(SandboxMover::class)->recover($move))->toBe(RecoverSandbox::CHECK_MINUTES * 60);

    $this->runtime->dead = [];
    $this->runtime->fingerprints['workspace'] = 'w2';

    expect(app(SandboxMover::class)->recover($move->fresh()))->toBeNull();

    $move->refresh();

    expect($move->old_status)->toBe(OldSandboxStatus::Recovered)
        ->and($move->recoveredSnapshot->reason)->toBe(ProjectSnapshot::RECOVERED)
        ->and($this->runtime->taken)->toBe([['rt-1', ['workspace']]])
        ->and($this->runtime->destroyed)->toBe(['rt-1'])
        // Never what a later move gets by itself.
        ->and(app(ProjectSnapshots::class)->latest($this->project)->id)->not->toBe($move->recovered_snapshot_id);

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->get(route('admin.sandboxes.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('moves.0.id', $move->id)->where('moves.0.old_status', 'recovered'));

    $this->actingAs($admin)->post(route('admin.sandboxes.moves.restore', $move))->assertRedirect(route('admin.sandboxes.index'));

    $restore = $this->project->sandboxMoves()->latest('id')->first();

    expect($restore->reason)->toBe('restore')
        ->and($restore->snapshot_id)->toBe($move->recovered_snapshot_id)
        ->and($restore->phase)->toBe(SandboxMovePhase::Done);

    $this->actingAs($admin)->get(route('admin.sandboxes.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('moves', fn ($moves) => collect($moves)->doesntContain('old_status', 'recovered')));
})->group('SBX-013');

test('an old sandbox that answers again with nothing newer is just deleted', function () {
    Queue::fake([RecoverSandbox::class]);
    ProjectSnapshot::factory()->for($this->project)->create(['layers' => collect(ProjectSnapshots::LAYERS)->mapWithKeys(fn ($layer) => [$layer => ['path' => "p/{$layer}", 'fingerprint' => "{$layer[0]}1", 'compression' => 'zst', 'size' => 1]])->all()]);
    $this->runtime->dead = ['rt-1'];
    app(SandboxMover::class)->moveMisplaced();
    $this->runtime->dead = [];

    $move = $this->project->sandboxMoves()->sole();

    expect(app(SandboxMover::class)->recover($move))->toBeNull()
        ->and($move->fresh()->old_status)->toBe(OldSandboxStatus::Removed)
        ->and($move->fresh()->recovered_snapshot_id)->toBeNull()
        ->and($this->runtime->taken)->toBe([])
        ->and($this->runtime->destroyed)->toBe(['rt-1']);
})->group('SBX-013');

test('a move that fails leaves the project on its old sandbox, and an admin can try it again', function () {
    $blaxel = $this->blaxel;
    $this->blaxel->dead = ['never'];
    $failing = new class extends FakeSandboxProvider
    {
        public function create(SandboxSpec $spec): string
        {
            throw new SandboxException('Blaxel: quota exceeded');
        }
    };
    app()->instance(SandboxProvider::class, new RoutingSandboxProvider(['runtime' => fn () => $this->runtime, 'blaxel' => fn () => $failing], 'blaxel'));
    app()->forgetInstance(SandboxMover::class);

    app(SandboxMover::class)->moveMisplaced();

    $move = $this->project->sandboxMoves()->sole();

    expect($move->phase)->toBe(SandboxMovePhase::Failed)
        ->and($move->error)->toBe('Blaxel: quota exceeded')
        ->and($this->sandbox->fresh()->external_id)->toBe('rt-1')
        // Its app was stopped for the snapshot, and carries on.
        ->and($this->runtime->started)->toBe(['rt-1']);

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->get(route('admin.sandboxes.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('moves.0.phase', 'failed')->where('moves.0.error', 'Blaxel: quota exceeded'));

    app()->instance(SandboxProvider::class, new RoutingSandboxProvider(['runtime' => fn () => $this->runtime, 'blaxel' => fn () => $blaxel], 'blaxel'));
    app()->forgetInstance(SandboxMover::class);
    $this->actingAs($admin)->post(route('admin.sandboxes.moves.retry', $move))->assertRedirect();

    expect($this->sandbox->fresh()->provider)->toBe('blaxel')
        ->and($this->project->sandboxMoves()->latest('id')->first()->phase)->toBe(SandboxMovePhase::Done);

    $this->actingAs($admin)->get(route('admin.sandboxes.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('moves', 0));
})->group('SBX-013');

test('a provider that is full is waited on, not failed', function () {
    $full = new class extends FakeSandboxProvider
    {
        public function create(SandboxSpec $spec): string
        {
            throw new TemporarySandboxException('Runtime has no room for the sandbox right now. Try again in a moment.');
        }
    };
    app()->instance(SandboxProvider::class, new RoutingSandboxProvider(['runtime' => fn () => $this->runtime, 'blaxel' => fn () => $full], 'blaxel'));
    app()->forgetInstance(SandboxMover::class);
    $move = app(SandboxMover::class)->start($this->sandbox, 'provider', queue: false);

    expect(app(SandboxMover::class)->step($move))->toBeFalse()
        ->and($move->fresh()->phase)->toBe(SandboxMovePhase::Creating);

    $this->travel(SandboxMover::GIVE_UP_MINUTES + 1)->minutes();

    expect(app(SandboxMover::class)->step($move->fresh()))->toBeTrue()
        ->and($move->fresh()->phase)->toBe(SandboxMovePhase::Failed);
})->group('SBX-005');

test('a step makes a sandbox only with enough time left for it', function () {
    $move = app(SandboxMover::class)->start($this->sandbox, 'provider', queue: false);
    app(SandboxWaitLimit::class)->start(SandboxMover::CREATE_SECONDS - 5);

    expect(app(SandboxMover::class)->step($move))->toBeFalse()
        ->and($move->fresh()->phase)->toBe(SandboxMovePhase::Creating)
        ->and($this->blaxel->created)->toBe([]);
})->group('SBX-005');

test('a task\'s copy moves too, through a snapshot of its own that is deleted afterwards', function () {
    $task = Task::factory()->for($this->project)->create();
    $copy = Sandbox::factory()->for($this->project)->create(['task_id' => $task->id, 'provider' => 'runtime', 'external_id' => 'rt-copy']);

    expect(app(SandboxMover::class)->moveMisplaced())->toBe(2);

    $move = SandboxMove::query()->where('sandbox_id', $copy->id)->sole();

    expect($move->phase)->toBe(SandboxMovePhase::Done)
        ->and($copy->fresh()->provider)->toBe('blaxel')
        ->and($this->runtime->taken)->toContain(['rt-copy', ProjectSnapshots::LAYERS])
        ->and($move->snapshot_id)->toBeNull() // carried, then deleted
        ->and(ProjectSnapshot::query()->whereNotNull('task_id')->count())->toBe(0)
        ->and(app(ProjectSnapshots::class)->latest($this->project)->task_id)->toBeNull();
})->group('SBX-005');

test('a project going unused gets a snapshot, so work done only in the Shell is kept', function () {
    Queue::fake([TakeSnapshot::class]);
    config(['sandbox.provider' => 'runtime']);
    $this->sandbox->forceFill(['last_active_at' => now()->subMinutes(11)])->save();

    app()->call([(new UpdateSandbox($this->project))->withFakeQueueInteractions(), 'handle']);

    Queue::assertPushed(TakeSnapshot::class, fn (TakeSnapshot $job) => $job->reason === 'idle' && $job->suspendAfter);
})->group('SBX-009');

test('a snapshot job checks on the sandbox\'s background work and puts itself back until it\'s done', function () {
    config(['sandbox.provider' => 'runtime']);
    $this->runtime->stuck = true;
    $job = (new TakeSnapshot($this->project, 'idle', suspendAfter: true))->withFakeQueueInteractions();

    app()->call([$job, 'handle']);

    $job->assertReleased();
    $pending = $this->project->snapshots()->sole();
    expect($pending->status)->toBe(ProjectSnapshot::PENDING)
        ->and($this->runtime->suspended)->toBe([]);

    $this->runtime->work = array_map(fn () => "done\nworkspace zst 5\n::error::\n", $this->runtime->work);
    app()->call([(new TakeSnapshot($this->project, 'idle', suspendAfter: true))->withFakeQueueInteractions(), 'handle']);

    expect($pending->fresh()->status)->toBe(ProjectSnapshot::READY)
        ->and($this->project->snapshots()->count())->toBe(1) // the same one, not a second
        ->and($this->runtime->suspended)->toBe(['rt-1']);
})->group('SBX-009');

test('sandbox:update waits for the move and says how it went', function () {
    $this->artisan('sandbox:update', ['project' => $this->project->id])
        ->expectsOutputToContain("Updated project {$this->project->id}.")
        ->assertSuccessful();

    expect($this->sandbox->fresh()->provider)->toBe('blaxel')
        ->and(app(SandboxUpdater::class)->isOutdated($this->project->fresh()))->toBeFalse();
})->group('SBX-005');

test('a move that hasn\'t started is called off when the toggles change back', function () {
    $move = app(SandboxMover::class)->start($this->sandbox, 'provider', queue: false);
    config(['sandbox.provider' => 'runtime']);

    expect(app(SandboxMover::class)->step($move))->toBeTrue()
        ->and($move->fresh()->phase)->toBe(SandboxMovePhase::Done)
        ->and($move->fresh()->messages)->toBe(['No longer needed: the project runs on Runtime Cloud again.'])
        ->and($this->runtime->taken)->toBe([])
        ->and($this->blaxel->created)->toBe([])
        ->and($this->sandbox->fresh()->external_id)->toBe('rt-1');
})->group('SBX-005');

test('a provider without its sandbox image can\'t become the one projects move to', function () {
    Queue::fake();
    Http::fake([
        '*/images/sandbox/*' => Http::response(['error' => 'not found'], 404),
        '*/images/resolve*' => Http::response(['id' => 'img-1']),
    ]);
    config(['sandbox.provider' => 'runtime', 'sandbox.providers.runtime.api_key' => 'rt-key', 'sandbox.providers.blaxel.api_key' => 'bl-key', 'sandbox.providers.blaxel.workspace' => 'acme']);
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->put(route('admin.sandboxes.update', 'blaxel'), ['enabled' => true]);
    $this->actingAs($admin)->put(route('admin.sandboxes.reorder'), ['providers' => ['runtime', 'blaxel', 'docker']]);

    $this->actingAs($admin)->put(route('admin.sandboxes.reorder'), ['providers' => ['blaxel', 'runtime', 'docker']])
        ->assertSessionHasErrors(['providers' => "Projects can't move to Blaxel yet: The sandbox image [onedrop-sandbox] isn't on Blaxel yet. Run `php artisan sandbox:build-image`."]);
    $this->actingAs($admin)->put(route('admin.sandboxes.update', 'runtime'), ['enabled' => false])
        ->assertSessionHasErrors('enabled');

    expect(config('sandbox.provider'))->toBe('runtime')
        ->and(app(SandboxProviders::class)->enabled())->toBe(['runtime', 'blaxel'])
        ->and(SandboxMove::query()->count())->toBe(0);
})->group('SBX-005', 'ADMIN-002');

test('a move whose new sandbox couldn\'t be made fails before waking or snapshotting the old one', function () {
    $this->blaxel->missingImage = "The sandbox image [onedrop-sandbox] isn't on Blaxel yet.";

    app(SandboxMover::class)->moveMisplaced();

    expect($this->project->sandboxMoves()->sole()->error)->toBe("The sandbox image [onedrop-sandbox] isn't on Blaxel yet.")
        ->and($this->runtime->executed)->toBe([])
        ->and($this->runtime->paused)->toBe([]);
})->group('SBX-005');
