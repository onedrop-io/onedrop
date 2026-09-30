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
 * Runs OpenAI's Codex CLI (`codex exec --json`) inside the project's sandbox (see SandboxAgentRunner),
 * on the user's ChatGPT sign-in or OpenAI API key. The forwarder writes the sign-in to Codex's auth.json.
 */
class CodexRunner extends SandboxAgentRunner
{
    public function __construct(SandboxProvider $provider, ModelCatalog $catalog, WorkspaceFiles $files, protected ChatGptAuth $chatGpt)
    {
        parent::__construct($provider, $catalog, $files);
    }

    public function start(Project $project, Message $message): void
    {
        $conversation = $message->conversation();
        $sandbox = $conversation->agentSandbox();
        $selection = $this->catalog->selectionFor($project);
        $connection = $project->user->agentConnections()->firstWhere('provider', AgentProvider::Codex);

        $problem = match (true) {
            ($sandboxProblem = $this->sandboxProblem($sandbox)) !== null => $sandboxProblem,
            $connection === null || $selection === null => 'Codex needs OpenAI: sign in with ChatGPT or connect an OpenAI API key in Settings → AI.',
            default => null,
        };

        if ($problem) {
            $this->fail($conversation, $problem);

            return;
        }

        if ($connection->credential_type === CredentialType::ChatGpt) {
            try {
                $connection = $this->chatGpt->ensureFresh($connection, needsIdToken: true);
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
            'APP_AGENT' => AgentHarness::Codex->value,
            'CODEX_AUTH_CONTENT' => json_encode($connection->codexAuth(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'APP_PROMPT' => $this->prompt($message, $attached),
            'APP_FILES' => json_encode($images, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'APP_MODEL' => $selection['model'],
            'APP_VARIANT' => $selection['variant'] ?? '',
        ]);
    }
}
