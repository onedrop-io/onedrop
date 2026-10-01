<?php

namespace App\Sandbox\Agents;

use App\Enums\AgentFailure;
use App\Enums\AgentHarness;
use App\Enums\AgentProvider;
use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use Illuminate\Support\Str;

/**
 * Turns OpenCode `run --format json` events (plus the forwarder's onedrop.* events)
 * into chat messages. Unknown events are ignored so OpenCode upgrades degrade gracefully.
 *
 * @see https://github.com/sst/opencode/blob/dev/packages/opencode/src/cli/cmd/run.ts
 */
class OpenCodeEvents extends AgentEvents
{
    /**
     * {@inheritDoc}
     */
    public function apply(Conversation $conversation, array $event): void
    {
        $this->rememberSession($conversation, $event['sessionID'] ?? null);

        $part = is_array($event['part'] ?? null) ? $event['part'] : [];

        match ($event['type'] ?? null) {
            'onedrop.start' => $conversation->update(['status' => ProjectStatus::Working]),
            'reasoning' => $this->say($conversation, MessageRole::Activity, 'Thinking'),
            'text' => $this->say($conversation, MessageRole::Assistant, trim((string) ($part['text'] ?? ''))),
            'tool_use' => $this->say($conversation, MessageRole::Activity, $this->describeTool($part)),
            'step_finish' => $this->stepFinished($conversation, $part, $event['model'] ?? null),
            'error' => $this->sayFailure($conversation, $this->explainError($this->errorMessage($event['error'] ?? null))),
            'onedrop.exit' => $this->finish($conversation, (int) ($event['code'] ?? 0), (string) ($event['stderr'] ?? '')),
            default => null,
        };
    }

    /**
     * A short, plain-language line for a tool call, e.g. "Editing src/App.tsx".
     *
     * @param  array<string, mixed>  $part
     */
    public function describeTool(array $part): string
    {
        $state = is_array($part['state'] ?? null) ? $part['state'] : [];
        $input = is_array($state['input'] ?? null) ? $state['input'] : [];
        $path = Str::after((string) ($input['filePath'] ?? $input['path'] ?? ''), '/workspace/');
        $failed = ($state['status'] ?? null) === 'error';

        $line = match ($part['tool'] ?? null) {
            'write' => "Creating {$path}",
            'edit', 'multiedit', 'patch' => $path !== '' ? "Editing {$path}" : 'Editing files',
            'read' => "Reading {$path}",
            'bash' => 'Running '.($input['description'] ?? '`'.Str::limit((string) ($input['command'] ?? ''), 80).'`'),
            'glob', 'grep', 'list' => 'Looking through the code',
            'todowrite', 'todoread' => 'Planning next steps',
            'webfetch', 'websearch' => 'Looking things up',
            'task' => 'Working on a subtask',
            default => 'Using '.($part['tool'] ?? 'a tool'),
        };

        return $failed ? "{$line} (failed)" : $line;
    }

    /**
     * Record a finished step's tokens and cost (USAGE-001). OpenCode doesn't say which model ran it, so the
     * forwarder adds the one it started OpenCode with ("anthropic/claude-sonnet-5"); older forwarders don't,
     * and then it's the project's current one.
     *
     * @param  array<string, mixed>  $part
     */
    protected function stepFinished(Conversation $conversation, array $part, mixed $model): void
    {
        $tokens = is_array($part['tokens'] ?? null) ? $part['tokens'] : [];
        $project = $conversation->ownerProject();
        [$catalogId, $id] = is_string($model) && str_contains($model, '/')
            ? explode('/', $model, 2)
            : [$project->agent_provider?->catalogId(), $project->agent_model ?? 'unknown'];

        $this->recordUsage($conversation, AgentHarness::OpenCode, collect(AgentProvider::cases())->first(fn (AgentProvider $provider) => $provider->catalogId() === $catalogId), $id, $part['sessionID'] ?? null, [
            // OpenCode counts reasoning separately from output; both are billed as output.
            'input' => (int) ($tokens['input'] ?? 0),
            'output' => (int) ($tokens['output'] ?? 0) + (int) ($tokens['reasoning'] ?? 0),
            'cache_read' => (int) ($tokens['cache']['read'] ?? 0),
            'cache_write' => (int) ($tokens['cache']['write'] ?? 0),
        ], (float) ($part['cost'] ?? 0));
    }

    /**
     * Plain-language version of a provider error from OpenCode.
     */
    protected function explainError(string $message): string
    {
        return match (true) {
            Str::contains($message, ['exceed your available credits', 'insufficient credits', 'Insufficient balance', 'credit balance is too low'], ignoreCase: true) => 'Your AI provider says your balance is too low for this request. Try again in a minute, or add credits (for OpenRouter: openrouter.ai/settings/credits).',
            default => $this->unmatched($message, 'Something went wrong: '.$message),
        };
    }

    /**
     * {@inheritDoc}
     */
    public function failureMessage(Conversation $conversation, AgentFailure $failure): ?string
    {
        return $failure === AgentFailure::OutOfCredits
            ? 'Your AI provider says your balance is too low for this request. Try again in a minute, or add credits (for OpenRouter: openrouter.ai/settings/credits).'
            : parent::failureMessage($conversation, $failure);
    }

    /**
     * @param  mixed  $error  OpenCode error payload, e.g. {name, data: {message}}
     */
    protected function errorMessage(mixed $error): string
    {
        if (is_array($error)) {
            return (string) ($error['data']['message'] ?? $error['message'] ?? $error['name'] ?? 'unknown error');
        }

        return is_string($error) && $error !== '' ? $error : 'unknown error';
    }
}
