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

test('the feature flags section switches flags and asks the agent to add one', function () {
    Queue::fake();
    app()->instance(AgentRunner::class, new FakeAgentRunner);

    $flags = [['key' => 'new-checkout', 'description' => 'The redesigned checkout', 'enabled' => false]];
    $provider = new FakeSandboxProvider;
    $provider->execUsing = function (array $command) use (&$flags) {
        if ($command[0] === 'php') {
            $flags[0]['enabled'] = $command[6] === '1';
        }

        return new ExecResult(0, json_encode(['version' => 1, 'flags' => $flags]));
    };
    app()->instance(SandboxProvider::class, $provider);

    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['preview_url' => null]);
    $this->actingAs($user);

    visit("/projects/{$project->id}")
        ->resize(1500, 1000)
        ->click('@tab-tools')
        ->click('@tool-flags')
        ->assertVisible('@flags-panel')
        ->assertSeeIn('@flag-new-checkout', 'The redesigned checkout')
        ->assertSeeIn('@flag-new-checkout', 'Off')
        ->click('@flag-switch-new-checkout')
        ->assertSeeIn('@flag-new-checkout', 'On')
        ->type('@flag-feature', 'The new pricing page.')
        ->click('@flag-add')
        ->assertSeeIn('@flags-notice', 'The agent is adding the flag')
        ->assertNoJavaScriptErrors();

    expect($flags[0]['enabled'])->toBeTrue()
        ->and($project->messages()->latest('id')->value('content'))->toStartWith('Put this behind a feature flag: The new pricing page.');
})->group('FLAG-001');

test('feature flags explain when the sandbox is not running', function () {
    app()->instance(SandboxProvider::class, new FakeSandboxProvider);
    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['status' => 'paused', 'preview_url' => null]);
    $this->actingAs($user);

    visit("/projects/{$project->id}")
        ->click('@tab-tools')
        ->click('@tool-flags')
        ->assertSeeIn('@flags-empty', 'Feature flags work when the sandbox is running')
        ->assertNoJavaScriptErrors();
})->group('FLAG-001');
