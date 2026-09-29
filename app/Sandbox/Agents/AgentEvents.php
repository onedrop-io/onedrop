<?php

namespace App\Sandbox\Agents;

use App\Enums\AgentHarness;
use App\Enums\AgentProvider;
use App\Enums\MessageRole;
use App\Jobs\BackupProject;
use App\Jobs\CheckPreviewErrors;
use App\Jobs\UpdateProjectIcon;
use App\Models\Project;
use App\Models\Task;
use App\Sandbox\ProjectIcons;
use Illuminate\Support\Str;

/**
 * Turns a coding agent's JSON events (plus the forwarder's zap.* events) into chat messages.
 * Unknown events are ignored so agent upgrades degrade gracefully.
 */
abstract class AgentEvents
{
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
     * The run is over; explain failures (unless the agent already did), check the preview for errors after a
     * successful main-chat turn (or move a task's card to Review), stop showing "working", back up the checkpoint
     * the forwarder committed, and pick up the app's icon.
     */
    protected function finish(Conversation $conversation, int $code, string $stderr, bool $reported = false): void
    {
        $project = $conversation->ownerProject();

        if ($code !== 0 && ! $reported) {
            $this->say($conversation, MessageRole::Assistant, $this->explainExit($stderr));
        }

        // Before the next queued message starts, so the check knows which turn it's for.
        if ($code === 0 && $conversation instanceof Project) {
            CheckPreviewErrors::afterTurn($project);
        }

        app(AgentQueue::class)->finished($conversation, succeeded: $code === 0);

        BackupProject::dispatch($project);

        if ($project->icon_path === null) {
            ProjectIcons::markDrawing($project);
        }

        UpdateProjectIcon::dispatch($project);
    }

    /**
     * Turn the tail of the agent's stderr into something the user can act on.
     */
    protected function explainExit(string $stderr): string
    {
        return match (true) {
            Str::contains($stderr, ['401', 'Unauthorized', 'invalid x-api-key', 'invalid_api_key', 'No auth credentials'], ignoreCase: true) => 'Your AI provider rejected the key. Reconnect it in Settings → AI.',
            Str::contains($stderr, ['402', 'insufficient', 'credits'], ignoreCase: true) => 'Your AI account is out of credits. Top it up and try again.',
            Str::contains($stderr, ['ModelNotFound', 'model not found', 'not a valid model'], ignoreCase: true) => "The AI model isn't available on your account. Ask an admin to change SANDBOX_MODEL_* in .env.",
            default => 'The agent stopped unexpectedly.'.(($line = $this->errorLine($stderr)) !== null ? ' Error: '.Str::limit($line, 200) : ''),
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
