<?php

use App\Enums\AgentHarness;
use App\Enums\AgentProvider;
use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\Skill;
use App\Models\User;
use App\Sandbox\Agents\AgentCommands;
use App\Sandbox\Agents\HarnessRunner;
use App\Sandbox\ExecResult;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->provider = new class extends FakeSandboxProvider
    {
        /** @var list<array<string, string>> */
        public array $envs = [];

        public function exec(string $id, array $command, array $env = [], bool $detach = false, bool $root = false): ExecResult
        {
            // Only the forwarder's: the skills sync (SandboxSkills) runs first.
            if ($command !== ['php', '/opt/onedrop/skills.php']) {
                $this->envs[] = $env;
            }

            return parent::exec($id, $command, $env, $detach, $root);
        }
    };
    app()->instance(SandboxProvider::class, $this->provider);

    $this->user = User::factory()->create();
});

/**
 * A project on $harness with a running sandbox.
 */
function commandsProject(User $user, AgentHarness $harness, AgentProvider $provider, string $model): Project
{
    $project = Project::factory()->for($user)->create([
        'status' => ProjectStatus::Working,
        'agent_harness' => $harness,
        'agent_provider' => $provider,
        'agent_model' => $model,
    ]);
    Sandbox::factory()->for($project)->create(['external_id' => 'ctr-1']);

    return $project;
}

/**
 * Run $content in the project's agent and return the forwarder's environment.
 *
 * @return array<string, string>
 */
function runCommand(Project $project, string $content): array
{
    $message = $project->messages()->create(['role' => MessageRole::User, 'content' => $content]);

    app(HarnessRunner::class)->start($project, $message);

    return test()->provider->envs[0];
}

test('before its first run, Claude Code offers its usual built-ins and the project\'s skills', function () {
    AgentConnection::factory()->for($this->user)->claudeLogin()->create();
    $project = commandsProject($this->user, AgentHarness::ClaudeCode, AgentProvider::Claude, 'claude-opus-5-5');
    $project->skills()->attach(Skill::factory()->for($this->user)->create(['name' => 'brand-voice', 'description' => 'Write in our voice']));

    $names = array_column(app(AgentCommands::class)->for($project), 'name');

    expect($names)->toBe(['compact', 'context', 'init', 'security-review', 'usage', 'brand-voice']);
})->group('AGT-016');

test('the commands Claude Code reports replace its usual ones, without the ones the chat has controls for', function () {
    AgentConnection::factory()->for($this->user)->claudeLogin()->create();
    $project = commandsProject($this->user, AgentHarness::ClaudeCode, AgentProvider::Claude, 'claude-opus-5-5');
    $sandbox = $project->sandbox;

    $this->withToken($sandbox->issueEventsToken())
        ->postJson(route('sandbox-events.store', $sandbox), ['agent' => 'claude_code', 'events' => [
            ['type' => 'system', 'subtype' => 'commands_changed', 'commands' => [
                ['name' => 'model', 'description' => 'Set the AI model for Claude Code', 'argumentHint' => '<model>', 'builtin' => true],
                ['name' => 'clear', 'description' => 'Start a new session', 'builtin' => true],
                ['name' => 'frontend-design:frontend-design', 'description' => 'Distinctive UI design'],
                ['name' => 'improve', 'description' => 'Survey a codebase (user)', 'argumentHint' => ''],
                ['name' => '__remote-workflow', 'description' => ''],
                ['name' => 'compact', 'description' => 'Free up context', 'argumentHint' => '<instructions>', 'builtin' => true],
            ]],
        ]])
        ->assertOk();

    expect(app(AgentCommands::class)->for($project->fresh()))->toBe([
        ['name' => 'compact', 'description' => 'Free up context', 'hint' => '<instructions>'],
        ['name' => 'frontend-design:frontend-design', 'description' => 'Distinctive UI design', 'hint' => ''],
        ['name' => 'improve', 'description' => 'Survey a codebase', 'hint' => ''],
    ]);

    $this->actingAs($this->user)
        ->get(route('projects.show', $project))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('agentCommands.0.name', 'compact')
            ->has('agentCommands', 3));
})->group('AGT-016');

test('a Claude Code command that only shows something becomes the reply', function () {
    $project = Project::factory()->for($this->user)->create(['status' => ProjectStatus::Working]);
    $sandbox = Sandbox::factory()->for($project)->create();

    $this->withToken($sandbox->issueEventsToken())
        ->postJson(route('sandbox-events.store', $sandbox), ['agent' => 'claude_code', 'events' => [
            ['type' => 'assistant', 'message' => ['model' => '<synthetic>', 'content' => [['type' => 'text', 'text' => '## Context Usage']]]],
            ['type' => 'result', 'subtype' => 'success', 'is_error' => false, 'num_turns' => 0, 'result' => "## Context Usage\n\n12% used"],
            ['type' => 'onedrop.exit', 'code' => 0, 'stderr' => '', 'reported' => false],
        ]])
        ->assertOk();

    expect($project->messages()->pluck('content')->all())->toBe(["## Context Usage\n\n12% used"]);
})->group('AGT-016');

test('Claude Code gets a command as it is', function () {
    AgentConnection::factory()->for($this->user)->claudeLogin()->create();
    $project = commandsProject($this->user, AgentHarness::ClaudeCode, AgentProvider::Claude, 'claude-opus-5-5');

    expect(runCommand($project, '/compact keep the API notes'))
        ->toMatchArray(['APP_PROMPT' => '/compact keep the API notes'])
        ->not->toHaveKey('APP_COMMAND');
})->group('AGT-016');

test('OpenCode runs one of its commands with the details as the message', function (string $content, string $command, string $prompt) {
    AgentConnection::factory()->for($this->user)->provider(AgentProvider::OpenRouter)->create();
    $project = commandsProject($this->user, AgentHarness::OpenCode, AgentProvider::OpenRouter, 'anthropic/claude-sonnet-5');
    $project->skills()->attach(Skill::factory()->for($this->user)->create(['name' => 'brand-voice']));

    expect(runCommand($project, $content))->toMatchArray(['APP_COMMAND' => $command, 'APP_PROMPT' => $prompt]);
})->with([
    'a built-in with details' => ['/review the API changes', 'review', 'the API changes'],
    'a built-in alone' => ['/init', 'init', ''],
    'a skill' => ['/brand-voice the home page', 'brand-voice', 'the home page'],
    'not a command' => ['/etc/hosts is wrong', '', '/etc/hosts is wrong'],
])->group('AGT-016');

test('Codex gets the prompt a command stands for', function (string $content, string $prompt) {
    AgentConnection::factory()->for($this->user)->chatGpt(['id_token' => 'chatgpt-id-token'])->create();
    $project = commandsProject($this->user, AgentHarness::Codex, AgentProvider::Codex, 'gpt-5.5');
    $project->skills()->attach(Skill::factory()->for($this->user)->create(['name' => 'brand-voice']));

    expect(runCommand($project, $content)['APP_PROMPT'])->toStartWith($prompt);
})->with([
    'review' => ['/review', 'Review the current code changes'],
    'init' => ['/init', 'Generate a file named AGENTS.md'],
    'a skill' => ['/brand-voice the home page', "\$brand-voice\n\nthe home page"],
    'not a command' => ['/nope', '/nope'],
])->group('AGT-016');
