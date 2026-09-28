<?php

use App\Enums\ProjectStatus;
use App\Enums\SandboxStatus;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Agents\AgentRunner;
use App\Sandbox\Agents\FakeAgentRunner;
use App\Sandbox\ExecResult;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxGrowth;
use App\Sandbox\SandboxProvider;
use App\Sandbox\WorkspaceAnalytics;
use App\Sandbox\WorkspaceAuth;
use Illuminate\Support\Facades\Queue;

const CHROME_MAC = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36';
const SAFARI_IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

beforeEach(function () {
    Queue::fake();
    app()->instance(AgentRunner::class, new FakeAgentRunner);

    $this->now = 1_790_000_000 - (1_790_000_000 % 3600) + 1800; // half past an hour
    $this->provider = new FakeSandboxProvider;
    app()->instance(SandboxProvider::class, $this->provider);

    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->project = Project::factory()->for($this->user)->create();
    $this->sandbox = Sandbox::factory()->for($this->project)->create(['external_id' => 'ctr-1']);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function visit_(int $at, string $ip, array $overrides = []): array
{
    return ['t' => $at * 1000, 's' => 200, 'd' => 10, 'ip' => $ip, 'pub' => true, 'm' => 'GET', 'p' => '/', 'ua' => CHROME_MAC, ...$overrides];
}

test('it counts visitors against the previous period, over time, and by page, referrer, country, browser and device', function () {
    $ago = fn (int $seconds) => $this->now - $seconds;
    $this->provider->execUsing = fn () => new ExecResult(0, implode("\n", array_map('json_encode', [
        visit_($ago(60), '1.1.1.1', ['p' => '/pricing', 'r' => 'news.example.com', 'c' => 'CA']),
        visit_($ago(70), '1.1.1.1', ['p' => '/pricing']),
        visit_($ago(80), '1.1.1.1', ['p' => '/app.js']), // a file, not a page
        visit_($ago(90), '2.2.2.2', ['ua' => SAFARI_IPHONE, 'r' => 'news.example.com', 'c' => 'US']),
        visit_($ago(100), '2.2.2.2', ['m' => 'POST', 'p' => '/login', 'ua' => SAFARI_IPHONE]), // not a page view
        visit_($ago(7200), '3.3.3.3', ['s' => 404, 'p' => '/missing', 'ua' => 'Googlebot/2.1']),
        visit_($ago(7300), '4.4.4.4', ['pub' => false]),
        ['t' => $ago(8000) * 1000, 's' => 200, 'd' => 5, 'ip' => '5.5.5.5', 'pub' => true], // logged before Growth: no page or browser
        visit_($ago(86400 + 60), '1.1.1.1'), // yesterday
        visit_($ago(86400 + 70), '9.9.9.9'),
        visit_($ago(3 * 86400), '8.8.8.8'), // before the previous period
    ]))."\nnot json\n");

    $growth = app(SandboxGrowth::class)->analytics($this->sandbox, '24h', now: $this->now);

    expect($growth['visitors'])->toBe(5)
        ->and($growth['previous_visitors'])->toBe(2)
        ->and($growth['since'])->toBe($this->now - 86400)
        ->and($growth['page_views'])->toBe(4)
        ->and($growth['visitors_over_time'])->toHaveCount(24)
        ->and(end($growth['visitors_over_time']))->toBe(['t' => $this->now - 1800, 'value' => 2])
        ->and($growth['pages'])->toBe([['label' => '/', 'count' => 2], ['label' => '/pricing', 'count' => 2]])
        ->and($growth['referrers'])->toBe([['label' => 'news.example.com', 'count' => 2]])
        ->and($growth['countries'])->toBe([['label' => 'CA', 'count' => 1], ['label' => 'US', 'count' => 1]])
        ->and($growth['browsers'])->toBe([['label' => 'Chrome', 'count' => 2], ['label' => 'Bot', 'count' => 1], ['label' => 'Safari', 'count' => 1], ['label' => 'Unknown', 'count' => 1]])
        ->and($growth['devices'])->toBe([['label' => 'Desktop', 'count' => 2], ['label' => 'Bot', 'count' => 1], ['label' => 'Mobile', 'count' => 1], ['label' => 'Unknown', 'count' => 1]]);

    $published = app(SandboxGrowth::class)->analytics($this->sandbox, '24h', publishedOnly: true, now: $this->now);
    expect($published['visitors'])->toBe(4);
})->group('GROW-001');

test('it tells browsers and devices apart', function (string $userAgent, array $expected) {
    expect(SandboxGrowth::userAgent($userAgent))->toBe($expected);
})->with([
    'edge' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36 Edg/129.0', ['Edge', 'Desktop']],
    'firefox on android' => ['Mozilla/5.0 (Android 14; Mobile; rv:130.0) Gecko/130.0 Firefox/130.0', ['Firefox', 'Mobile']],
    'safari on ipad' => ['Mozilla/5.0 (iPad; CPU OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1', ['Safari', 'Tablet']],
    'android tablet' => ['Mozilla/5.0 (Linux; Android 14; SM-X710) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36', ['Chrome', 'Tablet']],
    'curl' => ['curl/8.7.1', ['Bot', 'Bot']],
])->group('GROW-001');

test('the endpoint returns analytics, signed-in users and the latest seo scan', function () {
    $workspace = authWorkspace();
    $database = fakeDatabaseSandbox($workspace);
    file_put_contents($workspace.'/.zap/seo.json', json_encode([
        'version' => 1,
        'scanned_at' => '2026-09-27T14:05:00Z',
        'score' => 104.4,
        'summary' => ' Add descriptions. ',
        'checks' => [
            ['title' => 'Page titles', 'status' => 'pass', 'detail' => 'All good.'],
            ['title' => 'Sitemap', 'status' => 'broken'],
            ['status' => 'fail'],
        ],
    ]));
    $recent = gmdate('Y-m-d H:i:s', time() - 3600);
    (new PDO('sqlite:'.$workspace.'/database/database.sqlite'))->exec("UPDATE users SET last_login_at = CASE name WHEN 'Bob' THEN '{$recent}' ELSE '2020-01-01 00:00:00' END");

    $this->provider->execUsing = function (array $command, array $env) use ($database, $workspace) {
        $script = implode(' ', $command);

        return match (true) {
            str_contains($script, 'access.log') => new ExecResult(0, json_encode(visit_(time() - 60, '1.1.1.1'))),
            str_contains($script, 'seo.json') => new ExecResult(0, (string) file_get_contents($workspace.'/.zap/seo.json')),
            str_contains($script, 'events.log') => new ExecResult(0, json_encode(['t' => (time() - 60) * 1000, 'n' => 'signed_up', 'ip' => '1.1.1.1', 'pub' => true])),
            str_contains($script, 'analytics.json') => new ExecResult(0, json_encode(['events' => [['name' => 'signed_up', 'description' => 'Someone created an account.']]])),
            default => ($database->execUsing)($command, $env),
        };
    };

    $this->actingAs($this->user)
        ->getJson(route('projects.growth.show', [$this->project, 'range' => '30d', 'traffic' => 'published']))
        ->assertOk()
        ->assertJsonPath('analytics.range', '30d')
        ->assertJsonPath('analytics.visitors', 1)
        ->assertJsonCount(30, 'analytics.visitors_over_time')
        ->assertJsonPath('events.set_up', true)
        ->assertJsonPath('events.events.0.name', 'signed_up')
        ->assertJsonPath('events.events.0.description', 'Someone created an account.')
        ->assertJsonPath('events.events.0.count', 1)
        ->assertJsonCount(30, 'events.events.0.over_time')
        ->assertJsonPath('signed_in_users', 1)
        ->assertJsonPath('seo', [
            'score' => 100,
            'scanned_at' => '2026-09-27T14:05:00Z',
            'summary' => 'Add descriptions.',
            'checks' => [
                ['title' => 'Page titles', 'status' => 'pass', 'detail' => 'All good.'],
                ['title' => 'Sitemap', 'status' => 'warn', 'detail' => null],
            ],
        ]);
})->group('GROW-001');

test('signed-in users and the seo scan are left out until the app has them', function () {
    $this->provider->execUsing = fn (array $command) => $command === ['php', WorkspaceAuth::SCRIPT]
        ? new ExecResult(0, json_encode(['ok' => false, 'error' => "Sign-in isn't set up in this app yet."]))
        : new ExecResult(0, '');

    $this->actingAs($this->user)
        ->getJson(route('projects.growth.show', $this->project))
        ->assertOk()
        ->assertJsonPath('analytics.range', '7d')
        ->assertJsonPath('analytics.visitors', 0)
        ->assertJsonPath('events', ['set_up' => false, 'events' => []])
        ->assertJsonPath('signed_in_users', null)
        ->assertJsonPath('seo', null);
})->group('GROW-001');

test('running a scan asks the agent in the chat, queued while it works', function () {
    $this->actingAs($this->user)
        ->postJson(route('projects.growth.scan', $this->project))
        ->assertOk()
        ->assertJsonPath('queued', false);

    expect($this->project->messages()->latest('id')->value('content'))
        ->toBe("Check my app's SEO and tell me what to improve. Don't change the app yet. Follow the guide at /opt/zap/guides/seo.md.");

    $this->project->update(['status' => ProjectStatus::Working]);

    $this->actingAs($this->user)
        ->postJson(route('projects.growth.scan', $this->project), ['fix' => true])
        ->assertOk()
        ->assertJsonPath('queued', true);

    expect($this->project->queuedMessages()->value('content'))
        ->toBe("Check my app's SEO and fix the problems you find. Follow the guide at /opt/zap/guides/seo.md.");
})->group('GROW-001');

test('growth validates the range, requires access, and explains a stopped sandbox', function () {
    $this->actingAs($this->user)->getJson(route('projects.growth.show', [$this->project, 'range' => '1h']))->assertUnprocessable();

    $stranger = User::factory()->has(AgentConnection::factory())->create();
    $this->actingAs($stranger)->getJson(route('projects.growth.show', $this->project))->assertForbidden();
    $this->actingAs($stranger)->postJson(route('projects.growth.scan', $this->project))->assertForbidden();
    expect($this->provider->executed)->toBe([]);

    $this->sandbox->update(['status' => SandboxStatus::Paused]);
    $this->actingAs($this->user)->getJson(route('projects.growth.show', $this->project))->assertStatus(409);
    $this->actingAs($this->user)->postJson(route('projects.growth.scan', $this->project))->assertStatus(409);
})->group('GROW-001');

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function event_(int $at, string $name, string $ip, array $overrides = []): array
{
    return ['t' => $at * 1000, 'n' => $name, 'ip' => $ip, 'pub' => true, ...$overrides];
}

test('it counts custom events against the previous period, over time, and by property value, described events first', function () {
    $ago = fn (int $seconds) => $this->now - $seconds;
    $this->provider->execUsing = fn (array $command) => new ExecResult(0, str_contains(implode(' ', $command), 'analytics.json')
        ? json_encode(['version' => 1, 'events' => [
            ['name' => 'signed_up', 'description' => ' Someone created an account. '],
            ['name' => 'project_created', 'description' => 'Someone created a project.', 'props' => ['template']],
            ['name' => 'signed_up', 'description' => 'A duplicate.'],
            ['name' => 'Not An Event'],
        ]])
        : implode("\n", array_map('json_encode', [
            event_($ago(60), 'project_created', '1.1.1.1', ['props' => ['template' => 'blank', 'shared' => true]]),
            event_($ago(70), 'project_created', '1.1.1.1', ['props' => ['template' => 'blank']]),
            event_($ago(80), 'project_created', '2.2.2.2', ['props' => ['template' => 'kanban', '0' => 'bad']]),
            event_($ago(90), 'timer_started', '2.2.2.2'),
            event_($ago(100), 'timer_started', '3.3.3.3', ['pub' => false]),
            event_($ago(110), 'zzz_rare', '3.3.3.3'),
            event_($ago(86400 + 60), 'project_created', '1.1.1.1'), // yesterday
            event_($ago(3 * 86400), 'project_created', '1.1.1.1'), // before the previous period
            ['t' => $ago(60) * 1000, 'n' => 'Bad Name', 'ip' => '1.1.1.1'],
        ]))."\nnot json\n");

    $events = app(WorkspaceAnalytics::class)->events($this->sandbox, '24h', now: $this->now);

    expect($events['set_up'])->toBeTrue()
        ->and(array_column($events['events'], 'name'))->toBe(['signed_up', 'project_created', 'timer_started', 'zzz_rare'])
        ->and($events['events'][0])->toMatchArray(['description' => 'Someone created an account.', 'count' => 0, 'visitors' => 0, 'previous_count' => 0, 'props' => []])
        ->and($events['events'][1])->toMatchArray(['count' => 3, 'visitors' => 2, 'previous_count' => 1])
        ->and($events['events'][1]['over_time'])->toHaveCount(24)
        ->and(end($events['events'][1]['over_time']))->toBe(['t' => $this->now - 1800, 'value' => 3])
        ->and($events['events'][1]['props'])->toBe([
            ['key' => 'template', 'values' => [['label' => 'blank', 'count' => 2], ['label' => 'kanban', 'count' => 1]]],
            ['key' => 'shared', 'values' => [['label' => 'true', 'count' => 1]]],
        ])
        ->and($events['events'][2])->toMatchArray(['description' => null, 'count' => 2, 'visitors' => 2]);

    $published = app(WorkspaceAnalytics::class)->events($this->sandbox, '24h', publishedOnly: true, now: $this->now);
    expect($published['events'][2]['count'])->toBe(1);
})->group('GROW-002');

test('setting up events asks the agent in the chat, with the events the user described, queued while it works', function () {
    $this->actingAs($this->user)
        ->postJson(route('projects.growth.events', $this->project))
        ->assertOk()
        ->assertJsonPath('queued', false);

    expect($this->project->messages()->latest('id')->value('content'))
        ->toBe('Add custom analytics events to my project. Follow the guide at /opt/zap/guides/analytics.md.');

    $this->project->update(['status' => ProjectStatus::Working]);

    $this->actingAs($this->user)
        ->postJson(route('projects.growth.events', $this->project), ['events' => ' when someone shares a project. '])
        ->assertOk()
        ->assertJsonPath('queued', true);

    expect($this->project->queuedMessages()->value('content'))
        ->toBe('Add these custom analytics events to my project: when someone shares a project. Follow the guide at /opt/zap/guides/analytics.md.');
})->group('GROW-002');

test('adding events validates the request, requires access, and explains a stopped sandbox', function () {
    $this->actingAs($this->user)
        ->postJson(route('projects.growth.events', $this->project), ['events' => str_repeat('a', 1001)])
        ->assertUnprocessable();

    $stranger = User::factory()->has(AgentConnection::factory())->create();
    $this->actingAs($stranger)->postJson(route('projects.growth.events', $this->project))->assertForbidden();

    $this->sandbox->update(['status' => SandboxStatus::Paused]);
    $this->actingAs($this->user)->postJson(route('projects.growth.events', $this->project))->assertStatus(409);

    expect($this->project->messages()->count())->toBe(0);
})->group('GROW-002');
