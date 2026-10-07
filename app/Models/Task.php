<?php

namespace App\Models;

use App\Concerns\BroadcastsProjectChanges;
use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Enums\SandboxStatus;
use App\Enums\TaskStage;
use App\Enums\TaskSyncStatus;
use App\Enums\TurnOutcome;
use App\Jobs\DestroySandbox;
use App\Sandbox\Agents\Conversation;
use App\Sandbox\Agents\PlainActivity;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A piece of work in a project with its own agent and chat, shown as a card on the project's board
 * (TASK-001, TASK-002). It runs alongside the main chat and other tasks, in the same sandbox.
 *
 * @property int $id
 * @property int $project_id
 * @property string $title
 * @property string|null $description
 * @property TaskStage $stage
 * @property int $position
 * @property ProjectStatus $status
 * @property string|null $agent_session_id
 * @property int|null $sign_in_retry_message_id
 * @property string|null $events_token_hash
 * @property string|null $base_commit
 * @property TaskSyncStatus|null $sync_status
 * @property string|null $sync_error
 * @property Carbon|null $applied_at
 * @property Carbon|null $read_at
 * @property TurnOutcome|null $turn_outcome
 * @property int|null $pull_request_number The GitHub pull request it was checked out from (GIT-014).
 * @property string|null $pull_request_branch The pull request's own branch, which the task's copy works on and pushes to.
 * @property string|null $pull_request_base
 * @property bool $pull_request_fork Its branch is in a fork, so it can't be pushed to.
 * @property bool $pull_request_push Push the agent's work to the pull request after each turn.
 * @property bool $pull_request_autofix Send failed checks to the agent after each push (GIT-015).
 * @property int $pull_request_fix_attempts Fixes sent in a row without the checks passing.
 * @property string|null $pull_request_head_sha The newest commit pushed or checked out, whose checks are watched.
 * @property string|null $pull_request_checks Those checks as last seen: pending, success or failure.
 * @property string|null $last_reply_at From withLastReply().
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['title', 'description', 'stage', 'position', 'status', 'agent_session_id', 'sign_in_retry_message_id', 'events_token_hash', 'base_commit', 'sync_status', 'sync_error', 'applied_at', 'read_at', 'turn_outcome', 'pull_request_number', 'pull_request_branch', 'pull_request_base', 'pull_request_fork', 'pull_request_push', 'pull_request_autofix', 'pull_request_fix_attempts', 'pull_request_head_sha', 'pull_request_checks'])]
#[Hidden(['events_token_hash'])]
class Task extends Model implements Conversation
{
    use BroadcastsProjectChanges;

    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['stage' => 'todo', 'status' => 'idle', 'position' => 0, 'pull_request_fork' => false, 'pull_request_push' => true, 'pull_request_autofix' => false, 'pull_request_fix_attempts' => 0];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stage' => TaskStage::class,
            'status' => ProjectStatus::class,
            'position' => 'integer',
            'sync_status' => TaskSyncStatus::class,
            'applied_at' => 'datetime',
            'read_at' => 'datetime',
            'turn_outcome' => TurnOutcome::class,
            'pull_request_number' => 'integer',
            'pull_request_fork' => 'boolean',
            'pull_request_push' => 'boolean',
            'pull_request_autofix' => 'boolean',
            'pull_request_fix_attempts' => 'integer',
        ];
    }

    /**
     * Remove the chat one message at a time, so attachments' files go too (the database cascade alone would leave them),
     * and the task's copy of the app.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $task) {
            $task->hasMany(Message::class)->get()->each->delete();
            $task->destroyCopy();
        });
    }

    /**
     * A short title from the first thing the user asked.
     */
    public static function titleFromPrompt(string $prompt): string
    {
        $title = Str::of($prompt)->squish()->words(8, '')->rtrim('.,!?;:')->toString();

        return Str::limit(Str::ucfirst($title), 80, '') ?: 'New task';
    }

    /**
     * The project the task belongs to.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function ownerProject(): Project
    {
        return $this->project;
    }

    /**
     * The task's own copy of the project's sandbox (TASK-003), if it has one.
     *
     * @return HasOne<Sandbox, $this>
     */
    public function sandbox(): HasOne
    {
        return $this->hasOne(Sandbox::class);
    }

    /**
     * The task's own copy when tasks get one, otherwise the project's main sandbox (TASK-001).
     */
    public function agentSandbox(): ?Sandbox
    {
        return self::getsCopies() ? $this->sandbox()->first() : $this->project->sandbox()->first();
    }

    /**
     * Whether the task was checked out from a pull request (GIT-014).
     */
    public function isPullRequest(): bool
    {
        return $this->pull_request_number !== null;
    }

    /**
     * Whether tasks get their own copy of the app (config sandbox.task_copies).
     */
    public static function getsCopies(): bool
    {
        return (bool) config('sandbox.task_copies');
    }

    /**
     * Whether the task's next run needs a copy of the app made first: it gets one, and has none that works
     * (none yet, none since it was applied, or the last one failed to start).
     */
    public function needsCopy(): bool
    {
        return self::getsCopies() && ! $this->sandbox()->where('status', '!=', SandboxStatus::Failed)->exists();
    }

    /**
     * Delete the task's copy of the app, if it has one: its record now, the sandbox itself in the background.
     */
    public function destroyCopy(): void
    {
        $sandbox = $this->sandbox()->first();

        if ($sandbox?->external_id) {
            DestroySandbox::dispatch($sandbox->external_id, $sandbox->provider);
        }

        $sandbox?->delete();
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->where('queued', false)->orderBy('id');
    }

    /**
     * Adds `last_reply_at`: when the task's agent last replied, for its unread dot (PRJ-008).
     *
     * @param  Builder<Task>  $query
     */
    public function scopeWithLastReply(Builder $query): void
    {
        $query->withMax(['messages as last_reply_at' => fn (Builder $query) => $query->where('role', MessageRole::Assistant)], 'created_at');
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function queuedMessages(): HasMany
    {
        return $this->hasMany(Message::class)->where('queued', true)->orderBy('id');
    }

    public function runKey(): string
    {
        return "task-{$this->id}";
    }

    public function issueEventsToken(): string
    {
        $token = Str::random(48);
        $this->update(['events_token_hash' => hash('sha256', $token)]);

        return $token;
    }

    /**
     * Whether the given token is the secret of this task's current run.
     */
    public function acceptsEventsToken(?string $token): bool
    {
        return $token !== null && $this->events_token_hash !== null
            && hash_equals($this->events_token_hash, hash('sha256', $token));
    }

    /**
     * Whether the task's agent is running.
     */
    public function isWorking(): bool
    {
        return $this->status === ProjectStatus::Working;
    }

    /**
     * The agent's latest step in its current run, in plain words (PlainActivity), or null before its first one.
     */
    public function currentActivity(): ?string
    {
        $lastPromptId = $this->messages()->reorder()->where('role', MessageRole::User)->max('id') ?? 0;

        return PlainActivity::describe($this->messages()->reorder()
            ->where('role', MessageRole::Activity)
            ->where('id', '>', $lastPromptId)
            ->latest('id')
            ->value('content'));
    }
}
