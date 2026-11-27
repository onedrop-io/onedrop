<?php

namespace App\Sandbox\Agents;

use App\Enums\AgentHarness;
use App\Enums\AgentProvider;
use App\Enums\MessageRole;
use App\Models\Message;
use App\Models\Task;
use Throwable;

/**
 * Work OneDrop sends a pull request's task's agent by itself (new commits brought in, failing checks: GIT-014,
 * GIT-015) still gets done when the owner's own AI can't run it (a Claude or ChatGPT sign-in that ran out): it moves
 * to the organization's AI credits (CREDIT-001), on OpenCode with the credits' default model, in a conversation of its
 * own, so the task's own agent keeps its conversation (GIT-029). Messages the user sends never move.
 */
class CreditsFallback
{
    /** Message meta: sent by OneDrop, not the user, so it may move to AI credits. */
    public const AUTOMATIC = 'automatic';

    /** Message meta: moved to AI credits. */
    public const MOVED = 'credits_fallback';

    public function __construct(protected AiCredits $credits, protected ModelCatalog $catalog) {}

    /**
     * Whether a run of $message moved to AI credits.
     */
    public static function moved(?Message $message): bool
    {
        return ($message?->meta[self::MOVED] ?? false) === true;
    }

    /**
     * Move $message to AI credits, saying why ($reason, e.g. "Claude isn't signed in") in the chat. False, changing
     * nothing, when it isn't OneDrop's own work for a task, already moved, or the install has no credits or none left.
     */
    public function move(Conversation $conversation, Message $message, string $reason): bool
    {
        if (! $conversation instanceof Task || ($message->meta[self::AUTOMATIC] ?? false) !== true || self::moved($message) || ! $this->hasCreditsLeft($conversation)) {
            return false;
        }

        $model = $this->catalog->defaultModel(AgentProvider::Credits);

        $message->update(['meta' => [
            ...($message->meta ?? []),
            self::MOVED => true,
            'harness' => AgentHarness::OpenCode->value,
            'selection' => ['provider' => AgentProvider::Credits->value, 'model' => $model, 'variant' => null],
        ]]);

        $name = $this->catalog->describe(['provider' => AgentProvider::Credits, 'model' => $model, 'variant' => null])['name'];
        $conversation->messages()->create([
            'role' => MessageRole::Activity,
            'content' => "{$reason}, so this runs on AI credits ({$name}) instead. Your next message goes back to the task's own agent.",
        ]);

        return true;
    }

    protected function hasCreditsLeft(Task $task): bool
    {
        if (! $this->credits->enabled()) {
            return false;
        }

        try {
            return ($this->credits->summary($task->project->organization)['left'] ?? 0) > 0;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}
