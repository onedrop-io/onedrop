<?php

namespace App\Sandbox\Agents;

use App\Enums\AgentHarness;
use App\Enums\AgentProvider;
use App\Enums\CredentialType;
use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use Illuminate\Support\Str;

/**
 * Turns Codex CLI `codex exec --json` events (compacted by the forwarder) into chat messages.
 * Bumping Codex? Re-check these against its output.
 *
 * @see https://developers.openai.com/codex/noninteractive
 */
class CodexEvents extends AgentEvents
{
    /**
     * {@inheritDoc}
     */
    public function apply(Conversation $conversation, array $event): void
    {
        $item = is_array($event['item'] ?? null) ? $event['item'] : [];

        match ($event['type'] ?? null) {
            'onedrop.start' => $conversation->update(['status' => ProjectStatus::Working]),
            'thread.started' => $this->rememberSession($conversation, $event['thread_id'] ?? null),
            'item.started' => $this->started($conversation, $item),
            'item.completed' => $this->completed($conversation, $item),
            // Codex retries a failed request a few times before giving up with turn.failed.
            'error' => str_starts_with((string) ($event['message'] ?? ''), 'Reconnecting') ? $this->say($conversation, MessageRole::Activity, 'Reconnecting to OpenAI') : null,
            'turn.completed' => $this->turnCompleted($conversation, is_array($event['usage'] ?? null) ? $event['usage'] : [], $event['model'] ?? null),
            'turn.failed' => $this->say($conversation, MessageRole::Assistant, $this->explainError($conversation, $this->errorMessage($event['error']['message'] ?? null))),
            'onedrop.exit' => $this->finish($conversation, (int) ($event['code'] ?? 0), (string) ($event['stderr'] ?? ''), (bool) ($event['reported'] ?? false)),
            default => null,
        };
    }

    /**
     * A step starting: commands and lookups are shown as they begin, since they can take a while.
     *
     * @param  array<string, mixed>  $item
     */
    protected function started(Conversation $conversation, array $item): void
    {
        $line = match ($item['type'] ?? null) {
            'command_execution' => 'Running `'.Str::limit($this->command((string) ($item['command'] ?? '')), 80).'`',
            'mcp_tool_call' => 'Using '.($item['tool'] ?? 'a tool'),
            'web_search' => 'Looking things up',
            'todo_list' => 'Planning next steps',
            default => null,
        };

        if ($line !== null) {
            $this->say($conversation, MessageRole::Activity, $line);
        }
    }

    /**
     * A finished step: replies, thinking and file changes.
     *
     * @param  array<string, mixed>  $item
     */
    protected function completed(Conversation $conversation, array $item): void
    {
        match ($item['type'] ?? null) {
            'agent_message' => $this->say($conversation, MessageRole::Assistant, trim((string) ($item['text'] ?? ''))),
            'reasoning' => $this->say($conversation, MessageRole::Activity, 'Thinking'),
            'file_change' => collect((array) ($item['changes'] ?? []))
                ->filter(fn ($change) => is_array($change))
                ->each(fn (array $change) => $this->say($conversation, MessageRole::Activity, $this->describeChange($change))),
            default => null,
        };
    }

    /**
     * A short line for a changed file, e.g. "Editing src/App.tsx".
     *
     * @param  array<string, mixed>  $change
     */
    public function describeChange(array $change): string
    {
        $path = Str::after((string) ($change['path'] ?? ''), '/workspace/');

        return match ($change['kind'] ?? null) {
            'add' => "Creating {$path}",
            'delete' => "Deleting {$path}",
            default => $path !== '' ? "Editing {$path}" : 'Editing files',
        };
    }

    /**
     * The command Codex ran, without the shell it wraps it in ("/bin/bash -lc 'npm install'").
     */
    protected function command(string $command): string
    {
        if (! preg_match('/^\S*\b(?:ba|z)?sh -lc (.*)$/s', $command, $match)) {
            return $command;
        }

        $inner = $match[1];

        return strlen($inner) > 1 && in_array($inner[0], ["'", '"'], true) && str_ends_with($inner, $inner[0]) ? substr($inner, 1, -1) : $inner;
    }

    /**
     * Record the turn's tokens (USAGE-001). Codex counts cached input inside the input and reasoning inside
     * the output, and doesn't price them, so the cost is estimated from the catalog's API prices (cached
     * input at a tenth of the input price), on a ChatGPT sign-in too.
     *
     * @param  array<string, mixed>  $usage
     */
    protected function turnCompleted(Conversation $conversation, array $usage, mixed $model): void
    {
        $model = is_string($model) && $model !== '' ? $model : ($conversation->ownerProject()->agent_model ?? 'unknown');
        $cached = (int) ($usage['cached_input_tokens'] ?? 0);
        $cacheWrite = (int) ($usage['cache_write_input_tokens'] ?? 0);
        $input = max(0, (int) ($usage['input_tokens'] ?? 0) - $cached - $cacheWrite);
        $output = (int) ($usage['output_tokens'] ?? 0);

        $price = app(ModelCatalog::class)->find(AgentProvider::Codex, $model)['cost'] ?? null;
        $cost = $price ? (($input + $cacheWrite) * $price['input'] + $cached * $price['input'] / 10 + $output * $price['output']) / 1_000_000 : 0.0;

        $this->recordUsage($conversation, AgentHarness::Codex, AgentProvider::Codex, $model, null, [
            'input' => $input,
            'output' => $output,
            'cache_read' => $cached,
            'cache_write' => $cacheWrite,
        ], $cost);
    }

    /**
     * Plain-language version of a failed turn's error.
     */
    protected function explainError(Conversation $conversation, string $message): string
    {
        $chatGpt = $this->signedInWithChatGpt($conversation);

        return match (true) {
            Str::contains($message, ['usage limit', 'hit your limit'], ignoreCase: true) => "Your ChatGPT plan's Codex usage limit is used up for now. Try again when it resets, or connect an OpenAI API key in Settings → AI.",
            Str::contains($message, ['insufficient_quota', 'exceeded your current quota'], ignoreCase: true) => 'Your OpenAI account is out of credits. Add credits at platform.openai.com, then try again.',
            Str::contains($message, 'not supported when using Codex with a ChatGPT account', ignoreCase: true) => "That model isn't included with ChatGPT. Choose another model under the chat box.",
            Str::contains($message, ['Unauthorized', 'invalid_api_key', 'Incorrect API key'], ignoreCase: true) => $chatGpt
                ? 'OpenAI turned down your ChatGPT sign-in. Sign in with ChatGPT again in Settings → AI.'
                : 'OpenAI rejected your API key. Reconnect Codex in Settings → AI.',
            Str::contains($message, ['Too Many Requests', 'rate limit', 'overloaded', 'Service Unavailable'], ignoreCase: true) => 'OpenAI is busy right now. Try again in a minute.',
            default => 'Something went wrong: '.Str::limit($message, 300),
        };
    }

    /**
     * A failed turn's error text. Codex passes API errors on as their JSON body.
     */
    protected function errorMessage(mixed $message): string
    {
        $message = is_string($message) && $message !== '' ? $message : 'unknown error';
        $body = json_decode($message, true);

        return is_array($body) && is_string($body['error']['message'] ?? null) ? $body['error']['message'] : $message;
    }

    /**
     * Whether the project's owner runs Codex on their ChatGPT sign-in rather than an API key.
     */
    protected function signedInWithChatGpt(Conversation $conversation): bool
    {
        return $conversation->ownerProject()->user->agentConnections()
            ->where('provider', AgentProvider::Codex)
            ->where('credential_type', CredentialType::ChatGpt)
            ->exists();
    }
}
