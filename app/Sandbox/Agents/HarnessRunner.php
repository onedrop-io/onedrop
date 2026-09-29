<?php

namespace App\Sandbox\Agents;

use App\Enums\AgentHarness;
use App\Models\Message;
use App\Models\Project;

/**
 * Hands each run to the agent the project uses: OpenCode or Claude Code.
 */
class HarnessRunner implements AgentRunner
{
    public function __construct(protected ModelCatalog $catalog) {}

    public function start(Project $project, Message $message): void
    {
        $this->runnerFor($project)->start($project, $message);
    }

    public function stop(Conversation $conversation): void
    {
        // Both agents run under the same forwarder, so either one stops whichever is running.
        $this->runnerFor($conversation->ownerProject())->stop($conversation);
    }

    protected function runnerFor(Project $project): AgentRunner
    {
        return app(match ($this->catalog->harnessFor($project)) {
            AgentHarness::OpenCode => OpenCodeRunner::class,
            AgentHarness::ClaudeCode => ClaudeCodeRunner::class,
        });
    }
}
