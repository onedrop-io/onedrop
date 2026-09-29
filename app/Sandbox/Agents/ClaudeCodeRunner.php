<?php

namespace App\Sandbox\Agents;

use App\Enums\AgentHarness;
use App\Enums\AgentProvider;
use App\Models\Message;
use App\Models\Project;
use App\Sandbox\SandboxException;

/**
 * Runs Claude Code (`claude -p`) inside the project's sandbox (see SandboxAgentRunner). It runs on
 * the user's Anthropic API key or Claude subscription token: Claude Code is Anthropic's own agent,
 * so, unlike OpenCode, it may use a subscription.
 */
class ClaudeCodeRunner extends SandboxAgentRunner
{
    public function start(Project $project, Message $message): void
    {
        $conversation = $message->conversation();
        $sandbox = $conversation->agentSandbox();
        $selection = $this->catalog->selectionFor($project);
        $connection = $project->user->agentConnections()->firstWhere('provider', AgentProvider::Claude);

        $problem = match (true) {
            ($sandboxProblem = $this->sandboxProblem($sandbox)) !== null => $sandboxProblem,
            $connection === null || $selection === null => 'Claude Code needs Claude: connect an Anthropic API key or a Claude subscription token in Settings → AI.',
            default => null,
        };

        if ($problem) {
            $this->fail($conversation, $problem);

            return;
        }

        try {
            $attached = $this->copyAttachments($sandbox, $message);
        } catch (SandboxException $e) {
            $this->fail($conversation, $e->getMessage());

            return;
        }

        // Claude Code looks at attached images itself (its Read tool), from the paths in the prompt.
        $this->launch($conversation, $sandbox, [
            ...$connection->sandboxEnvironment(),
            'APP_AGENT' => AgentHarness::ClaudeCode->value,
            'APP_PROMPT' => $this->prompt($message, $attached),
            'APP_MODEL' => $selection['model'],
            'APP_VARIANT' => $selection['variant'] ?? '',
        ]);
    }
}
