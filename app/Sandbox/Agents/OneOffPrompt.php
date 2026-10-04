<?php

namespace App\Sandbox\Agents;

use App\Enums\AgentHarness;
use App\Enums\AgentProvider;
use App\Enums\CredentialType;
use App\Enums\SandboxStatus;
use App\Models\Project;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxProvider;

/**
 * Asks the project's AI a single question with a one-off run in the sandbox (where the user's AI
 * credentials already go): OpenCode, or Claude Code on a Claude subscription (which OpenCode can't use).
 * The run gets no tools that change anything and doesn't touch the agent's session.
 */
class OneOffPrompt
{
    public function __construct(protected SandboxProvider $provider, protected ModelCatalog $catalog, protected ChatGptAuth $chatGpt, protected AiCredits $credits) {}

    /**
     * The model's text answer.
     *
     * @throws SandboxException when the AI can't be asked or doesn't answer
     * @throws ChatGptSignInFailed when a ChatGPT sign-in can't be refreshed
     */
    public function ask(Project $project, string $prompt): string
    {
        $sandbox = $project->sandbox;

        if ($sandbox?->status !== SandboxStatus::Running || $sandbox->external_id === null) {
            throw new SandboxException("The project's sandbox isn't running.");
        }

        $setup = $this->setup($project);

        if ($setup['harness'] === AgentHarness::ClaudeCode) {
            return $this->askClaudeCode($sandbox->external_id, $setup['model'], $prompt);
        }

        $result = $this->provider->exec($sandbox->external_id, [
            'bash', '-c', 'cd /tmp && exec opencode run --format json -m "$APP_MODEL" -- "$APP_PROMPT"',
        ], [
            ...$setup['env'],
            'APP_MODEL' => $setup['model'],
            'APP_PROMPT' => $prompt,
        ]);

        $text = $this->textFrom($result->output);

        if (! $result->successful() || trim($text) === '') {
            throw new SandboxException(strtok(trim($result->errorOutput), "\n") ?: 'no answer');
        }

        return $text;
    }

    /**
     * How to ask the project's AI, here or from `ask` in the sandbox's shell (SBX-012): OpenCode with the model and
     * an environment holding the credentials and a config that lets it read but not change or run anything, or Claude
     * Code on a Claude subscription (which OpenCode can't use), signed in inside the sandbox, so no environment.
     *
     * @return array{harness: AgentHarness, model: string, env: array<string, string>}
     *
     * @throws SandboxException when no AI can be asked
     * @throws ChatGptSignInFailed when a ChatGPT sign-in can't be refreshed
     */
    public function setup(Project $project): array
    {
        $selection = $this->catalog->selectionFor($project);
        $connection = $selection ? $project->user->agentConnections()->firstWhere('provider', $selection['provider']) : null;
        $onCredits = $selection !== null && $selection['provider'] === AgentProvider::Credits;

        if ($selection === null || ($connection === null && ! $onCredits)) {
            throw new SandboxException('No connected AI can be asked.');
        }

        if ($onCredits) {
            try {
                $environment = $this->credits->sandboxEnvironment($project->organization);
            } catch (OutOfAiCredits $e) {
                throw new SandboxException($e->getMessage());
            }
        } elseif ($connection->credential_type === CredentialType::ClaudeLogin) {
            return ['harness' => AgentHarness::ClaudeCode, 'model' => $selection['model'], 'env' => []];
        } else {
            if ($connection->credential_type === CredentialType::ChatGpt) {
                $connection = $this->chatGpt->ensureFresh($connection);
            }

            $environment = $connection->sandboxEnvironment();
        }

        return [
            'harness' => AgentHarness::OpenCode,
            'model' => $this->catalog->opencodeId($selection['provider'], $selection['model']),
            'env' => [
                ...$environment,
                // No tools that change or run anything: just answer. Keeps a connection's own config (an Ollama server's).
                'OPENCODE_CONFIG_CONTENT' => json_encode([
                    ...json_decode($environment['OPENCODE_CONFIG_CONTENT'] ?? '{}', true),
                    'autoupdate' => false,
                    'share' => 'disabled',
                    'permission' => ['edit' => 'deny', 'bash' => 'deny', 'webfetch' => 'deny'],
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ],
        ];
    }

    /**
     * Ask through Claude Code's own sign-in in the sandbox (AI-005), with no tools. The prompt goes in on
     * stdin, and any key is left out so the run can't bill an API account instead of the subscription.
     *
     * @throws SandboxException
     */
    protected function askClaudeCode(string $sandboxId, string $model, string $prompt): string
    {
        $result = $this->provider->exec($sandboxId, [
            'bash', '-c', 'cd /tmp && printf %s "$APP_PROMPT" | exec env -u ANTHROPIC_API_KEY -u ANTHROPIC_AUTH_TOKEN -u CLAUDE_CODE_OAUTH_TOKEN DISABLE_AUTOUPDATER=1 claude -p --output-format json --no-session-persistence --tools "" --model "$APP_MODEL"',
        ], [
            'APP_MODEL' => $model,
            'APP_PROMPT' => $prompt,
        ]);

        $answer = json_decode($result->output, true);
        $text = is_array($answer) && ! ($answer['is_error'] ?? false) ? (string) ($answer['result'] ?? '') : '';

        if (! $result->successful() || trim($text) === '') {
            $error = is_array($answer) ? (string) ($answer['result'] ?? '') : '';

            throw new SandboxException(strtok(trim($error ?: $result->errorOutput), "\n") ?: 'no answer');
        }

        return $text;
    }

    /**
     * The model's answer from OpenCode's JSON events.
     */
    protected function textFrom(string $output): string
    {
        return collect(explode("\n", $output))
            ->map(fn (string $line) => json_decode($line, true))
            ->filter(fn ($event) => is_array($event) && ($event['type'] ?? null) === 'text')
            ->map(fn (array $event) => (string) ($event['part']['text'] ?? ''))
            ->implode('');
    }
}
