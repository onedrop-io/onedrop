<?php

namespace App\Jobs;

use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Enums\TurnOutcome;
use App\Models\Project;
use App\Models\Task;
use App\Sandbox\Agents\Jev;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * After an agent turn in the main chat or a task, ask Jev whether the agent finished or is waiting for the user
 * (a question, something only they can provide, or stuck), and keep the answer for the sidebar, the board and
 * desktop notifications (PRJ-011). Without a key, or when Jev doesn't answer, nothing is kept and they show
 * the turn as done, as before.
 */
class CheckTurnOutcome implements ShouldQueue
{
    use Queueable;

    /** How sure Jev must be of its choice for it to be kept. */
    public const THRESHOLD = 0.5;

    /** How long the sidebar holds a finished turn's notification for the answer, at most. */
    public const CHECKING_SECONDS = 20;

    public int $tries = 1;

    public int $timeout = 30;

    /**
     * @param  int  $turn  The user message the turn answered.
     */
    public function __construct(public Project|Task $conversation, public int $turn) {}

    /**
     * Forget the last turn's outcome and check the turn that just ended, when there's a key to ask Jev with.
     * Marks the conversation as being checked, so the sidebar waits for the answer before it notifies.
     */
    public static function afterTurn(Project|Task $conversation): void
    {
        if ($conversation->turn_outcome !== null) {
            $conversation->update(['turn_outcome' => null]);
        }

        $turn = $conversation->messages()->where('role', MessageRole::User)->reorder()->latest('id')->value('id');

        if ($turn === null || app(Jev::class)->endpointFor($conversation->ownerProject()) === null) {
            return;
        }

        Cache::put(self::checkingKey($conversation), true, now()->addSeconds(self::CHECKING_SECONDS));

        self::dispatch($conversation, $turn);
    }

    /**
     * Whether the turn that just ended in the conversation is still being checked.
     */
    public static function checking(Project|Task $conversation): bool
    {
        return (bool) Cache::get(self::checkingKey($conversation), false);
    }

    public function handle(Jev $jev): void
    {
        try {
            $this->check($jev);
        } finally {
            Cache::forget(self::checkingKey($this->conversation));
        }
    }

    /**
     * What Jev is asked about the turn.
     *
     * @return array<string, array{type: string, instructions: string, criteria: array<int|string, string>}>
     */
    public static function questions(): array
    {
        return [
            'outcome' => Jev::choiceQuestion(
                "How did the agent end this turn? Judge from the end of its reply to the user's request.",
                TurnOutcome::criteria(),
            ),
        ];
    }

    protected function check(Jev $jev): void
    {
        $conversation = $this->conversation->fresh();

        // Gone, or busy again with the next turn (which gets its own check).
        if ($conversation === null || $conversation->status === ProjectStatus::Working || ($endpoint = $jev->endpointFor($conversation->ownerProject())) === null) {
            return;
        }

        $since = $conversation->messages()->where('id', '>', $this->turn);

        // The user stopped it, so it isn't waiting on them.
        if ((clone $since)->where('role', MessageRole::Activity)->where('content', 'Stopped')->exists()) {
            return;
        }

        $reply = (clone $since)->where('role', MessageRole::Assistant)->pluck('content')->implode("\n\n");

        if ($reply === '') {
            return;
        }

        try {
            $answer = $jev->decide($endpoint, $this->state($conversation, $reply), self::questions(), timeout: 15)['outcome'];
        } catch (Throwable $e) {
            report($e);

            return;
        }

        $outcome = TurnOutcome::tryFrom((string) ($answer['choice'] ?? ''));

        if ($outcome === null || (float) ($answer['probabilities'][$outcome->value] ?? 0) < self::THRESHOLD) {
            return;
        }

        $conversation->update(['turn_outcome' => $outcome]);
    }

    /**
     * What Jev sees: the request, and the end of the agent's reply (where a question would be).
     *
     * @return array{user_request: string, agent_reply: string}
     */
    protected function state(Project|Task $conversation, string $reply): array
    {
        return [
            'user_request' => Str::limit($conversation->messages()->whereKey($this->turn)->value('content') ?? '', 2000),
            'agent_reply' => mb_strlen($reply) > 3000 ? '…'.mb_substr($reply, -3000) : $reply,
        ];
    }

    protected static function checkingKey(Project|Task $conversation): string
    {
        return 'turn-outcome-checking:'.($conversation instanceof Task ? 'task' : 'project').":{$conversation->id}";
    }
}
