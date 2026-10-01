<?php

namespace App\Sandbox\Agents;

use App\Enums\AgentFailure;
use App\Enums\AgentHarness;
use App\Enums\AgentProvider;
use App\Enums\MessageRole;
use App\Jobs\BackupProject;
use App\Jobs\CheckPreviewErrors;
use App\Jobs\CheckRequirementsKept;
use App\Jobs\CheckTurnOutcome;
use App\Jobs\ExplainAgentFailure;
use App\Jobs\UpdateProjectIcon;
use App\Models\Project;
use App\Models\Task;
use App\Sandbox\AbuseCheck;
use App\Sandbox\ProjectIcons;
use Illuminate\Support\Str;

/**
 * Turns a coding agent's JSON events (plus the forwarder's onedrop.* events) into chat messages.
 * Unknown events are ignored so agent upgrades degrade gracefully.
 */
abstract class AgentEvents
{
    /**
     * The error behind the generic failure message about to be said, for Jev to look at (AGT-010).
     */
    private ?string $unmatchedError = null;

    /**
     * Apply one event to the conversation (the project's main chat or a task) whose run sent it.
     *
     * @param  array<string, mixed>  $event
     */
    abstract public function apply(Conversation $conversation, array $event): void;

    /**
     * Keep the agent's session id so the next run continues the conversation.
     */
    protected function rememberSession(Conversation $conversation, mixed $sessionId): void
    {
        if (is_string($sessionId) && $sessionId !== '' && $conversation->getAttribute('agent_session_id') !== $sessionId) {
            $conversation->update(['agent_session_id' => $sessionId]);
        }
    }

    /**
     * Store a chat line, skipping empty ones and repeated "Thinking" lines.
     */
    protected function say(Conversation $conversation, MessageRole $role, string $content): void
    {
        if ($content === '') {
            return;
        }

        $last = $conversation->messages()->reorder()->latest('id')->first();

        if ($role === MessageRole::Activity && $last?->role === $role && $last->content === $content) {
            return;
        }

        $conversation->messages()->create(['role' => $role, 'content' => $content]);
    }

