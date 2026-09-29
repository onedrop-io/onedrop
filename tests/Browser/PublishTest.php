<?php

use App\Enums\PublishStatus;
use App\Enums\PublishVisibility;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\Publishing\FakePublisher;
use App\Sandbox\Publishing\Publisher;
use App\Sandbox\SandboxProvider;

beforeEach(function () {
    app()->instance(SandboxProvider::class, new FakeSandboxProvider);
    $this->publisher = new FakePublisher;
    app()->instance(Publisher::class, $this->publisher);

    $this->user = User::factory()->has(AgentConnection::factory())->create(['name' => 'Jeff Loiselle']);
    $this->project = Project::factory()->for($this->user)->create(['name' => 'Time Tracker']);
    Sandbox::factory()->for($this->project)->create(['preview_url' => null]);
    $this->actingAs($this->user);
});

test('publishing publicly shows the live url, then the button says Republish', function () {
    visit("/projects/{$this->project->id}")
        ->assertSeeIn('@publish-button', 'Publish')
        ->click('@publish-button')
        ->assertSeeIn('@publish-status', 'Not published')
        ->click('Public')
        ->click('@publish-submit')
        ->assertSeeIn('@publish-button', 'Republish')
        ->assertSeeIn('@publish-status', 'Jeff Loiselle published')
        ->assertSeeIn('@published-url', "time-tracker-{$this->project->id}.example.ts.net")
        ->click('@unpublish')
        ->assertSee('Unpublished.')
        ->assertNoJavaScriptErrors();

    expect($this->publisher->published)->toBe([]);
})->group('PUB-001');

test('the panel explains when publishing is not available', function () {
    $this->publisher->unavailable = 'Tailscale publishing only works with local Docker sandboxes.';

    visit("/projects/{$this->project->id}")
        ->click('@publish-button')
        ->assertSeeIn('@publish-unavailable', 'only works with local Docker')
        ->assertMissing('@publish-submit')
        ->assertNoJavaScriptErrors();
})->group('PUB-001');

test('without an auth key the panel offers a Tailscale sign-in link', function () {
    // The retry loop itself is covered in ProjectPublicationTest; here we check the waiting state's UI.
    $this->project->update([
        'publish_status' => PublishStatus::Publishing,
        'publish_visibility' => PublishVisibility::Public,
        'publish_login_url' => 'https://login.tailscale.com/a/abc123',
    ]);

    visit("/projects/{$this->project->id}")
        ->click('@publish-button')
        ->assertSeeIn('@publish-status', 'Publishing')
        ->assertSeeIn('@publish-login', 'Approve this project in Tailscale')
        ->assertAttribute('@publish-login-link', 'href', 'https://login.tailscale.com/a/abc123')
        ->assertSeeIn('@publish-status', 'Publishing')
        ->assertNoJavaScriptErrors();
})->group('PUB-001');

test('on a server, the user picks their domain or Tailscale, and each says who can open it', function () {
    config(['sandbox.gateway_domain' => 'onedrop.example.com']);

    $page = visit("/projects/{$this->project->id}")
        ->click('@publish-button')
        ->assertSeeIn('@publish-panel', 'Your domain')
        ->assertSeeIn('@publish-panel', 'People signed in to OneDrop')
        ->click('Tailscale')
        ->assertSeeIn('@publish-panel', "People on your team's tailnet")
        ->click('Your domain')
        ->click('Public')
        ->click('@publish-submit')
        ->assertSeeIn('@publish-status', 'Jeff Loiselle published')
        ->assertSeeIn('@published-target', 'Your domain')
        ->assertSeeIn('@published-url', "time-tracker-{$this->project->id}.onedrop.example.com")
        ->assertNoJavaScriptErrors();

    expect($this->publisher->published)->toBe([]);

    // Moving it to Tailscale takes it off the domain.
    $page->click('Tailscale')
        ->click('@publish-submit')
        ->assertSeeIn('@published-target', 'Tailscale')
        ->assertSeeIn('@published-url', "time-tracker-{$this->project->id}.example.ts.net")
        ->assertNoJavaScriptErrors();

    expect($this->publisher->published)->toHaveKey($this->project->id);
})->group('PUB-002');
