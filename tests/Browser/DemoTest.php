<?php

use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\ExecResult;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;

/**
 * A project with two tests and no demo yet. Saving writes the storyboard; rendering "finishes" at once, after which
 * the sandbox has a video. What was written to .onedrop/demo.json goes in $written, render commands in $renders.
 *
 * @param  list<list<string>>  $renders
 */
function demoProject(string &$written, array &$renders): Project
{
    $result = ['status' => 'passed', 'duration' => 900, 'error' => null, 'video' => null, 'trace' => null, 'run' => '1', 'ran_at' => now()->toIso8601String()];
    $tests = [
        'running' => false, 'started_at' => null, 'finished_at' => null, 'error' => null,
        'tests' => [
            ['id' => 't1', 'file' => 'tests/e2e/todos.spec.ts', 'line' => 3, 'title' => 'Todos › User should be able to add a todo', 'tags' => ['REQ-001'], 'result' => $result],
            ['id' => 't2', 'file' => 'tests/e2e/todos.spec.ts', 'line' => 9, 'title' => 'Todos › User should be able to clear done todos', 'tags' => ['REQ-002'], 'result' => $result],
        ],
    ];
    $rendered = false;

    $provider = new FakeSandboxProvider;
    $provider->execUsing = function (array $command, array $env = []) use (&$written, &$renders, &$rendered, $tests) {
        if (isset($env['APP_CONTENT'])) {
            $written .= $env['APP_CONTENT'];

            return new ExecResult(0, '');
        }

        return match (true) {
            ($command[1] ?? null) === '/opt/onedrop/tests.mjs' => new ExecResult(0, json_encode($tests)),
            ($command[1] ?? null) === '/opt/onedrop/demo.mjs' && $command[2] === 'render' => (function () use (&$renders, &$rendered, $command) {
                $renders[] = array_slice($command, 3);
                $rendered = true;

                return new ExecResult(0, '');
            })(),
            ($command[1] ?? null) === '/opt/onedrop/demo.mjs' => new ExecResult(0, json_encode([
                'running' => false, 'phase' => null, 'progress' => 0, 'started_at' => null, 'finished_at' => null, 'error' => null,
                'video' => $rendered ? ['path' => '.onedrop/demo/demo.mp4', 'size' => 1000, 'rendered_at' => now()->toIso8601String(), 'duration_ms' => 14000, 'scenes' => 2] : null,
                'storyboard' => $written !== '' ? json_decode($written, true) : null,
                'storyboard_error' => null,
            ])),
            $command[0] === 'base64' => new ExecResult(0, base64_encode('not really a video')),
            default => new ExecResult(0, ''),
        };
    };
    app()->instance(SandboxProvider::class, $provider);

    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create(['name' => 'Todos']);
    Sandbox::factory()->for($project)->create(['preview_url' => null, 'shell_url' => null]);
    test()->actingAs($user);

    return $project;
}

test('the user builds a storyboard from the tests, captions it and renders the demo', function () {
    $written = '';
    $renders = [];
    $project = demoProject($written, $renders);

    visit("/projects/{$project->id}?tool=demo")
        ->assertSeeIn('@demo-empty', 'No demo yet')
        ->assertSeeIn('@demo-no-scenes', 'No scenes yet')
        ->click('@demo-from-tests')
        ->assertValue('@demo-caption-0', 'Add a todo')
        ->assertValue('@demo-caption-1', 'Clear done todos')
        ->fill('@demo-tagline', 'Plan your day in seconds')
        ->fill('@demo-caption-1', 'Tidy up in one click')
        ->click('@demo-remove-0')
        ->assertValue('@demo-caption-0', 'Tidy up in one click')
        ->click('@demo-add-scene')
        ->click('@demo-add-t1')
        ->assertValue('@demo-caption-1', 'Add a todo')
        ->click('@demo-render')
        ->assertVisible('@demo-video')
        ->assertSeeIn('@demo-video-info', '0:14 · 2 scenes')
        ->assertVisible('@demo-download')
        ->assertNoJavaScriptErrors();

    expect(json_decode($written, true))->toMatchArray([
        'title' => 'Todos',
        'tagline' => 'Plan your day in seconds',
        'scenes' => [
            ['file' => 'tests/e2e/todos.spec.ts', 'title' => 'Todos › User should be able to clear done todos', 'caption' => 'Tidy up in one click'],
            ['file' => 'tests/e2e/todos.spec.ts', 'title' => 'Todos › User should be able to add a todo', 'caption' => 'Add a todo'],
        ],
    ])->and($renders)->toBe([['--background']]);
})->group('DEMO-001', 'DEMO-002');
