<?php

namespace App\Jobs;

use App\Enums\AgentFailure;
use App\Enums\MessageRole;
use App\Models\Message;
use App\Models\Project;
use App\Models\Task;
use App\Sandbox\Agents\AgentEvents;
use App\Sandbox\Agents\Jev;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * When a failed run's error matched none of the known causes and the chat got the generic "Something went wrong"
 * message, ask Jev which known cause it is, and when it's sure, replace that message with the cause's (AGT-010).
 */
class ExplainAgentFailure implements ShouldQueue
{
    use Queueable;

    /** How sure Jev must be of a cause for its message to replace the generic one. */
    public const THRESHOLD = 0.7;

    public int $tries = 1;

    public int $timeout = 30;

    /**
     * @param  int  $message  The chat message with the generic explanation.
     * @param  string  $explanation  That explanation, replaced only while the message still says it.
     * @param  class-string<AgentEvents>  $events  The harness's events class, which words each cause.
     */
    public function __construct(public Project|Task $conversation, public int $message, public string $explanation, public string $error, public string $events) {}

    /**
     * Look again at the failure the generic $message explains, when there's a key to ask Jev with.
     *
     * @param  class-string<AgentEvents>  $events
     */
    public static function afterFailure(Project|Task $conversation, Message $message, string $error, string $events): void
    {
        if (trim($error) === '' || app(Jev::class)->endpointFor($conversation->ownerProject()) === null) {
            return;
        }

        self::dispatch($conversation, $message->id, $message->content, $error, $events);
    }

    public function handle(Jev $jev): void
    {
        $conversation = $this->conversation->fresh();
        $message = $conversation?->messages()->where('role', MessageRole::Assistant)->find($this->message);

        if ($conversation === null || $message?->content !== $this->explanation || ($endpoint = $jev->endpointFor($conversation->ownerProject())) === null) {
            return;
        }

        try {
            $answer = $jev->decide($endpoint, ['error' => self::tail($this->error)], self::questions(), timeout: 15)['cause'];
        } catch (Throwable $e) {
            report($e);

            return;
        }

        $failure = AgentFailure::tryFrom((string) ($answer['choice'] ?? ''));

        if ($failure === null || $failure === AgentFailure::Other || (float) ($answer['probabilities'][$failure->value] ?? 0) < self::THRESHOLD) {
            return;
        }

        $explanation = app($this->events)->failureMessage($conversation, $failure);

        // Unless it changed in the meantime.
        if ($explanation !== null && $message->fresh()?->content === $this->explanation) {
            $message->update(['content' => $explanation]);
        }
    }

    /**
     * What Jev is asked about the error.
     *
     * @return array<string, array{type: string, instructions: string, criteria: array<int|string, string>}>
     */
    public static function questions(): array
    {
        return [
            'cause' => Jev::choiceQuestion(
                "A coding agent's run against an AI provider failed with this error. What caused it?",
                AgentFailure::criteria(),
            ),
        ];
    }

    /**
     * The end of the error, where the cause usually is.
     */
    protected static function tail(string $error): string
    {
        $error = trim($error);

        return mb_strlen($error) > 2000 ? '…'.mb_substr($error, -2000) : $error;
    }
}
