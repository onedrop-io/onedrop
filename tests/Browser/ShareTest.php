<?php

use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\Publishing\FakePublisher;
use App\Sandbox\Publishing\Publisher;
use App\Sandbox\SandboxProvider;
use App\Sandbox\ShareCards;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake(ShareCards::DISK);

    // The sandbox's share-card script "leaves" a screenshot and a card to copy out.
    app()->instance(SandboxProvider::class, new class extends FakeSandboxProvider
    {
        public function copyOut(string $id, string $path, string $directory): void
        {
            $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
            File::put("{$directory}/app.png", $png);
            File::put("{$directory}/card.png", $png);
        }
    });
    app()->instance(Publisher::class, new FakePublisher);

    $this->user = User::factory()->has(AgentConnection::factory())->create(['name' => 'Jeff Loiselle']);
    $this->project = Project::factory()->for($this->user)->create(['name' => 'Team CRM', 'prompt' => 'A CRM for our sales team']);
    Sandbox::factory()->for($this->project)->create(['preview_url' => null]);
    $this->actingAs($this->user);
});

test('sharing a project gives it a public page with its prompt, card and a remix button', function () {
    visit("/projects/{$this->project->id}")
        ->click('@share-button')
        ->assertValue('@share-prompt', 'A CRM for our sales team')
        ->fill('@share-prompt', 'A CRM for our sales team, with a deals pipeline')
        ->click('@share-submit')
        ->assertVisible('@share-card')
        ->assertSeeIn('@share-url', '/s/team-crm-')
        ->assertNoJavaScriptErrors();

    $share = $this->project->share()->firstOrFail();

    expect($share->prompt)->toBe('A CRM for our sales team, with a deals pipeline');

    visit("/s/{$share->slug}")
        ->assertSeeIn('@share-name', 'Team CRM')
        ->assertSeeIn('@share-prompt', 'with a deals pipeline')
        ->assertVisible('@share-screenshot')
        ->assertVisible('@edit-share')
        ->click('@remix')
        ->assertSee('Remixing “Team CRM”')
        ->assertValue('#composer-prompt', 'A CRM for our sales team, with a deals pipeline')
        ->assertNoJavaScriptErrors();

    expect($share->fresh()->remixes)->toBe(1);
})->group('SHARE-001', 'SHARE-002');

test('stopping sharing takes the page down', function () {
    $share = $this->project->share()->create(['slug' => 'team-crm-abcd1234', 'prompt' => 'A CRM']);

    visit("/projects/{$this->project->id}")
        ->click('@share-button')
        ->click('@share-stop')
        ->assertSee('Stopped sharing.')
        ->click('@share-button')
        ->assertVisible('@share-submit')
        ->assertNoJavaScriptErrors();

    $this->get("/s/{$share->slug}")->assertNotFound();
})->group('SHARE-001');
