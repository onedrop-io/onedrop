<?php

namespace App\Sandbox\Agents;

use App\Enums\CredentialType;
use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Enums\SandboxStatus;
use App\Models\Message;
use App\Models\Project;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxProvider;

/**
 * Runs OpenCode inside the project's sandbox. A forwarder in the sandbox
 * (docker/sandbox/forwarder.mjs) streams its JSON events back to
 * SandboxEventController; this class only starts it and returns.
 */
class OpenCodeRunner implements AgentRunner
{
    public function __construct(protected SandboxProvider $provider) {}

    public function start(Project $project, Message $message): void
    {
        $sandbox = $project->sandbox;
        $connection = $project->user->agentConnections()->firstWhere('is_default', true);

        $problem = match (true) {
            $sandbox?->status !== SandboxStatus::Running || $sandbox->external_id === null => "Your app's sandbox isn't running, so I can't work on it right now.",
            $connection === null => 'Connect an AI in Settings → AI so I can start working.',
            $connection->credential_type === CredentialType::OAuthToken => "OpenCode can't use a Claude subscription token (Anthropic doesn't allow it). Connect an Anthropic API key or OpenRouter in Settings → AI.",
            default => null,
        };

        if ($problem) {
            $this->fail($project, $problem);

            return;
        }

        $env = [
            ...$connection->sandboxEnvironment(),
            'ZAP_PROMPT' => $message->content,
            'ZAP_MODEL' => config("sandbox.models.{$connection->provider->value}"),
            'ZAP_EVENTS_URL' => rtrim(config('sandbox.callback_url'), '/').route('sandbox-events.store', $sandbox, absolute: false),
            'ZAP_EVENTS_TOKEN' => $sandbox->issueEventsToken(),
            'ZAP_SESSION_ID' => $project->agent_session_id ?? '',
        ];

        try {
            $result = $this->provider->exec($sandbox->external_id, ['node', '/opt/zap/forwarder.mjs'], $env, detach: true);
        } catch (SandboxException $e) {
            $this->fail($project, $e->getMessage());

            return;
        }

        if (! $result->successful()) {
            $this->fail($project, "Couldn't start the agent: ".(strtok(trim($result->errorOutput), "\n") ?: 'unknown error'));
        }
    }

    /**
     * Tell the user why nothing will happen and stop showing "working".
     */
    protected function fail(Project $project, string $reason): void
    {
        $project->messages()->create(['role' => MessageRole::Assistant, 'content' => $reason]);
        $project->update(['status' => ProjectStatus::Idle]);
    }
}
