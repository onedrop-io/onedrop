<?php

use App\Enums\ProjectStatus;
use App\Enums\PublishStatus;
use App\Enums\PublishVisibility;
use App\Enums\SandboxStatus;
use App\Jobs\RunAgentTask;
use App\Jobs\UpdateSandbox;
use App\Models\AgentConnection;
use App\Models\Message;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\Task;
use App\Models\User;
use App\Sandbox\Agents\AgentRunner;
use App\Sandbox\Agents\Conversation;
use App\Sandbox\ExecResult;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\Publishing\FakePublisher;
use App\Sandbox\Publishing\Publisher;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxProvider;
use App\Sandbox\SandboxSpec;
use App\Sandbox\SandboxTools;
use App\Sandbox\SandboxUpdater;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->provider = new FakeSandboxProvider;
    app()->instance(SandboxProvider::class, $this->provider);
    app()->instance(Publisher::class, new FakePublisher);

    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->project = Project::factory()->for($this->user)->create(['agent_session_id' => 'ses_1']);
    $this->sandbox = Sandbox::factory()->for($this->project)->create(['external_id' => 'old-ctr']);
});

test('an outdated sandbox moves to the current image with its files, agent history and publication', function () {
    $this->project->update(['publish_status' => PublishStatus::Live, 'publish_visibility' => PublishVisibility::Public]);
    $this->provider->outdated = ['old-ctr'];

    expect(app(SandboxUpdater::class)->updateIfOutdated($this->project))->toBeTrue();

    $sandbox = $this->sandbox->fresh();
    $copies = collect($this->provider->copied);

    expect($sandbox->status)->toBe(SandboxStatus::Running)
        ->and($sandbox->external_id)->not->toBe('old-ctr')
        ->and($this->provider->paused)->toBe(['old-ctr']) // stopped before copying, so a database's files are at rest
        ->and($copies->where(0, 'out')->pluck(2)->all())->toBe(SandboxUpdater::KEPT_PATHS)
        ->and($copies->where(0, 'out')->pluck(1)->unique()->all())->toBe(['old-ctr'])
        ->and($copies->where(0, 'in')->pluck(2)->all())->toBe(SandboxUpdater::KEPT_PATHS)
        ->and($copies->where(0, 'in')->pluck(1)->unique()->all())->toBe([$sandbox->external_id])
        ->and(collect($this->provider->executed)->pluck('command')->all())->toContain(['/opt/onedrop/restart'])
        ->toContain(['bash', '-c', SandboxUpdater::USE_IMAGE_SHELL_SETUP]) // old shell files give way to the image's
        ->and($this->project->fresh()->agent_session_id)->toBe('ses_1')
        ->and($this->project->fresh()->publish_status)->toBe(PublishStatus::Live)
        ->and(SandboxUpdater::isUpdating($this->project))->toBeFalse();
})->group('SBX-002');

test('when copying files out fails, the old sandbox starts again and stays', function () {
    $this->provider->outdated = ['old-ctr'];
    $provider = new class extends FakeSandboxProvider
    {
        public function copyOut(string $id, string $path, string $directory): void
        {
            throw new SandboxException('Docker error: disk full');
        }
    };
    $provider->outdated = ['old-ctr'];
    app()->instance(SandboxProvider::class, $provider);

    expect(fn () => app(SandboxUpdater::class)->updateIfOutdated($this->project, wait: true))->toThrow(SandboxException::class, 'disk full');

    expect($provider->paused)->toBe(['old-ctr'])
        ->and($provider->started)->toBe(['old-ctr'])
        ->and($this->sandbox->fresh()->external_id)->toBe('old-ctr')
        ->and($this->sandbox->fresh()->status)->toBe(SandboxStatus::Running)
        ->and(glob(storage_path('framework/sandbox-backup-*')))->toBe([]);
})->group('SBX-002');

test('any failure while copying files out starts the old sandbox again', function () {
    $provider = new class extends FakeSandboxProvider
    {
        public function copyOut(string $id, string $path, string $directory): void
        {
            throw new RuntimeException('Connection reset by peer');
        }
    };
    $provider->outdated = ['old-ctr'];
    app()->instance(SandboxProvider::class, $provider);

    // Not a provider's own error, so it isn't shown as it is.
    expect(fn () => app(SandboxUpdater::class)->updateIfOutdated($this->project, wait: true))->toThrow(SandboxException::class, 'Something went wrong');

    expect($provider->started)->toBe(['old-ctr'])
        ->and($this->sandbox->fresh()->external_id)->toBe('old-ctr')
        ->and(glob(storage_path('framework/sandbox-backup-*')))->toBe([]);
})->group('SBX-002');

