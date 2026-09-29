<?php

use App\Jobs\UpdateProjectIcon;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\ProjectIcons;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Queue::fake();
    Storage::fake(ProjectIcons::disk());
    $this->seed(DatabaseSeeder::class);
    $this->user = User::where('email', 'dev@example.com')->sole();
    AgentConnection::factory()->for($this->user)->create();
    $this->project = Project::factory()->for($this->user)->create(['name' => 'Team CRM', 'read_at' => now()]);
    Sandbox::factory()->for($this->project)->create(['preview_url' => 'http://127.0.0.1:49152']);
    $this->actingAs($this->user);
});

test('the user sees the app icon in the sidebar and Tools, and asks the AI for a new one', function () {
    visit("/projects/{$this->project->id}")
        ->resize(1920, 1080)
        ->assertMissing('@sidebar-project-icon')
        ->click('@tab-tools')
        ->click('@tool-icon')
        ->assertSeeIn('@icon-panel-status', 'No icon yet');

    // Pest's browser server doesn't pass multipart uploads through yet; ProjectIconTest covers uploading.
    Storage::disk(ProjectIcons::disk())->put('project-icons/1/icon.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" rx="14" fill="#2563eb"/></svg>');
    $this->project->update(['icon_path' => 'project-icons/1/icon.svg', 'icon_mime' => 'image/svg+xml', 'icon_hash' => str_repeat('a', 64)]);

    visit("/projects/{$this->project->id}")
        ->resize(1920, 1080)
        ->assertVisible('@sidebar-project-icon')
        ->click('@tab-tools')
        ->click('@tool-icon')
        ->assertSeeIn('@icon-panel-status', "Your app's icon")
        ->assertVisible('@icon-panel-image')
        ->assertScript('document.querySelector(\'[data-test="sidebar-project-icon"]\').naturalWidth > 0', true)
        ->click('@icon-panel-draw')
        ->assertSeeIn('@icon-panel-status', 'Drawing an icon…')
        ->assertNoJavaScriptErrors();

    Queue::assertPushed(UpdateProjectIcon::class, fn (UpdateProjectIcon $job) => $job->redraw);
})->group('PRJ-007');
