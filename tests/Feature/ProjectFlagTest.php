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
use App\Sandbox\SandboxProvider;
use App\Sandbox\WorkspaceFlags;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;

beforeEach(function () {
    Queue::fake();
    app()->instance(AgentRunner::class, new FakeAgentRunner);

    $this->file = tempnam(sys_get_temp_dir(), 'flags');
    file_put_contents($this->file, json_encode([
        'version' => 1,
        'flags' => [
            ['key' => 'new-checkout', 'description' => ' The redesigned checkout ', 'enabled' => false],
            ['key' => 'dark-mode', 'enabled' => true],
            ['key' => 'Bad Key', 'enabled' => true],
            ['key' => 'new-checkout', 'enabled' => true],
            ['description' => 'no key'],
        ],
    ]));

    // Serves the sandbox's flags file from $this->file, and runs the in-sandbox script against it.
    $this->provider = new FakeSandboxProvider;
    $this->provider->execUsing = function (array $command) {
        if ($command[0] === 'php') {
            $process = new Process([PHP_BINARY, ...array_slice(array_replace($command, [4 => $this->file]), 1)]);
            $process->run();

            return new ExecResult((int) $process->getExitCode(), $process->getOutput(), $process->getErrorOutput());
        }

        return new ExecResult(0, (string) @file_get_contents($this->file));
    };
    app()->instance(SandboxProvider::class, $this->provider);

    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->project = Project::factory()->for($this->user)->create();
    $this->sandbox = Sandbox::factory()->for($this->project)->create(['external_id' => 'ctr-1']);
});

afterEach(fn () => @unlink($this->file));

test('it lists the flags, skipping bad keys and duplicates', function () {
    $this->actingAs($this->user)
        ->getJson(route('projects.flags.index', $this->project))
        ->assertOk()
        ->assertExactJson(['flags' => [
            ['key' => 'new-checkout', 'description' => 'The redesigned checkout', 'enabled' => false],
            ['key' => 'dark-mode', 'description' => null, 'enabled' => true],
        ]]);
})->group('FLAG-001');

test('an app without a flags file has no flags', function () {
    unlink($this->file);

    $this->actingAs($this->user)
        ->getJson(route('projects.flags.index', $this->project))
        ->assertOk()
        ->assertExactJson(['flags' => []]);
})->group('FLAG-001');

test('switching a flag saves it to the flags file without the agent', function () {
    $this->actingAs($this->user)
        ->patchJson(route('projects.flags.update', [$this->project, 'new-checkout']), ['enabled' => true])
        ->assertOk()
        ->assertJsonPath('flags.0.enabled', true);

    $saved = json_decode(file_get_contents($this->file), true);

    expect($saved['version'])->toBe(1)
        ->and($saved['flags'][0])->toBe(['key' => 'new-checkout', 'description' => ' The redesigned checkout ', 'enabled' => true])
        ->and($saved['flags'][1]['enabled'])->toBeTrue()
        ->and($this->project->messages()->count())->toBe(0);

    $this->actingAs($this->user)
        ->patchJson(route('projects.flags.update', [$this->project, 'dark-mode']), ['enabled' => false])
        ->assertOk()
        ->assertJsonPath('flags.1.enabled', false);
})->group('FLAG-001');

test('switching a flag that no longer exists explains it', function () {
    $this->actingAs($this->user)
        ->patchJson(route('projects.flags.update', [$this->project, 'gone']), ['enabled' => true])
        ->assertUnprocessable()
        ->assertJsonPath('message', "That flag isn't in your app anymore. It may have been removed.");

    $this->actingAs($this->user)
        ->patchJson(route('projects.flags.update', [$this->project, 'dark-mode']), ['enabled' => 'yes please'])
        ->assertJsonValidationErrors('enabled');
})->group('FLAG-001');

test('adding a flag asks the agent in the chat, queued while it works', function () {
    $this->actingAs($this->user)
        ->postJson(route('projects.flags.store', $this->project), ['feature' => 'The new pricing page.'])
        ->assertOk()
        ->assertJsonPath('queued', false);

    expect($this->project->messages()->latest('id')->value('content'))
        ->toBe('Put this behind a feature flag: The new pricing page. Follow the guide at /opt/zap/guides/flags.md.');

    $this->project->update(['status' => ProjectStatus::Working]);

    $this->actingAs($this->user)
        ->postJson(route('projects.flags.store', $this->project), ['feature' => 'Dark mode.'])
        ->assertOk()
        ->assertJsonPath('queued', true);

    $this->actingAs($this->user)
        ->postJson(route('projects.flags.store', $this->project), ['feature' => ''])
        ->assertJsonValidationErrors('feature');
})->group('FLAG-001');

test('removing a flag asks the agent to keep the feature as it is now', function () {
    $this->actingAs($this->user)
        ->deleteJson(route('projects.flags.destroy', [$this->project, 'dark-mode']))
        ->assertOk();

    expect($this->project->messages()->latest('id')->value('content'))
        ->toBe('Remove the feature flag "dark-mode" and keep the feature on for everyone. Follow the guide at '.WorkspaceFlags::GUIDE.'.');

    $this->actingAs($this->user)
        ->deleteJson(route('projects.flags.destroy', [$this->project, 'new-checkout']))
        ->assertOk()
        ->assertJsonPath('queued', true);

    expect($this->project->queuedMessages()->value('content'))->toContain('leave the feature off (remove its code)');

    $this->actingAs($this->user)
        ->deleteJson(route('projects.flags.destroy', [$this->project, 'gone']))
        ->assertUnprocessable();
})->group('FLAG-001');

test('other users cannot see or change flags', function () {
    $stranger = User::factory()->has(AgentConnection::factory())->create();

    $this->actingAs($stranger)->getJson(route('projects.flags.index', $this->project))->assertForbidden();
    $this->actingAs($stranger)->patchJson(route('projects.flags.update', [$this->project, 'dark-mode']), ['enabled' => false])->assertForbidden();
    $this->actingAs($stranger)->postJson(route('projects.flags.store', $this->project), ['feature' => 'x'])->assertForbidden();
    $this->actingAs($stranger)->deleteJson(route('projects.flags.destroy', [$this->project, 'dark-mode']))->assertForbidden();

    expect($this->provider->executed)->toBe([]);
})->group('FLAG-001');

test('it explains a stopped sandbox', function () {
    $this->sandbox->update(['status' => SandboxStatus::Paused]);

    $this->actingAs($this->user)
        ->getJson(route('projects.flags.index', $this->project))
        ->assertStatus(409)
        ->assertJsonPath('message', "The project's sandbox isn't running.");
})->group('FLAG-001');
