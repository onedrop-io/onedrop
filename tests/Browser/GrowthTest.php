<?php

use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Agents\AgentRunner;
use App\Sandbox\Agents\FakeAgentRunner;
use App\Sandbox\ExecResult;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use Illuminate\Support\Facades\Queue;

/**
 * A week of visits and a finished SEO scan, served as the sandbox's files.
 */
function fakeGrowthProvider(): FakeSandboxProvider
{
    $now = time();
    $access = [];
    $places = [
        ['c' => 'CA', 'rg' => 'Quebec', 'ci' => 'Montréal', 'la' => 45.51, 'lo' => -73.59],
        ['c' => 'CA', 'rg' => 'Ontario', 'ci' => 'Toronto', 'la' => 43.65, 'lo' => -79.38],
        ['c' => 'CA', 'rg' => 'Quebec', 'ci' => 'Montréal', 'la' => 45.51, 'lo' => -73.59],
        ['c' => 'GB', 'rg' => 'England', 'ci' => 'London', 'la' => 51.51, 'lo' => -0.13],
        ['c' => 'JP', 'rg' => 'Tokyo', 'ci' => 'Tokyo', 'la' => 35.68, 'lo' => 139.69],
        [],
    ];
    $agents = [
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36',
        'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1',
    ];
    for ($i = 0; $i < 60; $i++) {
        $access[] = json_encode([
            't' => ($now - $i * 3000) * 1000, 's' => 200, 'd' => 20, 'ip' => '10.0.0.'.($i % 6), 'pub' => $i % 2 === 0,
            'm' => 'GET', 'p' => ['/', '/pricing', '/about'][$i % 3], 'r' => $i % 4 === 0 ? 'news.example.com' : null,
            'ua' => $agents[$i % 6 < 4 ? 0 : 1], ...$places[$i % 6],
        ]);
    }
    $seo = json_encode([
        'version' => 1, 'scanned_at' => '2026-09-27T14:05:00Z', 'score' => 72,
        'summary' => 'Add descriptions and a sitemap.',
        'checks' => [
            ['title' => 'Page titles', 'status' => 'pass', 'detail' => 'All 3 pages have unique titles.'],
            ['title' => 'Meta descriptions', 'status' => 'fail', 'detail' => 'None of the 3 pages has a description.'],
        ],
    ]);

    $provider = new FakeSandboxProvider;
    $provider->execUsing = function (array $command) use ($access, $seo) {
        $script = implode(' ', $command);

        return new ExecResult(0, match (true) {
            str_contains($script, 'access.log') => implode("\n", $access),
            str_contains($script, 'seo.json') => $seo,
            default => '',
        });
    };

    return $provider;
}

test('the growth section shows the seo rating and visitor analytics, and runs a scan with the agent', function () {
    Queue::fake();
    app()->instance(AgentRunner::class, new FakeAgentRunner);
    app()->instance(SandboxProvider::class, fakeGrowthProvider());
    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['preview_url' => null]);
    $this->actingAs($user);

    $page = visit("/projects/{$project->id}")
        ->resize(1500, 1000)
        ->click('@tab-tools')
        ->click('@tool-growth')
        ->assertVisible('@growth-panel')
        ->assertSeeIn('@seo-score', '72/100')
        ->assertSeeIn('@seo-checks', 'Meta descriptions')
        ->assertSeeIn('@seo-checks', 'Failed')
        ->assertSeeIn('@growth-visitors', '6')
        ->assertSeeIn('@growth-change', 'vs previous 7 days')
        ->assertVisible('@chart-visitors')
        ->assertSeeIn('@top-pages', '/pricing')
        ->assertSeeIn('@top-referrers', 'news.example.com')
        ->assertSeeIn('@top-countries', 'Canada')
        ->assertPresent('[data-test="growth-map"] svg path')
        ->assertSeeIn('@top-cities', 'Montréal, Quebec, CA')
        ->assertSeeIn('@top-browsers', 'Safari')
        ->assertSeeIn('@top-devices', 'Mobile')
        ->click('[aria-checked="false"]')
        ->assertSeeIn('@growth-visitors', '3')
        ->click('@seo-scan')
        ->assertSeeIn('@seo-scan-sent', 'The agent is checking your app')
        ->assertNoJavaScriptErrors();

    expect($project->messages()->latest('id')->value('content'))->toContain("Check my app's SEO and tell me what to improve.");
})->group('GROW-001');