/**
 * A fake that records the order of copies and deletions, and can fail copying into the new sandbox.
 */
function orderedProvider(bool $failCopyIn = false): FakeSandboxProvider
{
    $provider = new class($failCopyIn) extends FakeSandboxProvider
    {
        /** @var list<string> */
        public array $events = [];

        public function __construct(public bool $failCopyIn) {}

        public function copyIn(string $id, string $directory, string $path): void
        {
            if ($this->failCopyIn) {
                throw new RuntimeException('Worker killed');
            }

            $this->events[] = "in:{$id}";
        }

        public function destroy(string $id): void
        {
            $this->events[] = "destroy:{$id}";
            parent::destroy($id);
        }
    };
    $provider->outdated = ['old-ctr'];
    app()->instance(SandboxProvider::class, $provider);

    return $provider;
}

test('the old sandbox is deleted only once the new one has every file', function () {
    $provider = orderedProvider();

    expect(app(SandboxUpdater::class)->updateIfOutdated($this->project))->toBeTrue();

    $new = $this->sandbox->fresh()->external_id;

    expect($provider->events)->toBe([...array_fill(0, count(SandboxUpdater::KEPT_PATHS), "in:{$new}"), 'destroy:old-ctr']);
})->group('SBX-002');

test('an update cut off while copying files in goes back to the old sandbox with its files', function () {
    $this->sandbox->update(['preview_url' => 'https://old.preview.test']);
    $provider = orderedProvider(failCopyIn: true);

    expect(fn () => app(SandboxUpdater::class)->updateIfOutdated($this->project, wait: true))->toThrow(SandboxException::class, 'Something went wrong');

    $sandbox = $this->sandbox->fresh();

    expect($sandbox->external_id)->toBe('old-ctr')
        ->and($sandbox->status)->toBe(SandboxStatus::Running)
        ->and($sandbox->preview_url)->toBe('https://old.preview.test')
        ->and($provider->events)->not->toContain('destroy:old-ctr')
        ->and($provider->events)->toHaveCount(1) // the new sandbox, removed
        ->and($provider->started)->toBe(['old-ctr'])
        ->and(glob(storage_path('framework/sandbox-backup-*')))->toBe([]);
})->group('SBX-002');

test('a new sandbox that fails to start leaves the project on the old one', function () {
    $provider = new class extends FakeSandboxProvider
    {
        public function create(SandboxSpec $spec): string
        {
            throw new SandboxException('Blaxel: no capacity');
        }
    };
    $provider->outdated = ['old-ctr'];
    app()->instance(SandboxProvider::class, $provider);

    expect(fn () => app(SandboxUpdater::class)->updateIfOutdated($this->project, wait: true))->toThrow(SandboxException::class, 'no capacity');

    // Its files were never copied, so it was never stopped.
    expect($this->sandbox->fresh()->external_id)->toBe('old-ctr')
        ->and($this->sandbox->fresh()->status)->toBe(SandboxStatus::Running)
        ->and($provider->paused)->toBe([])
        ->and($this->project->sandboxMoves()->sole()->error)->toBe('Blaxel: no capacity');
})->group('SBX-002');

test('a batch update suspends each sandbox it updated, so they don\'t all keep running', function () {
    $this->provider->outdated = ['old-ctr'];

    $this->artisan('sandbox:update')->assertSuccessful();

    $new = $this->sandbox->fresh()->external_id;

    expect($new)->not->toBe('old-ctr')
        ->and($this->provider->suspended)->toBe([$new]);
})->group('SBX-002');

test('an up-to-date or stopped sandbox is left alone', function () {
    expect(app(SandboxUpdater::class)->updateIfOutdated($this->project))->toBeFalse();

    $this->provider->outdated = ['old-ctr'];
    $this->sandbox->update(['status' => SandboxStatus::Paused]);

    expect(app(SandboxUpdater::class)->updateIfOutdated($this->project))->toBeFalse()
        ->and($this->sandbox->fresh()->external_id)->toBe('old-ctr')
        ->and($this->provider->copied)->toBe([]);
})->group('SBX-002');

/**
 * Answers the fake's hash command as a sandbox would: every tool and base file as the source has them, except $missing
 * (paths in the sandbox), and the base files from an older image when $oldBase.
 *
 * @param  list<string>  $missing
 */
