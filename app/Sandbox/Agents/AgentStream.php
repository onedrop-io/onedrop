<?php

namespace App\Sandbox\Agents;

use App\Events\AgentStreamed;
use Illuminate\Support\Facades\Cache;

/**
 * The reply or thinking an agent is writing right now (AGT-017). Each piece is kept in the cache, not the database,
 * and pushed to open pages; the whole text is saved as a message once the agent finishes it. The text so far is
 * part of the chat's live data, for a page that opens (or polls) mid-reply.
 */
class AgentStream
{
    /** Long enough to outlast a slow reply; ended sooner by the reply's message or the run's end. */
    private const SECONDS = 600;

    /** More than any one reply; the rest still arrives with the finished message. */
    private const MAX_LENGTH = 200_000;

    /**
     * Add a piece of a block (a reply or thinking, by the agent's own id) and tell open pages. A new block replaces the
     * one before it.
     */
    public function append(Conversation $conversation, string $block, string $kind, string $delta): void
    {
        if ($delta === '' || ! in_array($kind, ['text', 'thinking'], true)) {
            return;
        }

        $current = $this->current($conversation);

        if ($current === null || $current['block'] !== $block) {
            $current = ['block' => $block, 'kind' => $kind, 'seq' => 0, 'text' => ''];
        }

        if (strlen($current['text']) >= self::MAX_LENGTH) {
            return;
        }

        $current['seq']++;
        $current['text'] .= $delta;

        Cache::put($this->key($conversation), $current, self::SECONDS);

        if (self::broadcasts()) {
            AgentStreamed::dispatch($conversation->ownerProject()->id, $conversation->runKey(), $block, $kind, $current['seq'], $delta);
        }
    }

    /**
     * The block being written, or null.
     *
     * @return array{block: string, kind: string, seq: int, text: string}|null
     */
    public function current(Conversation $conversation): ?array
    {
        $current = Cache::get($this->key($conversation));

        if (! is_array($current) || ! is_string($current['block'] ?? null) || ! is_string($current['kind'] ?? null) || ! is_int($current['seq'] ?? null) || ! is_string($current['text'] ?? null)) {
            return null;
        }

        return ['block' => $current['block'], 'kind' => $current['kind'], 'seq' => $current['seq'], 'text' => $current['text']];
    }

    /**
     * The block is finished (its message is saved), or with no block, the run is over.
     */
    public function end(Conversation $conversation, ?string $block = null): void
    {
        if ($block === null || ($this->current($conversation)['block'] ?? null) === $block) {
            Cache::forget($this->key($conversation));
        }
    }

    private function key(Conversation $conversation): string
    {
        return 'agent-stream:'.$conversation->ownerProject()->id.':'.$conversation->runKey();
    }

    /**
     * Only Reverb reaches pages; other broadcasters would only fill the log with every piece.
     */
    private static function broadcasts(): bool
    {
        return config('broadcasting.default') === 'reverb';
    }
}
