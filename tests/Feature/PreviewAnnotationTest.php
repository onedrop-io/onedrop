<?php

use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Jobs\RunAgentTask;
use App\Models\AgentConnection;
use App\Models\Message;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Agents\AgentRunner;
use App\Sandbox\Agents\ClaudeCodeRunner;
use App\Sandbox\Agents\FakeAgentRunner;
use App\Sandbox\Agents\ModelCatalog;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use App\Sandbox\WorkspaceFiles;
use Illuminate\Support\Facades\Queue;

const MARKED_UP = "preview-annotated.png is the user's marked-up screenshot of the app's preview at / (785×1102px), showing what they want changed.\n1. Note \"change to My Cool Counter\" on div.card (\"Counter Count is 0\")";

beforeEach(function () {
    Queue::fake();
    config(['services.openrouter.key' => null]);
    app()->instance(SandboxProvider::class, new FakeSandboxProvider);
    app()->instance(AgentRunner::class, new FakeAgentRunner);

    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->project = Project::factory()->for($this->user)->create(['status' => ProjectStatus::Idle]);
    Sandbox::factory()->for($this->project)->create(['external_id' => 'ctr-1']);
});

test('what a marked-up preview points at goes to the agent, not into the chat', function () {
    $this->actingAs($this->user)
        ->post(route('projects.messages.store', $this->project), ['content' => 'Make it so', 'checks' => '1', 'agent_context' => MARKED_UP])
        ->assertRedirect();

    expect($this->project->messages()->sole()->only('content', 'meta'))->toBe([
        'content' => 'Make it so',
        'meta' => ['agent_context' => MARKED_UP],
    ]);
    Queue::assertPushed(RunAgentTask::class);
})->group('AGT-013');

test('the agent sees the notes after the user\'s text', function () {
    $message = $this->project->messages()->create(['role' => MessageRole::User, 'content' => 'Make it so', 'meta' => ['agent_context' => MARKED_UP]]);
    $runner = new class(app(SandboxProvider::class), app(ModelCatalog::class), app(WorkspaceFiles::class)) extends ClaudeCodeRunner
    {
        public function promptFor(Message $message): string
        {
            return $this->prompt($message, []);
        }
    };

    expect($runner->promptFor($message))->toBe("Make it so\n\n".MARKED_UP);
})->group('AGT-013');

test('the notes are limited in size', function () {
    $this->actingAs($this->user)
        ->post(route('projects.messages.store', $this->project), ['content' => 'Make it so', 'agent_context' => str_repeat('x', 20001)])
        ->assertSessionHasErrors('agent_context');

    expect($this->project->messages()->count())->toBe(0);
})->group('AGT-013');
