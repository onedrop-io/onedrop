<?php

namespace App\Sandbox\Agents;

use App\Enums\AgentHarness;
use App\Models\Message;
use App\Models\Project;

/**
 * Hands each run to the agent its chat uses (the project's main chat, or a task: TASK-007): OpenCode, Claude Code or Codex.
 */
class HarnessRunner implements AgentRunner
{
    public function __construct(protected ModelCatalog $catalog) {}

    public function start(Project $project, Message $message): void
    {
        $this->runnerFor($message->conversation(), $message)->start($project, $message);
    }

    public function stop(Conversation $conversation): void
    {
        // Every agent runs under the same forwarder, so any of them stops whichever is running.
        $this->runnerFor($conversation)->stop($conversation);
    }

    protected function runnerFor(Conversation $conversation, ?Message $message = null): AgentRunner
    {
        return app(match ($this->catalog->harnessFor($conversation, $message)) {
            AgentHarness::OpenCode => OpenCodeRunner::class,
            AgentHarness::ClaudeCode => ClaudeCodeRunner::class,
            AgentHarness::Codex => CodexRunner::class,
        });
    }
}
