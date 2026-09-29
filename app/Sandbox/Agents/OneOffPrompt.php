<?php

namespace App\Sandbox\Agents;

use App\Enums\CredentialType;
use App\Enums\SandboxStatus;
use App\Models\Project;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxProvider;

/**
 * Asks the project's AI a single question with a one-off run in the sandbox (where the user's AI
 * credentials already go): OpenCode, or Claude Code on a Claude subscription (which OpenCode can't use).
 * The run gets no tools and doesn't touch the agent's session.
 */
class OneOffPrompt
{
    public function __construct(protected SandboxProvider $provider, protected ModelCatalog $catalog, protected ChatGptAuth $chatGpt) {}

    /**
     * The model's text answer.
     *
     * @throws SandboxException when the AI can't be asked or doesn't answer
     * @throws ChatGptSignInFailed when a ChatGPT sign-in can't be refreshed
     */
    public function ask(Project $project, string $prompt): string
    {
        $sandbox = $project->sandbox;
        $selection = $this->catalog->selectionFor($project);
        $connection = $selection ? $project->user->agentConnections()->firstWhere('provider', $selection['provider']) : null;

        if ($sandbox?->status !== SandboxStatus::Running || $sandbox->external_id === null) {
            throw new SandboxException("The project's sandbox isn't running.");
        }

        if ($selection === null || $connection === null) {
            throw new SandboxException('No connected AI can be asked.');
        }

        if ($connection->credential_type === CredentialType::ClaudeLogin) {
            return $this->askClaudeCode($sandbox->external_id, $selection['model'], $prompt);
        }

        if ($connection->credential_type === CredentialType::ChatGpt) {
            $connection = $this->chatGpt->ensureFresh($connection);
        }

        $result = $this->provider->exec($sandbox->external_id, [
            'bash', '-c', 'cd /tmp && exec opencode run --format json -m "$APP_MODEL" -- "$APP_PROMPT"',
        ], [
            ...$connection->sandboxEnvironment(),
            'APP_MODEL' => $this->catalog->opencodeId($selection['provider'], $selection['model']),
            'APP_PROMPT' => $prompt,
            // No project instructions and no tools: just answer.
            'OPENCODE_CONFIG_CONTENT' => json_encode([
                'autoupdate' => false,
                'share' => 'disabled',
                'permission' => ['edit' => 'deny', 'bash' => 'deny', 'webfetch' => 'deny'],
            ], JSON_THROW_ON_ERROR),
        ]);

        $text = $this->textFrom($result->output);

        if (! $result->successful() || trim($text) === '') {
            throw new SandboxException(strtok(trim($result->errorOutput), "\n") ?: 'no answer');
        }

        return $text;
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
