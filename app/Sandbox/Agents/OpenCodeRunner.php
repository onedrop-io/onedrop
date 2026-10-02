<?php

namespace App\Sandbox\Agents;

use App\Enums\AgentHarness;
use App\Enums\AgentProvider;
use App\Enums\CredentialType;
use App\Models\Attachment;
use App\Models\Message;
use App\Models\Project;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxProvider;
use App\Sandbox\WorkspaceFiles;

/**
 * Runs OpenCode inside the project's sandbox (see SandboxAgentRunner).
 */
class OpenCodeRunner extends SandboxAgentRunner
{
    public function __construct(SandboxProvider $provider, ModelCatalog $catalog, WorkspaceFiles $files, protected ChatGptAuth $chatGpt, protected AiCredits $credits)
    {
        parent::__construct($provider, $catalog, $files);
    }

    public function start(Project $project, Message $message): void
    {
        $conversation = $message->conversation();
        $sandbox = $conversation->agentSandbox();
        $selection = $this->catalog->selectionFor($project, $message);
        // The connection for the chosen model's provider; the default one only explains why nothing is usable.
        $connection = $selection
            ? $project->user->agentConnections()->firstWhere('provider', $selection['provider'])
            : $project->user->agentConnections()->firstWhere('is_default', true);

        $onCredits = $selection !== null && $selection['provider'] === AgentProvider::Credits;

        $problem = match (true) {
            ($sandboxProblem = $this->sandboxProblem($sandbox)) !== null => $sandboxProblem,
            $onCredits => null,
            $connection === null => 'Connect an AI in Settings → AI so I can start working.',
            $connection->credential_type === CredentialType::ClaudeLogin => "OpenCode can't use a Claude subscription (Anthropic doesn't allow it). Choose Claude Code under the chat box, or connect an Anthropic API key in Settings → AI.",
            default => null,
        };

        if ($problem) {
            $this->fail($conversation, $problem);

            return;
        }

        if ($onCredits) {
            try {
                $environment = $this->credits->sandboxEnvironment($project->organization);
            } catch (OutOfAiCredits $e) {
                $this->fail($conversation, $e->getMessage());

                return;
            }
        } elseif ($connection->credential_type === CredentialType::ChatGpt) {
            try {
                $connection = $this->chatGpt->ensureFresh($connection);
            } catch (ChatGptSignInFailed $e) {
                $this->fail($conversation, $e->getMessage());

                return;
            }
        }

        try {
            $attached = $this->copyAttachments($sandbox, $message);
        } catch (SandboxException $e) {
            $this->fail($conversation, $e->getMessage());

            return;
        }

        $images = array_keys(array_filter($attached, fn (Attachment $attachment) => $attachment->isVisibleImage()));
        $vision = $images !== [] ? $this->selectionForImages($project, $conversation, $selection) : $selection;

        if ($vision === null) {
            $images = [];
        } else {
            $selection = $vision;
        }

        $this->launch($conversation, $sandbox, [
            ...($onCredits ? $environment : $connection->sandboxEnvironment()),
            'APP_AGENT' => AgentHarness::OpenCode->value,
            'APP_PROMPT' => $this->prompt($message, $attached),
            'APP_FILES' => json_encode($images, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'APP_MODEL' => $this->catalog->opencodeId($selection['provider'], $selection['model']),
            'APP_VARIANT' => $selection['variant'] ?? '',
        ]);
    }
}