function sandboxToolHashes(array $missing = [], bool $oldBase = false): Closure
{
    return function (array $command) use ($missing, $oldBase): ExecResult {
        if (! str_contains($command[2] ?? '', 'ONEDROP_TOOL_PATHS')) {
            return new ExecResult(0, '');
        }

        return new ExecResult(0, collect(app(SandboxTools::class)->expected())
            ->reject(fn (string $hash, string $path) => in_array($path, $missing, true))
            ->map(fn (string $hash, string $path) => ($oldBase && str_starts_with($path, SandboxTools::BASE_PATH) ? str_repeat('0', 64) : $hash)."  {$path}")
            ->implode("\n"));
    };
}

test('opening a project queues an update for when nobody uses it, and doesn\'t show it updating', function () {
    Queue::fake();
    $this->provider->outdated = ['old-ctr'];

    $this->actingAs($this->user)
        ->get(route('projects.show', $this->project))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('sandbox.updating', false));

    Queue::assertPushed(UpdateSandbox::class, fn (UpdateSandbox $job) => $job->project->is($this->project)
        && $job->delay->greaterThan(now()->addSeconds(UpdateSandbox::IDLE_SECONDS - 5)));

    // One waits per project: using it again doesn't queue another.
    $this->travel(20)->seconds();
    $this->actingAs($this->user)->get(route('projects.show', $this->project))->assertOk();
    Queue::assertPushed(UpdateSandbox::class, 1);

    expect($this->sandbox->fresh()->external_id)->toBe('old-ctr');
})->group('SBX-002');

test('a task\'s copy being used queues no update', function () {
    Queue::fake();
    $task = Task::factory()->for($this->project)->create();
    $copy = Sandbox::factory()->for($this->project)->create(['task_id' => $task->id, 'external_id' => 'copy-ctr']);

    $copy->markActive();

    Queue::assertNotPushed(UpdateSandbox::class);
})->group('SBX-002');

test('the update waits while the project is in use or its agent works', function () {
    $this->provider->outdated = ['old-ctr'];
    $this->sandbox->forceFill(['last_active_at' => now()->subMinutes(2)])->save();

    $job = (new UpdateSandbox($this->project))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);
    $job->assertReleased(delay: UpdateSandbox::IDLE_SECONDS - 120);

    $this->sandbox->forceFill(['last_active_at' => now()->subMinutes(30)])->save();
    $this->project->update(['status' => ProjectStatus::Working]);
    $job = (new UpdateSandbox($this->project))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);
    $job->assertReleased();

    expect($this->sandbox->fresh()->external_id)->toBe('old-ctr')
        ->and($this->provider->copied)->toBe([]);
})->group('SBX-002');

test('a sandbox nobody has used for a while is updated, then suspended again', function () {
    $this->provider->outdated = ['old-ctr'];
    $this->sandbox->forceFill(['last_active_at' => now()->subMinutes(11), 'stopped_at' => now()->subMinutes(5)])->save();

    $job = (new UpdateSandbox($this->project))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);
    $job->assertNotReleased();

    $sandbox = $this->sandbox->fresh();

    expect($sandbox->external_id)->not->toBe('old-ctr')
        ->and($this->provider->suspended)->toBe([$sandbox->external_id])
        ->and($sandbox->suspended_at)->not->toBeNull()
        ->and($sandbox->stopped_at)->toBeNull()
        ->and(SandboxUpdater::isUpdating($this->project))->toBeFalse();
})->group('SBX-002');

test('changed tool files are copied into the running sandbox, and the processes using them restarted', function () {
    $this->provider->outdated = ['old-ctr'];
    $this->provider->execUsing = sandboxToolHashes(missing: [SandboxTools::PATH.'/host-proxy.mjs', SandboxTools::PATH.'/guides/auth.md']);

    expect(app(SandboxUpdater::class)->updateIfOutdated($this->project))->toBeTrue();

    expect($this->sandbox->fresh()->external_id)->toBe('old-ctr') // no new sandbox
        ->and($this->provider->copied)->toBe([])
        ->and($this->provider->installed)->toBe([['id' => 'old-ctr', 'path' => '/opt/onedrop', 'files' => ['guides/auth.md', 'host-proxy.mjs']]])
        ->and(collect($this->provider->executed)->pluck('command')->all())->toContain(['pkill', '-f', 'node /opt/onedrop/host-proxy.mjs'])
        ->and(SandboxUpdater::isUpdating($this->project))->toBeFalse();
})->group('SBX-002');

test('a sandbox whose tools match is left alone, even when the image was rebuilt', function () {
    $this->provider->outdated = ['old-ctr'];
    $this->provider->execUsing = sandboxToolHashes();

    expect(app(SandboxUpdater::class)->updateIfOutdated($this->project))->toBeFalse()
        ->and($this->provider->installed)->toBe([])
        ->and($this->sandbox->fresh()->external_id)->toBe('old-ctr');

    // Remembered: the next check doesn't ask the sandbox again.
    $asked = count($this->provider->executed);
    app(SandboxUpdater::class)->updateIfOutdated($this->project);
    expect($this->provider->executed)->toHaveCount($asked);
})->group('SBX-002');

