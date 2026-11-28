<?php

use App\Enums\MessageRole;
use App\Enums\PublishStatus;
use App\Enums\PublishTarget;
use App\Enums\PublishVisibility;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Agents\HarnessRunner;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\Publishing\FakePublisher;
use App\Sandbox\Publishing\Publisher;
use App\Sandbox\Publishing\PublishingSummary;
use App\Sandbox\SandboxProvider;

beforeEach(function () {
    config([
        'sandbox.gateway_domain' => null,
        'hosting.providers.fly.enabled' => false,
        'hosting.providers.cloudflare.enabled' => false,
        'hosting.providers.neon.enabled' => false,
        'hosting.providers.upstash.enabled' => false,
        'hosting.providers.vercel.enabled' => false,
    ]);
    app()->instance(Publisher::class, new FakePublisher);

    $this->project = Project::factory()->for(User::factory()->has(AgentConnection::factory()))->create();
});

test('without hosting set up the agent only hears about the targets on offer', function () {
    $summary = app(PublishingSummary::class)->for($this->project);

    expect($summary['published'])->toBeNull()
        ->and($summary['hosting'])->toBeNull()
        ->and(array_column($summary['targets'], 'target'))->toBe([PublishTarget::Tailscale->value]);
})->group('PUB-004');

test('the agent hears where the app is published, the hosting platforms and whose account each provider uses', function () {
    config([
        'sandbox.gateway_domain' => 'apps.example.com',
        'hosting.providers.cloudflare' => ['enabled' => true, 'account_id' => 'acct', 'api_token' => 'cf-platform-token'],
        'sandbox.snapshot_disk' => 'releases',
        'filesystems.disks.releases' => ['driver' => 's3'],
    ]);
    $this->project->organization->forceFill(['hosting_accounts' => ['fly' => ['api_token' => 'fly-theirs', 'org_slug' => 'acme']]])->save();
    $this->project->update([
        'publish_target' => PublishTarget::Domain,
        'publish_visibility' => PublishVisibility::Public,
        'publish_status' => PublishStatus::Live,
        'published_url' => 'https://shop-1.apps.example.com',
    ]);

    $summary = app(PublishingSummary::class)->for($this->project->fresh());

    expect($summary['published'])->toBe(['target' => 'domain', 'url' => 'https://shop-1.apps.example.com', 'visibility' => 'public', 'status' => 'live'])
        ->and(array_column($summary['targets'], 'target'))->toBe(['domain', 'hosting', 'tailscale'])
        ->and($summary['hosting'])->toBe([
            'platforms' => ['Fly.io & Cloudflare'],
            'accounts' => ['Fly.io' => 'organization', 'Cloudflare' => 'install', 'Neon' => 'none', 'Upstash' => 'none', 'Vercel' => 'none'],
        ]);

    // Names only: no provider keys reach the sandbox.
    expect(app(PublishingSummary::class)->json($this->project->fresh()))
        ->not->toContain('fly-theirs')
        ->not->toContain('cf-platform-token');
})->group('PUB-004');

test('every agent run is told what the project can be published to', function () {
    $provider = new FakeSandboxProvider;
    app()->instance(SandboxProvider::class, $provider);
    Sandbox::factory()->for($this->project)->create(['external_id' => 'ctr-1']);
    $message = $this->project->messages()->create(['role' => MessageRole::User, 'content' => 'how do I deploy this?']);

    app(HarnessRunner::class)->start($this->project, $message);

    $forwarder = collect($provider->executed)->firstWhere('command', ['node', '/opt/onedrop/forwarder.mjs']);

    expect(json_decode($forwarder['env']['APP_PUBLISHING'], true))->toBe(app(PublishingSummary::class)->for($this->project));
})->group('PUB-004');

test('the agent is told to answer deploy questions with the publishing guide', function () {
    $instructions = file_get_contents(base_path('docker/sandbox/instructions.md'));

    expect($instructions)->toContain('/opt/onedrop/guides/publishing.md')
        ->and(file_get_contents(base_path('docker/sandbox/guides/publishing.md')))->toContain('/home/sandbox/.onedrop/publishing.json');
})->group('PUB-004');
