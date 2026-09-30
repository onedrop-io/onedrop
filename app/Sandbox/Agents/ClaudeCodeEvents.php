<?php

namespace App\Sandbox\Agents;

use App\Enums\AgentHarness;
use App\Enums\AgentProvider;
use App\Enums\CredentialType;
use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxProvider;
use Illuminate\Support\Str;

/**
 * Turns Claude Code `claude -p --output-format stream-json` events (compacted by the forwarder)
 * into chat messages. Bumping Claude Code? Re-check these against its output.
 *
 * @see https://code.claude.com/docs/en/headless
 */
class ClaudeCodeEvents extends AgentEvents
{
    /** The chat line for a run going again after Claude rejected the login. */
    public const RETRYING_SIGN_IN = 'Claude turned down the sign-in, trying again';

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
            'result' => $this->result($conversation, $event),
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
     * The run's result: record what each model used (failed runs cost tokens too), then explain a failure.
     *
     * @param  array<string, mixed>  $event
     */
    protected function result(Conversation $conversation, array $event): void
    {
        foreach ((array) ($event['modelUsage'] ?? []) as $model => $usage) {
            if (! is_array($usage) || $model === '<synthetic>') {
                continue;
            }

            $this->recordUsage($conversation, AgentHarness::ClaudeCode, AgentProvider::Claude, (string) $model, $event['session_id'] ?? null, [
                'input' => (int) ($usage['inputTokens'] ?? 0),
                'output' => (int) ($usage['outputTokens'] ?? 0),
                'cache_read' => (int) ($usage['cacheReadInputTokens'] ?? 0),
                'cache_write' => (int) ($usage['cacheCreationInputTokens'] ?? 0),
            ], (float) ($usage['costUSD'] ?? 0));
        }

        if (! ($event['is_error'] ?? false)) {
            return;
        }

        $messageId = $conversation->messages()->reorder()->where('role', MessageRole::User)->latest('id')->value('id');
        $rejected = $this->loginRejected($conversation, $event);

        // Claude Code refreshing a shared login elsewhere revokes the token this run started with,
        // so a rejected login gets one more try before the user is asked to sign in again (AI-005).
        if ($rejected && ! $this->retriedAfterRejection($conversation, $messageId)) {
            $this->say($conversation, MessageRole::Activity, self::RETRYING_SIGN_IN);
            $conversation->update(['sign_in_retry_message_id' => $messageId]);

            return;
        }

        [$explanation, $signedOut] = $this->explainResult($conversation, $event);
        $this->say($conversation, MessageRole::Assistant, $explanation);

        // Run the message again once the user signs in (AI-005).
        if ($signedOut) {
            if ($rejected) {
                $this->clearRejectedLogin($conversation);
            }

            $conversation->update(['sign_in_retry_message_id' => $messageId]);
        }
    }

    /**
     * {@inheritDoc}
     */
    protected function finish(Conversation $conversation, int $code, string $stderr, bool $reported = false): void
    {
        $retrying = $conversation->getAttribute('sign_in_retry_message_id') !== null
            && $conversation->messages()->reorder()->latest('id')->value('content') === self::RETRYING_SIGN_IN;

        parent::finish($conversation, $code, $stderr, $reported);

        if ($retrying) {
            app(AgentQueue::class)->resumeAfterSignIn($conversation, activity: null);
        }
    }

    /**
     * Whether Claude turned down the user's Claude subscription login (rather than finding none).
     *
     * @param  array<string, mixed>  $event
     */
    protected function loginRejected(Conversation $conversation, array $event): bool
    {
        return $this->authFailed($event)
            && ! Str::contains($this->resultMessage($event), ['Not logged in', 'Please run /login'], ignoreCase: true)
            && $this->usesSubscription($conversation);
    }

    /**
     * Whether the message already had its one retry after a rejected login.
     */
    protected function retriedAfterRejection(Conversation $conversation, ?int $messageId): bool
    {
        return $conversation->messages()
            ->where('id', '>', (int) $messageId)
            ->where('role', MessageRole::Activity)
            ->where('content', self::RETRYING_SIGN_IN)
            ->exists();
    }

    /**
     * Sign Claude Code out of a login Claude rejected twice: `claude auth status` only checks that one
     * is saved, so without this the chat would keep showing the user as signed in and never offer to
     * sign in again. Runs before the run ends, so the next status check already sees it.
     */
    protected function clearRejectedLogin(Conversation $conversation): void
    {
        $sandbox = $conversation->agentSandbox();

        if ($sandbox?->external_id === null) {
            return;
        }

        try {
            app(SandboxProvider::class)->exec($sandbox->external_id, ['claude', 'auth', 'logout']);
        } catch (SandboxException) {
            // Unreachable: the status check can't reach it either, and the next run fails the same way.
        }
    }

    /**
     * Plain-language version of a failed run's result, and whether it failed because Claude Code isn't signed in.
     *
     * @param  array<string, mixed>  $event
     * @return array{string, bool}
     */
    protected function explainResult(Conversation $conversation, array $event): array
    {
        $subscription = $this->usesSubscription($conversation);
        $message = $this->resultMessage($event);
        $status = (int) ($event['api_error_status'] ?? 0);

        $retry = " I'll pick up your message as soon as you're signed in.";
        $authFailed = $this->authFailed($event);

        return match (true) {
            Str::contains($message, 'credit balance is too low', ignoreCase: true) => ['Your Anthropic account is out of credits. Add credits at console.anthropic.com, then try again.', false],
            Str::contains($message, ['usage limit', 'hit your limit', 'limit reached', 'out of extra usage'], ignoreCase: true) => ["Your Claude plan's usage limit is used up for now. Try again when it resets, or connect an Anthropic API key in Settings → AI.", false],
            Str::contains($message, ['Not logged in', 'Please run /login'], ignoreCase: true) => ['Sign in to Claude to build on your subscription: click **Sign in to Claude** under the chat box.'.$retry, true],
            $authFailed && $subscription => ['Your Claude sign-in has expired or was signed out. Click **Sign in to Claude** under the chat box to sign in again.'.$retry, true],
            $authFailed => ['Claude rejected your API key. Reconnect Claude in Settings → AI.', false],
            $status === 429 || $status === 529 || Str::contains($message, 'overloaded', ignoreCase: true) => ['Claude is overloaded right now. Try again in a minute.', false],
            default => ['Something went wrong: '.Str::limit($message, 300), false],
        };
    }

    /**
     * Whether the user builds on their Claude subscription (Claude Code's own sign-in) rather than an API key.
     */
    protected function usesSubscription(Conversation $conversation): bool
    {
        return $conversation->ownerProject()->user->agentConnections()
            ->where('provider', AgentProvider::Claude)
            ->where('credential_type', CredentialType::ClaudeLogin)
            ->exists();
    }

    /**
     * Whether Claude turned the run's credentials down.
     *
     * @param  array<string, mixed>  $event
     */
    protected function authFailed(array $event): bool
    {
        return in_array((int) ($event['api_error_status'] ?? 0), [401, 403], true)
            || Str::contains($this->resultMessage($event), ['authentication_failed', 'Failed to authenticate', 'invalid api key', 'OAuth token'], ignoreCase: true);
    }

    /**
     * A failed result's error text.
     *
     * @param  array<string, mixed>  $event
     */
    protected function resultMessage(array $event): string
    {
        return trim(implode(' ', array_filter([
            is_string($event['result'] ?? null) ? $event['result'] : null,
            ...array_filter((array) ($event['errors'] ?? []), 'is_string'),
        ]))) ?: (string) ($event['subtype'] ?? 'unknown error');
    }
}
