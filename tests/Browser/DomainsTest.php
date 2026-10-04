<?php

use App\Jobs\CheckProjectDomain;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Domains\DomainDns;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\Publishing\FakePublisher;
use App\Sandbox\Publishing\Publisher;
use App\Sandbox\SandboxProvider;
use Illuminate\Support\Facades\Queue;

test('the domains section adds a domain, shows its DNS record, checks it and makes another primary', function () {
    config(['sandbox.gateway_domain' => 'onedrop.example.com']);
    Queue::fake([CheckProjectDomain::class]);
    app()->instance(SandboxProvider::class, new FakeSandboxProvider);
    app()->instance(Publisher::class, new FakePublisher);

    $dns = ['onedrop.example.com' => ['203.0.113.10']];
    $fake = Mockery::mock(DomainDns::class);
    $fake->shouldReceive('addresses')->andReturnUsing(function (string $host) use (&$dns) {
        return $dns[$host] ?? [];
    });
    app()->instance(DomainDns::class, $fake);

    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create(['name' => 'Bake Sale', 'publish_target' => 'domain']);
    Sandbox::factory()->for($project)->create(['preview_url' => null]);
    $this->actingAs($user);

    $page = visit("/projects/{$project->id}?tool=domains")
        ->resize(1500, 1000)
        ->click('@tab-tools')
        ->assertVisible('@domains-panel')
        ->assertSee('No domains yet')
        ->type('@domain-hostname', 'shop.example.com')
        ->click('@domain-add')
        ->assertSeeIn('@domain-shop-example-com', 'Primary')
        ->assertSeeIn('@domain-status-shop-example-com', 'Waiting for DNS')
        ->assertSeeIn('@domain-records', 'CNAME')
        ->assertSeeIn('@domain-records', 'onedrop.example.com');

    $dns['shop.example.com'] = ['198.51.100.7'];
    $page->click('@domain-check-shop-example-com')
        ->assertSeeIn('@domain-problem-shop-example-com', 'points at 198.51.100.7, not this server');

    $dns['shop.example.com'] = ['203.0.113.10'];
    $page->click('@domain-check-shop-example-com')
        ->assertSeeIn('@domain-status-shop-example-com', 'Active')
        ->type('@domain-hostname', 'example.com')
        ->click('@domain-add')
        ->assertSeeIn('@domain-records', '203.0.113.10')
        ->click('@domain-menu-example-com')
        ->click('@domain-primary-example-com')
        ->assertSeeIn('@domain-example-com', 'Primary')
        ->type('@domain-hostname', 'onedrop.example.com')
        ->click('@domain-add')
        ->assertSeeIn('@domain-error', 'own domain')
        ->assertNoJavaScriptErrors();

    expect($project->domains()->where('primary', true)->value('hostname'))->toBe('example.com');
})->group('DOM-001', 'DOM-002');
