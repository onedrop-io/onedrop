<?php

use App\Enums\MoveSource;
use App\Enums\OldSandboxStatus;
use App\Enums\SandboxMovePhase;
use App\Jobs\MoveProjectSandbox;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\ProjectSnapshot;
use App\Models\Sandbox;
use App\Models\SandboxMove;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->dev = User::where('email', 'dev@example.com')->sole();
    AgentConnection::factory()->for($this->dev)->create();
    // Moves run in the queue; here they're only started.
    Queue::fake();
    config(['sandbox.provider' => 'docker', 'sandbox.providers.blaxel.api_key' => null, 'sandbox.providers.blaxel.workspace' => null]);
});

test('an admin turns a provider off, sees its projects moving, tries a failed move again and restores recovered files', function () {
    $shop = Project::factory()->for($this->dev)->create(['name' => 'Shop']);
    Sandbox::factory()->for($shop)->create(['provider' => 'docker', 'external_id' => 'shop-ctr']);

    $blog = Project::factory()->for($this->dev)->create(['name' => 'Blog']);
    $failed = SandboxMove::query()->create([
        'project_id' => $blog->id,
        'sandbox_id' => Sandbox::factory()->for($blog)->create(['provider' => 'docker', 'external_id' => 'blog-ctr'])->id,
        'reason' => 'provider', 'phase' => SandboxMovePhase::Failed, 'from_provider' => 'docker', 'to_provider' => 'blaxel',
        'error' => 'Blaxel: quota exceeded', 'finished_at' => now(),
    ]);

    $wiki = Project::factory()->for($this->dev)->create(['name' => 'Wiki']);
    $recovered = SandboxMove::query()->create([
        'project_id' => $wiki->id,
        'sandbox_id' => Sandbox::factory()->for($wiki)->create(['provider' => 'blaxel', 'external_id' => 'wiki-bl'])->id,
        'reason' => 'provider', 'phase' => SandboxMovePhase::Done, 'from_provider' => 'docker', 'to_provider' => 'blaxel',
        'source' => MoveSource::Snapshot, 'old_status' => OldSandboxStatus::Recovered, 'finished_at' => now()->subHour(),
        'recovered_snapshot_id' => ProjectSnapshot::factory()->for($wiki)->create(['reason' => ProjectSnapshot::RECOVERED])->id,
    ]);

    $page = visit('/login')
        ->fill('email', 'dev@example.com')
        ->fill('password', 'password')
        ->press('@login-button')
        ->assertPathIs(orgPath())
        ->navigate('/admin/sandboxes')
        ->assertSeeIn("@sandbox-move-{$failed->id}", 'Blaxel: quota exceeded')
        // A move that failed goes again.
        ->click("@sandbox-move-{$failed->id}-retry")
        ->assertSee('Moving Blog again.')
        ->assertMissing("@sandbox-move-{$failed->id}")
        // Recovered files go back in only once the admin confirms.
        ->assertSeeIn("@sandbox-move-{$recovered->id}", 'answered again')
        ->click("@sandbox-move-{$recovered->id}-restore")
        ->assertSeeIn('@restore-recovered-dialog', 'Anything changed in the project since it moved will be replaced.')
        ->click('@restore-recovered-confirm')
        ->assertSee('Restoring Wiki\'s recovered files.')
        ->assertMissing("@sandbox-move-{$recovered->id}")
        // Set up Blaxel and turn it on, then turn Docker off: its projects move at once.
        ->click('@provider-blaxel-select')
        ->fill('#blaxel-api_key', 'bl-key')
        ->fill('#blaxel-workspace', 'acme')
        ->press('@provider-blaxel-save')
        ->assertSee('Blaxel saved.')
        ->click('@provider-blaxel-enabled')
        ->assertSee('Blaxel saved.')
        ->click('@provider-docker-enabled')
        ->assertSee('New projects now run on Blaxel. Moving 1 sandbox to Blaxel.')
        ->assertSeeIn('@sandbox-moves', 'Shop')
        ->assertSeeIn('@sandbox-moves', 'Docker → Blaxel');

    $page->assertNoJavaScriptErrors();

    expect(SandboxMove::query()->where('project_id', $wiki->id)->latest('id')->first()->reason)->toBe('restore')
        ->and(SandboxMove::query()->where('project_id', $blog->id)->count())->toBe(2);
    Queue::assertPushed(MoveProjectSandbox::class, 3);
})->group('SBX-005', 'SBX-013', 'ADMIN-002');
