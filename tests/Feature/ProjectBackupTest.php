<?php

use App\Enums\ProjectStatus;
use App\Enums\SandboxStatus;
use App\Jobs\BackupProject;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\ExecResult;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\Publishing\FakePublisher;
use App\Sandbox\Publishing\Publisher;
use App\Sandbox\SandboxProvider;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('backups');
    config(['sandbox.backup_disk' => 'backups']);

    // A sandbox whose workspace has commits: bundling prints HEAD, and copying out yields the bundle.
    $this->provider = new class extends FakeSandboxProvider
    {
        public function copyOut(string $id, string $path, string $directory): void
        {
            parent::copyOut($id, $path, $directory);
            File::put("{$directory}/repo.bundle", 'bundle-bytes');
        }
    };
    $this->provider->execUsing = fn (array $command) => str_contains(implode(' ', $command), 'git bundle create')
        ? new ExecResult(0, "abc123\n")
        : new ExecResult(0, '');
    app()->instance(SandboxProvider::class, $this->provider);
    app()->instance(Publisher::class, new FakePublisher);

    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->project = Project::factory()->for($this->user)->create(['status' => ProjectStatus::Working]);
    $this->sandbox = Sandbox::factory()->for($this->project)->create(['external_id' => 'ctr-1']);
});

test('finishing an agent turn backs up the project\'s git history', function () {
    $this->withToken($this->sandbox->issueEventsToken())
        ->postJson(route('sandbox-events.store', $this->sandbox), ['events' => [['type' => 'onedrop.exit', 'code' => 0, 'stderr' => '']]])
        ->assertOk();

    $this->project->refresh();

    expect(Storage::disk('backups')->get("project-backups/{$this->project->id}/repo.bundle"))->toBe('bundle-bytes')
        ->and($this->project->backup_commit)->toBe('abc123')
        ->and($this->project->backed_up_at)->not->toBeNull()
        ->and(collect($this->provider->copied)->where(0, 'out')->pluck(2)->all())->toBe(['/tmp/onedrop-backup']);
})->group('SBX-006');

test('a turn with no new commit copies nothing', function () {
    $this->project->update(['backup_commit' => 'abc123']);
    $this->provider->execUsing = fn () => new ExecResult(3, '');

    BackupProject::dispatchSync($this->project);

    expect(collect($this->provider->executed)->sole()['env'])->toBe(['ONEDROP_BACKED_UP' => 'abc123'])
        ->and($this->provider->copied)->toBe([])
        ->and(Storage::disk('backups')->allFiles())->toBe([]);
})->group('SBX-006');

test('a backup that fails keeps the last one and is tried again next turn', function () {
    Storage::disk('backups')->put("project-backups/{$this->project->id}/repo.bundle", 'older');
    $this->project->update(['backup_commit' => 'old111']);
    $this->provider->execUsing = fn () => new ExecResult(128, '', 'fatal: bad object');

    BackupProject::dispatchSync($this->project);

    expect($this->project->fresh()->backup_commit)->toBe('old111')
        ->and(Storage::disk('backups')->get("project-backups/{$this->project->id}/repo.bundle"))->toBe('older');
})->group('SBX-006');

test('a project without a running sandbox is not backed up', function () {
    $this->sandbox->update(['status' => SandboxStatus::Failed]);

    BackupProject::dispatchSync($this->project);

    expect($this->provider->executed)->toBe([]);
})->group('SBX-006');

test('stopping the agent backs up the stopped turn once the forwarder has committed it', function () {
    Queue::fake();

    $this->actingAs($this->user)->post(route('projects.agent.stop', $this->project));

    Queue::assertPushed(BackupProject::class, fn (BackupProject $job) => $job->project->is($this->project) && $job->delay !== null);
})->group('SBX-006');

test('a sandbox recreated without its files gets the code back from the backup and starts the app', function () {
    Storage::disk('backups')->put("project-backups/{$this->project->id}/repo.bundle", 'bundle-bytes');
    $this->project->update(['status' => ProjectStatus::Idle]);

    $this->artisan('sandbox:recreate', ['project' => $this->project->id])
        ->expectsOutputToContain('Restored the code from its backup.')
        ->assertSuccessful();

    $new = $this->project->sandbox()->first()->external_id;
    $commands = collect($this->provider->executed)->where('id', $new)->pluck('command')->map(fn (array $command) => implode(' ', $command));

    expect(collect($this->provider->copied)->where(0, 'in')->map(fn (array $copy) => [$copy[1], $copy[2]])->values()->all())->toBe([[$new, '/tmp/onedrop-backup']])
        ->and($commands->first(fn (string $command) => str_contains($command, 'git clone -q repo.bundle repo')))->not->toBeNull()
        ->and($commands->last())->toBe('/opt/onedrop/restart');
})->group('SBX-006');

test('a sandbox recreated with its files is not restored from the backup', function () {
    Storage::disk('backups')->put("project-backups/{$this->project->id}/repo.bundle", 'bundle-bytes');
    $this->project->update(['status' => ProjectStatus::Idle]);

    $this->artisan('sandbox:recreate', ['project' => $this->project->id, '--keep-files' => true])->assertSuccessful();

    expect(collect($this->provider->copied)->where(0, 'in')->pluck(2)->all())->not->toContain('/tmp/onedrop-backup');
})->group('SBX-006');

test('a project without a backup is recreated empty', function () {
    $this->project->update(['status' => ProjectStatus::Idle]);

    $this->artisan('sandbox:recreate', ['project' => $this->project->id])
        ->doesntExpectOutputToContain('Restored the code')
        ->assertSuccessful();

    expect($this->provider->copied)->toBe([]);
})->group('SBX-006');

test('deleting a project deletes its backup', function () {
    Storage::disk('backups')->put("project-backups/{$this->project->id}/repo.bundle", 'bundle-bytes');

    $this->actingAs($this->user)->delete(route('projects.destroy', $this->project));

    expect(Storage::disk('backups')->allFiles())->toBe([]);
})->group('SBX-006');

test('without a backup disk set, backups go to the app\'s default disk', function () {
    Storage::fake('shared');
    config(['sandbox.backup_disk' => null, 'filesystems.default' => 'shared']);

    BackupProject::dispatchSync($this->project);

    expect(Storage::disk('shared')->get("project-backups/{$this->project->id}/repo.bundle"))->toBe('bundle-bytes');
})->group('SBX-006');