    /**
     * Record the tokens a model used and their estimated cost, charged to the project's owner (USAGE-001).
     *
     * @param  array{input: int, output: int, cache_read: int, cache_write: int}  $tokens
     */
    protected function recordUsage(Conversation $conversation, AgentHarness $harness, ?AgentProvider $provider, string $model, mixed $sessionId, array $tokens, float $cost): void
    {
        if (array_sum($tokens) === 0 && $cost <= 0) {
            return;
        }

        $project = $conversation->ownerProject();

        $project->user->agentUsages()->create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'task_id' => $conversation instanceof Task ? $conversation->id : null,
            'harness' => $harness,
            'provider' => $provider,
            'model' => $model,
            'session_id' => is_string($sessionId) && $sessionId !== '' ? $sessionId : $conversation->getAttribute('agent_session_id'),
            'input_tokens' => $tokens['input'],
            'output_tokens' => $tokens['output'],
            'cache_read_tokens' => $tokens['cache_read'],
            'cache_write_tokens' => $tokens['cache_write'],
            'cost' => max(0, $cost),
        ]);
    }

    /**
     * The run is over; explain failures (unless the agent already did), check the preview for errors and that the
     * requirements were kept after a successful main-chat turn (or move a task's card to Review), check whether the
     * agent is waiting for the user, stop showing "working", back up the checkpoint the forwarder committed, and pick
     * up the app's icon.
     */
    protected function finish(Conversation $conversation, int $code, string $stderr, bool $reported = false): void
    {
        $project = $conversation->ownerProject();

        if ($code !== 0 && ! $reported) {
            $this->sayFailure($conversation, $this->explainExit($stderr));
        }

        // Before the next queued message starts, so the check knows which turn it's for.
        if ($code === 0 && $conversation instanceof Project) {
            CheckPreviewErrors::afterTurn($project);
            CheckRequirementsKept::afterTurn($project);
            AbuseCheck::afterTurn($project);
        }

        // Before it goes idle, so the sidebar holds its notification until the outcome is known (PRJ-011).
        if ($conversation instanceof Project || $conversation instanceof Task) {
            CheckTurnOutcome::afterTurn($conversation);
        }

        app(AgentQueue::class)->finished($conversation, succeeded: $code === 0);

        BackupProject::dispatch($project);

        if ($project->icon_path === null) {
            ProjectIcons::markDrawing($project);
        }

        UpdateProjectIcon::dispatch($project);
    }

    /**
     * Say why the run failed. When the explanation is the generic one (the error matched no known cause, see
     * unmatched()), Jev gets a look at the error and may replace it with a known cause's message (AGT-010).
     */
    protected function sayFailure(Conversation $conversation, string $explanation): void
    {
        $error = $this->unmatchedError;
        $this->unmatchedError = null;

        $this->say($conversation, MessageRole::Assistant, $explanation);

        if ($error === null || ! ($conversation instanceof Project || $conversation instanceof Task)) {
            return;
        }

        $message = $conversation->messages()->reorder()->latest('id')->first();

        if ($message !== null && $message->role === MessageRole::Assistant && $message->content === $explanation) {
            ExplainAgentFailure::afterFailure($conversation, $message, $error, static::class);
        }
    }

    /**
     * The generic $explanation for an $error that matched no known cause, noted so sayFailure() asks Jev about it.
     */
    protected function unmatched(string $error, string $explanation): string
    {
        $this->unmatchedError = $error;

        return $explanation;
    }

    /**
     * What to tell the user when Jev finds a failure's cause (AGT-010); null keeps the generic message. Harnesses
     * with their own wording for a cause override this.
     */
    public function failureMessage(Conversation $conversation, AgentFailure $failure): ?string
    {
        return $failure === AgentFailure::Other ? null : self::genericFailureMessage($failure);
    }

    /**
     * Each cause's message for any harness.
     */
    protected static function genericFailureMessage(AgentFailure $failure): string
    {
        return match ($failure) {
            AgentFailure::BadKey => 'Your AI provider rejected the key. Reconnect it in Settings → AI.',
            AgentFailure::OutOfCredits => 'Your AI account is out of credits. Top it up and try again.',
            AgentFailure::ModelUnavailable => "The AI model isn't available on your account. Ask an admin to change SANDBOX_MODEL_* in .env.",
            AgentFailure::RateLimited => 'Your AI provider is busy or limiting requests right now. Try again in a minute.',
            AgentFailure::ContextTooLong => 'The conversation got too long for the AI model. Start a new task for your next change.',
            AgentFailure::Network => "The agent couldn't reach your AI provider. Try again in a minute.",
            AgentFailure::Other => 'The agent stopped unexpectedly.',
        };
    }

    /**
     * Turn the tail of the agent's stderr into something the user can act on.
     */
    protected function explainExit(string $stderr): string
    {
        return match (true) {
            Str::contains($stderr, ['401', 'Unauthorized', 'invalid x-api-key', 'invalid_api_key', 'No auth credentials'], ignoreCase: true) => self::genericFailureMessage(AgentFailure::BadKey),
            Str::contains($stderr, ['402', 'insufficient', 'credits'], ignoreCase: true) => self::genericFailureMessage(AgentFailure::OutOfCredits),
            Str::contains($stderr, ['ModelNotFound', 'model not found', 'not a valid model'], ignoreCase: true) => self::genericFailureMessage(AgentFailure::ModelUnavailable),
            default => $this->unmatched($stderr, 'The agent stopped unexpectedly.'.(($line = $this->errorLine($stderr)) !== null ? ' Error: '.Str::limit($line, 200) : '')),
        };
    }

    /**
     * The most useful line of a crash: the first that names an error, skipping
     * runtime banners like "Bun v1.3.14 (Linux arm64)".
     */
    protected function errorLine(string $stderr): ?string
    {
        $lines = collect(explode("\n", $stderr))
            ->map(fn (string $line) => trim($line))
            ->reject(fn (string $line) => $line === '' || preg_match('/^(Bun|Node\.js) v\d/', $line));

        return $lines->first(fn (string $line) => Str::contains($line, ['error', 'Error', 'EACCES', 'ENOENT', 'denied', 'panic']))
            ?? $lines->last();
    }
}