test('growth explains when the sandbox is not running', function () {
    app()->instance(SandboxProvider::class, new FakeSandboxProvider);
    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['status' => 'paused', 'preview_url' => null]);
    $this->actingAs($user);

    visit("/projects/{$project->id}")
        ->click('@tab-tools')
        ->click('@tool-growth')
        ->assertSeeIn('@growth-empty', 'Growth starts when the sandbox is running')
        ->assertNoJavaScriptErrors();
})->group('GROW-001');

test('custom events: set up with the agent, then see each event over time and add more', function () {
    Queue::fake();
    app()->instance(AgentRunner::class, new FakeAgentRunner);
    $provider = new FakeSandboxProvider;
    $setUp = false;
    $provider->execUsing = function (array $command) use (&$setUp) {
        $script = implode(' ', $command);
        $now = time();

        return new ExecResult(0, match (true) {
            ! $setUp => '',
            str_contains($script, 'analytics.json') => json_encode(['version' => 1, 'events' => [
                ['name' => 'signed_up', 'description' => 'Someone created an account.'],
                ['name' => 'project_created', 'description' => 'Someone created a project.'],
            ]]),
            str_contains($script, 'events.log') => implode("\n", array_map('json_encode', [
                ['t' => ($now - 60) * 1000, 'n' => 'signed_up', 'ip' => '10.0.0.1', 'pub' => true],
                ['t' => ($now - 120) * 1000, 'n' => 'project_created', 'ip' => '10.0.0.1', 'pub' => true, 'props' => ['template' => 'kanban']],
                ['t' => ($now - 180) * 1000, 'n' => 'project_created', 'ip' => '10.0.0.2', 'pub' => true, 'props' => ['template' => 'blank']],
                ['t' => ($now - 240) * 1000, 'n' => 'project_created', 'ip' => '10.0.0.2', 'pub' => true, 'props' => ['template' => 'kanban']],
            ])),
            default => '',
        });
    };
    app()->instance(SandboxProvider::class, $provider);
    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['preview_url' => null]);
    $this->actingAs($user);

    visit("/projects/{$project->id}")
        ->resize(1500, 1000)
        ->click('@tab-tools')
        ->click('@tool-growth')
        ->assertSeeIn('@events-card', 'Understand how people use your app')
        ->click('@events-set-up')
        ->assertSeeIn('@events-sent', 'The agent is adding events to your app')
        // The chat follows the run straight away, without a refresh.
        ->assertSeeIn('@message-user', 'Add custom analytics events to my project')
        ->assertSee('Thinking…');

    expect($project->messages()->latest('id')->value('content'))->toStartWith('Add custom analytics events to my project.');

    $setUp = true;
    visit("/projects/{$project->id}")
        ->resize(1500, 1000)
        ->click('@tab-tools')
        ->click('@tool-growth')
        ->assertSeeIn('@events-table', 'signed_up')
        ->assertSeeIn('@events-table', 'Someone created a project.')
        ->click('project_created')
        ->assertVisible('@chart-event')
        ->assertSeeIn('@event-prop-template', 'kanban')
        ->type('@events-more', 'when someone shares a project')
        ->click('@events-add')
        ->assertSeeIn('@events-sent', 'It runs after the current task')
        ->assertNoJavaScriptErrors();

    // The agent is still on the first request, so this one waits its turn.
    expect($project->queuedMessages()->value('content'))
        ->toBe('Add these custom analytics events to my project: when someone shares a project. Follow the guide at /opt/onedrop/guides/analytics.md.');
})->group('GROW-002');