test('a sandbox on an older base gets no tool files, and a new sandbox once a newer image is built', function () {
    $this->provider->execUsing = sandboxToolHashes(missing: [SandboxTools::PATH.'/host-proxy.mjs'], oldBase: true);

    expect(app(SandboxUpdater::class)->updateIfOutdated($this->project))->toBeFalse()
        ->and($this->provider->installed)->toBe([]);

    $this->provider->outdated = ['old-ctr'];

    expect(app(SandboxUpdater::class)->updateIfOutdated($this->project))->toBeTrue()
        ->and($this->sandbox->fresh()->external_id)->not->toBe('old-ctr');
})->group('SBX-002');

test('a provider that can\'t write tool files gets a new sandbox when its image was rebuilt', function () {
    $this->provider->installs = false;
    $this->provider->execUsing = sandboxToolHashes(missing: [SandboxTools::PATH.'/host-proxy.mjs']);

    expect(app(SandboxUpdater::class)->updateIfOutdated($this->project))->toBeFalse();

    $this->provider->outdated = ['old-ctr'];

    expect(app(SandboxUpdater::class)->updateIfOutdated($this->project))->toBeTrue()
        ->and($this->sandbox->fresh()->external_id)->not->toBe('old-ctr');
})->group('SBX-002');

test('the agent\'s run gets the current tool files first, but never waits for a new sandbox', function () {
    $this->provider->outdated = ['old-ctr'];
    $this->provider->execUsing = sandboxToolHashes(missing: [SandboxTools::PATH.'/instructions.md']);
    $message = Message::factory()->for($this->project)->create();
    $runner = new class implements AgentRunner
    {
        public ?string $sandboxId = null;

        public function start(Project $project, Message $message): void
        {
            $this->sandboxId = $project->sandbox->external_id;
        }

        public function stop(Conversation $conversation): void {}
    };

    (new RunAgentTask($this->project, $message))->handle($runner, app(SandboxUpdater::class));

    expect($runner->sandboxId)->toBe('old-ctr')
        ->and($this->provider->installed)->toHaveCount(1)
        ->and($this->provider->installed[0]['files'])->toBe(['instructions.md']);

    // On an older base the run goes ahead in the sandbox as it is.
    $this->provider->installed = [];
    $this->provider->execUsing = sandboxToolHashes(missing: [SandboxTools::PATH.'/instructions.md'], oldBase: true);
    (new RunAgentTask($this->project, $message))->handle($runner, app(SandboxUpdater::class));

    expect($runner->sandboxId)->toBe('old-ctr')
        ->and($this->provider->installed)->toBe([])
        ->and($this->provider->copied)->toBe([]);
})->group('SBX-002');

test('sandbox:update updates outdated sandboxes and skips ones the agent is working on', function () {
    $busy = Project::factory()->for($this->user)->create(['status' => ProjectStatus::Working]);
    Sandbox::factory()->for($busy)->create(['external_id' => 'busy-ctr']);
    $current = Project::factory()->for($this->user)->create();
    Sandbox::factory()->for($current)->create(['external_id' => 'current-ctr']);
    $this->provider->outdated = ['old-ctr', 'busy-ctr'];

    $this->artisan('sandbox:update')
        ->expectsOutputToContain("Updated project {$this->project->id}.")
        ->expectsOutputToContain("Skipped project {$busy->id}")
        ->assertSuccessful();

    expect($this->sandbox->fresh()->external_id)->not->toBe('old-ctr')
        ->and($busy->sandbox->fresh()->external_id)->toBe('busy-ctr')
        ->and($current->sandbox->fresh()->external_id)->toBe('current-ctr');

    $this->artisan('sandbox:update', ['project' => $current->id])
        ->expectsOutputToContain('Every sandbox is up to date.')
        ->assertSuccessful();
})->group('SBX-002');

test('sandbox:recreate --keep-files carries the files over', function () {
    $this->artisan('sandbox:recreate', ['project' => $this->project->id, '--keep-files' => true])
        ->expectsOutputToContain('Copied files into the new sandbox.')
        ->assertSuccessful();

    expect(collect($this->provider->copied)->where(0, 'in'))->toHaveCount(count(SandboxUpdater::KEPT_PATHS))
        ->and($this->project->fresh()->agent_session_id)->toBe('ses_1');
})->group('SBX-002');
