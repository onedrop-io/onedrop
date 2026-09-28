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
use App\Models\User;
use App\Sandbox\Agents\AgentRunner;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\Publishing\FakePublisher;
use App\Sandbox\Publishing\Publisher;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxProvider;
use App\Sandbox\SandboxUpdater;
use Illuminate\Support\Facades\Cache;
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
        ->and(collect($this->provider->executed)->pluck('command')->all())->toContain(['/opt/zap/restart'])
        ->toContain(['bash', '-c', SandboxUpdater::LOAD_IMAGE_BASHRC]) // an old ~/.bashrc gives way to the image's shell setup
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

    expect(fn () => app(SandboxUpdater::class)->updateIfOutdated($this->project))->toThrow(SandboxException::class, 'disk full');

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

    expect(fn () => app(SandboxUpdater::class)->updateIfOutdated($this->project))->toThrow(RuntimeException::class, 'Connection reset');

    expect($provider->started)->toBe(['old-ctr'])
        ->and($this->sandbox->fresh()->external_id)->toBe('old-ctr')
        ->and(glob(storage_path('framework/sandbox-backup-*')))->toBe([]);
})->group('SBX-002');

test('an up-to-date or stopped sandbox is left alone', function () {
    expect(app(SandboxUpdater::class)->updateIfOutdated($this->project))->toBeFalse();

    $this->provider->outdated = ['old-ctr'];
    $this->sandbox->update(['status' => SandboxStatus::Paused]);

    expect(app(SandboxUpdater::class)->updateIfOutdated($this->project))->toBeFalse()
        ->and($this->sandbox->fresh()->external_id)->toBe('old-ctr')
        ->and($this->provider->copied)->toBe([]);
})->group('SBX-002');

test('opening a project with an outdated sandbox queues an update and shows it updating', function () {
    Queue::fake();
    $this->provider->outdated = ['old-ctr'];

    $this->actingAs($this->user)
        ->get(route('projects.show', $this->project))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('sandbox.updating', true));

    Queue::assertPushed(UpdateSandbox::class, fn (UpdateSandbox $job) => $job->project->is($this->project));

    // Checked at most once a minute: polling the page doesn't queue more.
    $this->actingAs($this->user)->get(route('projects.show', $this->project))->assertOk();
    Queue::assertPushed(UpdateSandbox::class, 1);
})->group('SBX-002');

test('opening a project never queues an update while the agent works or when the sandbox is current', function () {
    Queue::fake();

    $this->actingAs($this->user)
        ->get(route('projects.show', $this->project))
        ->assertInertia(fn ($page) => $page->where('sandbox.updating', false));

    Cache::flush();
    $this->provider->outdated = ['old-ctr'];
    $this->project->update(['status' => ProjectStatus::Working]);
    $this->actingAs($this->user)->get(route('projects.show', $this->project))->assertOk();

    Queue::assertNotPushed(UpdateSandbox::class);
})->group('SBX-002');

test('the update job skips a project the agent is working on and clears the updating state', function () {
    $this->provider->outdated = ['old-ctr'];
    $this->project->update(['status' => ProjectStatus::Working]);
    SandboxUpdater::markUpdating($this->project);

    (new UpdateSandbox($this->project))->handle(app(SandboxUpdater::class));

    expect($this->sandbox->fresh()->external_id)->toBe('old-ctr')
        ->and(SandboxUpdater::isUpdating($this->project))->toBeFalse();

    $this->project->update(['status' => ProjectStatus::Idle]);
    (new UpdateSandbox($this->project))->handle(app(SandboxUpdater::class));

    expect($this->sandbox->fresh()->external_id)->not->toBe('old-ctr');
})->group('SBX-002');

test('the agent runs in an up-to-date sandbox', function () {
    $this->provider->outdated = ['old-ctr'];
    $message = Message::factory()->for($this->project)->create();
    $runner = new class implements AgentRunner
    {
        public ?string $sandboxId = null;

        public function start(Project $project, Message $message): void
        {
            $this->sandboxId = $project->sandbox->external_id;
        }

        public function stop(Project $project): void {}
    };

    (new RunAgentTask($this->project, $message))->handle($runner, app(SandboxUpdater::class));

    expect($runner->sandboxId)->not->toBe('old-ctr')
        ->and($runner->sandboxId)->toBe($this->sandbox->fresh()->external_id);
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
