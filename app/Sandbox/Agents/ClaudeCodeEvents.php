<?php

namespace App\Sandbox\Agents;

use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use Illuminate\Support\Str;

/**
 * Turns Claude Code `claude -p --output-format stream-json` events (compacted by the forwarder)
 * into chat messages. Bumping Claude Code? Re-check these against its output.
 *
 * @see https://code.claude.com/docs/en/headless
 */
class ClaudeCodeEvents extends AgentEvents
{
    /**
     * {@inheritDoc}
     */
    public function apply(Conversation $conversation, array $event): void
    {
        $this->rememberSession($conversation, $event['session_id'] ?? null);

        match ($event['type'] ?? null) {
            'zap.start' => $conversation->update(['status' => ProjectStatus::Working]),
            'assistant' => $this->assistant($conversation, is_array($event['message'] ?? null) ? $event['message'] : []),
            'system' => ($event['subtype'] ?? null) === 'api_retry' ? $this->say($conversation, MessageRole::Activity, 'Claude is busy, retrying') : null,
            'result' => ($event['is_error'] ?? false) ? $this->say($conversation, MessageRole::Assistant, $this->explainResult($event)) : null,
            'zap.exit' => $this->finish($conversation, (int) ($event['code'] ?? 0), (string) ($event['stderr'] ?? ''), (bool) ($event['reported'] ?? false)),
            default => null,
        };
    }

    /**
     * A short, plain-language line for a tool call, e.g. "Editing src/App.tsx".
     *
     * @param  array<string, mixed>  $input
     */
    public function describeTool(string $tool, array $input): string
    {
        $path = Str::after((string) ($input['file_path'] ?? $input['notebook_path'] ?? $input['path'] ?? ''), '/workspace/');

        return match ($tool) {
            'Write' => "Creating {$path}",
            'Edit', 'MultiEdit', 'NotebookEdit' => $path !== '' ? "Editing {$path}" : 'Editing files',
            'Read' => "Reading {$path}",
            'Bash' => 'Running '.($input['description'] ?? '`'.Str::limit((string) ($input['command'] ?? ''), 80).'`'),
            'Glob', 'Grep', 'LS' => 'Looking through the code',
            'TodoWrite' => 'Planning next steps',
            'WebFetch', 'WebSearch' => 'Looking things up',
            'Task', 'Agent' => 'Working on a subtask',
            default => "Using {$tool}",
        };
    }

    /**
     * One assistant message: its thinking, replies and tool calls, in order. Claude Code's
     * own error notices ("<synthetic>" messages) are skipped; the result event explains them.
     *
     * @param  array<string, mixed>  $message
     */
    protected function assistant(Conversation $conversation, array $message): void
    {
        if (($message['model'] ?? null) === '<synthetic>') {
            return;
        }

        foreach ((array) ($message['content'] ?? []) as $block) {
            match (is_array($block) ? ($block['type'] ?? null) : null) {
                'thinking', 'redacted_thinking' => $this->say($conversation, MessageRole::Activity, 'Thinking'),
                'text' => $this->say($conversation, MessageRole::Assistant, trim((string) ($block['text'] ?? ''))),
                'tool_use' => $this->say($conversation, MessageRole::Activity, $this->describeTool((string) ($block['name'] ?? 'a tool'), is_array($block['input'] ?? null) ? $block['input'] : [])),
                default => null,
            };
        }
    }

    /**
     * Plain-language version of a failed run's result.
     *
     * @param  array<string, mixed>  $event
     */
    protected function explainResult(array $event): string
    {
        $message = trim(implode(' ', array_filter([
            is_string($event['result'] ?? null) ? $event['result'] : null,
            ...array_filter((array) ($event['errors'] ?? []), 'is_string'),
        ]))) ?: (string) ($event['subtype'] ?? 'unknown error');
        $status = (int) ($event['api_error_status'] ?? 0);

        return match (true) {
            Str::contains($message, 'credit balance is too low', ignoreCase: true) => 'Your Anthropic account is out of credits. Add credits at console.anthropic.com, then try again.',
            Str::contains($message, ['usage limit', 'hit your limit', 'limit reached', 'out of extra usage', 'Agent SDK credit'], ignoreCase: true) => "Your Claude plan's usage limit (or its monthly Agent SDK credit) is used up for now. Try again later, or connect an Anthropic API key in Settings → AI.",
            in_array($status, [401, 403], true) || Str::contains($message, ['authentication_failed', 'Failed to authenticate', 'invalid api key', 'OAuth token'], ignoreCase: true) => 'Claude rejected your key or token. Reconnect Claude in Settings → AI (for a subscription, run `claude setup-token` again).',
            $status === 429 || $status === 529 || Str::contains($message, 'overloaded', ignoreCase: true) => 'Claude is overloaded right now. Try again in a minute.',
            default => 'Something went wrong: '.Str::limit($message, 300),
        };
    }
}
