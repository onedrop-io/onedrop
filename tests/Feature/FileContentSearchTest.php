<?php

use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\ExecResult;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;

beforeEach(function () {
    $this->provider = new FakeSandboxProvider;
    app()->instance(SandboxProvider::class, $this->provider);

    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->project = Project::factory()->for($this->user)->create();
    Sandbox::factory()->for($this->project)->create(['external_id' => 'ctr-1']);
});

/**
 * ripgrep's --json output for the given matches.
 *
 * @param  list<array{string, int, string, list<array{int, int}>}>  $matches  path, line, text, byte ranges
 */
function rgJson(array $matches): string
{
    $lines = [];

    foreach ($matches as [$path, $line, $text, $ranges]) {
        $lines[] = json_encode(['type' => 'begin', 'data' => ['path' => ['text' => $path]]]);
        $lines[] = json_encode(['type' => 'match', 'data' => [
            'path' => ['text' => $path],
            'lines' => ['text' => $text."\n"],
            'line_number' => $line,
            'submatches' => array_map(fn (array $range) => ['match' => ['text' => ''], 'start' => $range[0], 'end' => $range[1]], $ranges),
        ]]);
        $lines[] = json_encode(['type' => 'end', 'data' => ['path' => ['text' => $path]]]);
    }

    return implode("\n", $lines)."\n";
}

test('searches inside files with ripgrep and groups matches by file', function () {
    $this->provider->execUsing = fn () => new ExecResult(0, rgJson([
        ['src/b.ts', 2, 'const héllo = "Hello";', [[16, 21]]],
        ['src/a.ts', 7, 'hello world', [[0, 5]]],
        ['src/a.ts', 9, '  say(hello)', [[6, 11]]],
    ]), "rg-exit:0\n");

    $this->actingAs($this->user)
        ->getJson(route('projects.files.search', [$this->project, 'query' => 'hello']))
        ->assertOk()
        ->assertExactJson([
            'results' => [
                ['path' => 'src/a.ts', 'matches' => [
                    ['line' => 7, 'column' => [0, 5], 'text' => 'hello world', 'ranges' => [[0, 5]]],
                    ['line' => 9, 'column' => [6, 11], 'text' => '  say(hello)', 'ranges' => [[6, 11]]],
                ]],
                // Byte offsets become character offsets.
                ['path' => 'src/b.ts', 'matches' => [
                    ['line' => 2, 'column' => [15, 20], 'text' => 'const héllo = "Hello";', 'ranges' => [[15, 20]]],
                ]],
            ],
            'truncated' => false,
        ]);

    $command = $this->provider->executed[0]['command'];
    expect(array_slice($command, 0, 2))->toBe(['sh', '-c'])
        ->and($command[2])->toContain('cd /workspace', 'rg "$@"', 'head -n')
        ->and($command)->toContain('--json', '--hidden', '--ignore-case', '--fixed-strings', '!node_modules', '!.git', '!vendor', '!.cache')
        ->and(array_slice($command, -3))->toBe(['--regexp', 'hello', '--']);
})->group('FILE-008');

test('match case, whole word, regex and a folder narrow the search', function () {
    $this->provider->execUsing = fn () => new ExecResult(0, '', "rg-exit:1\n");

    $this->actingAs($this->user)
        ->getJson(route('projects.files.search', [$this->project, 'query' => 'use\w+', 'folder' => 'src/hooks/', 'case' => 1, 'word' => 1, 'regex' => 1]))
        ->assertOk()
        ->assertExactJson(['results' => [], 'truncated' => false]);

    $command = $this->provider->executed[0]['command'];
    expect($command)->toContain('--case-sensitive', '--word-regexp')
        ->not->toContain('--fixed-strings', '--ignore-case')
        ->and(array_slice($command, -4))->toBe(['--regexp', 'use\w+', '--', 'src/hooks']);
})->group('FILE-008');

test('long lines are cut around the first match', function () {
    $text = str_repeat('a', 500).'needle'.str_repeat('b', 500);
    $this->provider->execUsing = fn () => new ExecResult(0, rgJson([['big.min.js', 1, $text, [[500, 506]]]]), "rg-exit:0\n");

    $match = $this->actingAs($this->user)
        ->getJson(route('projects.files.search', [$this->project, 'query' => 'needle']))
        ->assertOk()
        ->json('results.0.matches.0');

    expect($match['column'])->toBe([500, 506])
        ->and($match['text'])->toStartWith('…'.str_repeat('a', 40).'needle')
        ->and(mb_strlen($match['text']))->toBe(301)
        ->and($match['ranges'])->toBe([[41, 47]]);
})->group('FILE-008');

test('a search that hits the limit says the results are cut short', function () {
    $matches = array_map(fn (int $line) => ['a.txt', $line, 'x', [[0, 1]]], range(1, 1001));
    $this->provider->execUsing = fn () => new ExecResult(0, rgJson($matches), "rg-exit:141\n");

    $this->actingAs($this->user)
        ->getJson(route('projects.files.search', [$this->project, 'query' => 'x']))
        ->assertOk()
        ->assertJsonPath('truncated', true)
        ->assertJsonCount(1000, 'results.0.matches');
})->group('FILE-008');

test('an unfinished regular expression is explained, not a server error', function () {
    $this->provider->execUsing = fn () => new ExecResult(0, '', "rg: regex parse error:\n    (?:hel()\n    ^\nerror: unclosed group\nrg-exit:2\n");

    $this->actingAs($this->user)
        ->getJson(route('projects.files.search', [$this->project, 'query' => 'hel(', 'regex' => 1]))
        ->assertStatus(422)
        ->assertJsonPath('message', "That isn't a valid regular expression.");
})->group('FILE-008');

test('a sandbox without ripgrep says to recreate it', function () {
    $this->provider->execUsing = fn () => new ExecResult(0, '', "sh: 1: rg: not found\nrg-exit:127\n");

    $this->actingAs($this->user)
        ->getJson(route('projects.files.search', [$this->project, 'query' => 'x']))
        ->assertStatus(502)
        ->assertJsonPath('message', 'Searching inside files needs a newer sandbox. Recreate it to get one.');
})->group('FILE-008');

test('searching needs a query and stays inside the workspace', function (array $params) {
    $this->actingAs($this->user)
        ->getJson(route('projects.files.search', [$this->project, ...$params]))
        ->assertStatus(422);

    expect($this->provider->executed)->toBe([]);
})->with([
    'no query' => [['query' => '']],
    'parent folder' => [['query' => 'x', 'folder' => '../etc']],
    'escaping folder' => [['query' => 'x', 'folder' => 'src/../../etc']],
])->group('FILE-008');

test('other users cannot search a project\'s files', function () {
    $this->actingAs(User::factory()->has(AgentConnection::factory())->create())
        ->getJson(route('projects.files.search', [$this->project, 'query' => 'x']))
        ->assertForbidden();

    expect($this->provider->executed)->toBe([]);
})->group('FILE-008');
