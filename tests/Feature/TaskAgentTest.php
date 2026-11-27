<?php

use App\Enums\AgentHarness;
use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Models\AgentConnection;
use App\Models\Message;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Sandbox\Agents\AgentRunner;
use App\Sandbox\Agents\ClaudeCodeRunner;
use App\Sandbox\Agents\FakeAgentRunner;
use App\Sandbox\Agents\HarnessRunner;
use App\Sandbox\Agents\ModelCatalog;
use App\Sandbox\Agents\OpenCodeRunner;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['sandbox.task_copies' => false]);
    Queue::fake();
    app()->instance(AgentRunner::class, new FakeAgentRunner);

    // A Claude API key, which both OpenCode and Claude Code can run.
    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->project = Project::factory()->for($this->user)->create([
        'agent_harness' => AgentHarness::OpenCode, 'agent_provider' => 'claude', 'agent_model' => 'claude-sonnet-5',
    ]);
    $this->actingAs($this->user);

    $this->claudeCodeOpus = ['agent_harness' => 'claude_code', 'agent_provider' => 'claude', 'agent_model' => 'claude-opus-5-5', 'agent_variant' => 'high'];
});

test("a new task starts on Main's agent and model, unless its first message picks its own", function () {
    $this->post(route('projects.tasks.store', $this->project), ['title' => 'Plain card']);
    $this->post(route('projects.tasks.store', $this->project), ['content' => 'Add a footer', ...$this->claudeCodeOpus]);

    [$plain, $picked] = Task::query()->orderBy('id')->get()->all();

    expect([$plain->agent_harness, $plain->agent_model])->toBe([AgentHarness::OpenCode, 'claude-sonnet-5'])
        ->and([$picked->agent_harness, $picked->agent_model, $picked->agent_variant])->toBe([AgentHarness::ClaudeCode, 'claude-opus-5-5', 'high']);
})->group('TASK-007');

test("changing a task's agent and model changes only that task and stays on its page", function () {
    $task = Task::factory()->for($this->project)->create(['agent_session_id' => 'ses_task']);
    $other = Task::factory()->for($this->project)->create(['agent_session_id' => 'ses_other']);
    $this->project->update(['agent_session_id' => 'ses_main']);

    $this->patch(route('projects.tasks.agent.update', [$this->project, $task]), $this->claudeCodeOpus)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('projects.tasks.show', [$this->project, $task]));

    $task->refresh();
    expect([$task->agent_harness, $task->agent_model, $task->agent_variant, $task->agent_session_id])->toBe([AgentHarness::ClaudeCode, 'claude-opus-5-5', 'high', null])
        ->and($task->messages()->where('role', MessageRole::Activity)->pluck('content')->all())->toBe(['Switched to Claude Code. It starts a fresh conversation; your files are kept.'])
        ->and([$other->fresh()->agent_harness, $other->fresh()->agent_session_id])->toBe([AgentHarness::OpenCode, 'ses_other'])
        ->and([$this->project->fresh()->agent_harness, $this->project->fresh()->agent_session_id])->toBe([AgentHarness::OpenCode, 'ses_main'])
        ->and($this->project->messages()->count())->toBe(0);
})->group('TASK-007');

test("changing a task's model on the same agent keeps its conversation", function () {
    $task = Task::factory()->for($this->project)->create(['agent_session_id' => 'ses_task']);

    $this->patch(route('projects.tasks.agent.update', [$this->project, $task]), ['agent_harness' => 'opencode', 'agent_provider' => 'claude', 'agent_model' => 'claude-opus-5-5'])
        ->assertSessionHasNoErrors();

    expect([$task->fresh()->agent_model, $task->fresh()->agent_session_id])->toBe(['claude-opus-5-5', 'ses_task'])
        ->and(Message::count())->toBe(0);
})->group('TASK-007');

test("switching a task's agent is refused while it works, and Main's isn't held up by a working task", function () {
    $task = Task::factory()->for($this->project)->working()->create(['agent_session_id' => 'ses_task']);

    $this->patch(route('projects.tasks.agent.update', [$this->project, $task]), $this->claudeCodeOpus)
        ->assertSessionHasErrors(['agent_harness' => 'Wait for the agent to finish, or stop it, before switching agents.']);

    $this->patch(route('projects.agent.update', $this->project), $this->claudeCodeOpus)
        ->assertSessionHasNoErrors();

    expect([$task->fresh()->agent_harness, $task->fresh()->agent_session_id])->toBe([AgentHarness::OpenCode, 'ses_task'])
        ->and($this->project->fresh()->agent_harness)->toBe(AgentHarness::ClaudeCode);

    $task->update(['status' => ProjectStatus::Idle]);
    $this->patch(route('projects.tasks.agent.update', [$this->project, $task]), $this->claudeCodeOpus)->assertSessionHasNoErrors();
})->group('TASK-007');

test("a task's agent is validated like Main's, and only its project's owner can change it", function () {
    $task = Task::factory()->for($this->project)->create();
    $elsewhere = Task::factory()->create();

    $this->patch(route('projects.tasks.agent.update', [$this->project, $task]), ['agent_harness' => 'claude_code', 'agent_provider' => 'openrouter', 'agent_model' => 'x'])
        ->assertSessionHasErrors(['agent_provider' => 'Claude Code only runs Claude models.']);
    $this->patch(route('projects.tasks.agent.update', [$this->project, $elsewhere]), $this->claudeCodeOpus)->assertNotFound();

    $this->actingAs(User::factory()->has(AgentConnection::factory())->create())
        ->patch(route('projects.tasks.agent.update', [$this->project, $task]), $this->claudeCodeOpus)
        ->assertForbidden();

    expect($task->fresh()->agent_harness)->toBe(AgentHarness::OpenCode);
})->group('TASK-007');

test("a task's page shows its own agent and model, and a new task's shows Main's", function () {
    $task = Task::factory()->for($this->project)->create(['agent_harness' => AgentHarness::ClaudeCode, 'agent_provider' => 'claude', 'agent_model' => 'claude-opus-5-5']);

    $this->get(route('projects.tasks.show', [$this->project, $task]))
        ->assertInertia(fn ($page) => $page->where('agent.harness', 'claude_code')->where('agent.model', 'claude-opus-5-5'));
    $this->get(route('projects.tasks.create', $this->project))
        ->assertInertia(fn ($page) => $page->where('agent.harness', 'opencode')->where('agent.model', 'claude-sonnet-5'));
})->group('TASK-007');

test("a task's messages, sent by the user or not, run on the task's agent and model", function () {
    $task = Task::factory()->for($this->project)->create(['agent_harness' => AgentHarness::ClaudeCode, 'agent_provider' => 'claude', 'agent_model' => 'claude-opus-5-5']);
    $message = $task->messages()->create(['role' => MessageRole::User, 'content' => 'Bring this copy up to date']);
    $started = [];

    foreach ([OpenCodeRunner::class => 'opencode', ClaudeCodeRunner::class => 'claude_code'] as $class => $name) {
        app()->instance($class, new class($started, $name) extends FakeAgentRunner
        {
            /** @param  list<string>  $started */
            public function __construct(public array &$started, public string $name) {}

            public function start(Project $project, Message $message): void
            {
                $this->started[] = $this->name;
            }
        });
    }

    app(HarnessRunner::class)->start($this->project, $message);

    expect($started)->toBe(['claude_code'])
        ->and(app(ModelCatalog::class)->selectionFor($task, $message))->toBe(['provider' => $task->agent_provider, 'model' => 'claude-opus-5-5', 'variant' => null])
        ->and(app(ModelCatalog::class)->selectionFor($this->project)['model'])->toBe('claude-sonnet-5');
})->group('TASK-007');
