<?php

namespace App\Models;

use App\Concerns\BelongsToOrganization;
use App\Concerns\BroadcastsProjectChanges;
use App\Enums\AgentHarness;
use App\Enums\AgentProvider;
use App\Enums\GitSyncStatus;
use App\Enums\ProjectSort;
use App\Enums\ProjectStatus;
use App\Enums\PublishStatus;
use App\Enums\PublishTarget;
use App\Enums\PublishVisibility;
use App\Enums\TaskStage;
use App\Enums\TurnOutcome;
use App\Sandbox\Agents\Conversation;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $user_id
 * @property string $name
 * @property string $prompt
 * @property ProjectStatus $status
 * @property string|null $agent_session_id
 * @property int|null $sign_in_retry_message_id
 * @property AgentHarness|null $agent_harness
 * @property AgentProvider|null $agent_provider
 * @property string|null $agent_model
 * @property string|null $agent_variant
 * @property bool $agent_auto
 * @property PublishStatus|null $publish_status
 * @property PublishVisibility|null $publish_visibility
 * @property PublishTarget|null $publish_target
 * @property string|null $published_url
 * @property Carbon|null $published_at
 * @property int|null $published_by
 * @property string|null $publish_error
 * @property bool $onedrop_enabled
 * @property string|null $onedrop_client_id
 * @property string|null $onedrop_client_secret
 * @property string|null $onedrop_callback_path
 * @property list<int>|null $onedrop_group_ids
 * @property string|null $publish_login_url
 * @property string|null $publish_waiting_for
 * @property Carbon|null $pinned_at
 * @property Carbon|null $read_at
 * @property TurnOutcome|null $turn_outcome
 * @property Carbon|null $archived_at
 * @property int|null $sidebar_position Where the owner dragged it in the sidebar (PRJ-010); null until they do
 * @property string|null $backup_commit
 * @property Carbon|null $backed_up_at
 * @property Carbon|null $created_at
 * @property string|null $icon_path
 * @property string|null $icon_mime
 * @property string|null $icon_hash
 * @property string|null $git_remote_url
 * @property string|null $git_remote_username
 * @property string|null $git_remote_token
 * @property GitSyncStatus|null $git_sync_status
 * @property string|null $git_sync_error
 * @property Carbon|null $git_synced_at
 * @property int|null $github_installation_id
 * @property Carbon|null $updated_at
 * @property-read string|null $last_reply_at When the agent last replied (loaded with withMax, for the sidebar).
 * @property-read bool|null $task_working Whether any of its tasks' agents is running (loaded with withExists, for the sidebar).
 */
#[Fillable(['organization_id', 'name', 'prompt', 'status', 'agent_session_id', 'sign_in_retry_message_id', 'agent_harness', 'agent_provider', 'agent_model', 'agent_variant', 'agent_auto', 'publish_status', 'publish_visibility', 'publish_target', 'published_url', 'published_at', 'published_by', 'publish_error', 'publish_login_url', 'publish_waiting_for', 'onedrop_enabled', 'onedrop_client_id', 'onedrop_client_secret', 'onedrop_callback_path', 'onedrop_group_ids', 'pinned_at', 'read_at', 'archived_at', 'sidebar_position', 'backup_commit', 'backed_up_at', 'icon_path', 'icon_mime', 'icon_hash', 'git_remote_url', 'git_remote_username', 'git_remote_token', 'git_sync_status', 'git_sync_error', 'git_synced_at', 'github_installation_id', 'autofix', 'track_requirements', 'turn_outcome'])]
#[Hidden(['onedrop_client_secret', 'git_remote_token'])]
class Project extends Model implements Conversation
{
    /** @use HasFactory<ProjectFactory> */
    use BelongsToOrganization, HasFactory;

    use BroadcastsProjectChanges;

    /**
     * Errors the preview shows after a turn go back to the agent unless turned off (ERR-001), and the agent
     * keeps the project's requirements unless turned off (REQ-001).
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['autofix' => true, 'track_requirements' => true];

    /**
     * Order projects the way the sidebar lists them (PRJ-010). Ties go to the newest.
     *
     * @param  Builder<static>  $query
     */
    public function scopeSortedBy(Builder $query, ProjectSort $sort): void
    {
        match ($sort) {
            ProjectSort::Updated => $query->latest('updated_at'),
            ProjectSort::Created => $query->latest('created_at'),
            // Projects not placed yet (new ones) come first, the same way in SQLite and Postgres.
            ProjectSort::Manual => $query->orderByRaw('case when sidebar_position is null then 0 else 1 end')->orderBy('sidebar_position')->latest('created_at'),
        };

        $query->latest('id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'autofix' => 'boolean',
            'track_requirements' => 'boolean',
            'agent_auto' => 'boolean',
            'agent_harness' => AgentHarness::class,
            'agent_provider' => AgentProvider::class,
            'publish_status' => PublishStatus::class,
            'publish_visibility' => PublishVisibility::class,
            'publish_target' => PublishTarget::class,
            'published_at' => 'datetime',
            'onedrop_enabled' => 'boolean',
            'onedrop_client_secret' => 'hashed',
            'onedrop_group_ids' => 'array',
            'pinned_at' => 'datetime',
            'read_at' => 'datetime',
            'turn_outcome' => TurnOutcome::class,
            'archived_at' => 'datetime',
            'backed_up_at' => 'datetime',
            'git_remote_token' => 'encrypted',
            'git_sync_status' => GitSyncStatus::class,
            'git_synced_at' => 'datetime',
            'github_installation_id' => 'integer',
        ];
    }

    /**
     * Turn a free-form description into a short project name.
     */
    public static function nameFromPrompt(string $prompt): string
    {
        $name = Str::of($prompt)->squish()->words(6, '')->rtrim('.,!?;:')->title()->toString();

        return Str::limit($name, 60, '') ?: 'Untitled Project';
    }

    /**
     * The user who owns the project.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The organization the project belongs to (ORG-001).
     *
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * A new project goes in its owner's current organization, unless the code creating it says otherwise.
     */
    protected function defaultOrganization(): ?Organization
    {
        return $this->user?->currentOrganization();
    }

    /**
     * The main chat's messages, oldest first (not counting queued ones). Tasks have their own (TASK-001).
     *
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->whereNull('task_id')->where('queued', false)->orderBy('id');
    }

    /**
     * Messages waiting for the main chat's current agent run to finish, in the order they'll run.
     *
     * @return HasMany<Message, $this>
     */
    public function queuedMessages(): HasMany
    {
        return $this->hasMany(Message::class)->whereNull('task_id')->where('queued', true)->orderBy('id');
    }

    /**
     * Every message in the project: the main chat's and its tasks'.
     *
     * @return HasMany<Message, $this>
     */
    public function allMessages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * The agent skills turned on in the project, in the order they were turned on (SKILL-001).
     *
     * @return BelongsToMany<Skill, $this>
     */
    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class)->withTimestamps()->orderByPivot('id');
    }

    /**
     * The project's tasks, in board order (TASK-002).
     *
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class)->orderBy('position')->orderBy('id');
    }

    public function ownerProject(): Project
    {
        return $this;
    }

    public function runKey(): string
    {
        return 'main';
    }

    public function agentSandbox(): ?Sandbox
    {
        return $this->sandbox()->first();
    }

    /**
     * The main chat's runs report with the sandbox's own events secret.
     */
    public function issueEventsToken(): string
    {
        return $this->sandbox->issueEventsToken();
    }

    /**
     * Whether any agent is running in the project: the main chat's, or a task's (other than $except).
     */
    public function agentBusy(?Conversation $except = null): bool
    {
        if ($this->status === ProjectStatus::Working && ! $except instanceof Project) {
            return true;
        }

        return $this->tasks()->reorder()
            ->where('status', ProjectStatus::Working)
            ->when($except instanceof Task, fn ($query) => $query->whereKeyNot($except))
            ->exists();
    }

    /**
     * Whether an agent is running in the main sandbox: the main chat's, or a task's that shares it (other than
     * $except). Tasks with their own copy (TASK-003) don't count; they work elsewhere.
     */
    public function mainSandboxBusy(?Conversation $except = null): bool
    {
        if (! Task::getsCopies()) {
            return $this->agentBusy($except);
        }

        return $this->status === ProjectStatus::Working && ! $except instanceof Project;
    }

    /**
     * Whether an agent is running in the given sandbox (the project's main one when null): a task's copy is busy
     * while its task's agent works (TASK-003).
     */
    public function busyIn(?Sandbox $sandbox): bool
    {
        return $sandbox?->task_id !== null
            ? $this->tasks()->reorder()->whereKey($sandbox->task_id)->where('status', ProjectStatus::Working)->exists()
            : $this->mainSandboxBusy();
    }

    /**
     * Whether the project already runs as many task copies of the app as it may (config sandbox.max_task_copies).
     */
    public function taskCopyLimitReached(): bool
    {
        return $this->sandboxes()->whereNotNull('task_id')->count() >= config('sandbox.max_task_copies');
    }

    /**
     * Where a new card goes: the end of the stage's column.
     */
    public function nextTaskPosition(TaskStage $stage): int
    {
        return (int) $this->tasks()->reorder()->where('stage', $stage)->max('position') + 1;
    }

    /**
     * The sandbox the project's main chat runs in (not its tasks' copies).
     *
     * @return HasOne<Sandbox, $this>
     */
    public function sandbox(): HasOne
    {
        return $this->hasOne(Sandbox::class)->whereNull('task_id');
    }

    /**
     * Every sandbox the project has: its main one and its tasks' copies (TASK-003).
     *
     * @return HasMany<Sandbox, $this>
     */
    public function sandboxes(): HasMany
    {
        return $this->hasMany(Sandbox::class);
    }

    /**
     * The project's snapshots, oldest first (SBX-009).
     *
     * @return HasMany<ProjectSnapshot, $this>
     */
    public function snapshots(): HasMany
    {
        return $this->hasMany(ProjectSnapshot::class);
    }

    /**
     * The user who last published the project.
     *
     * @return BelongsTo<User, $this>
     */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /**
     * The project's public show-off page, while it's shared (SHARE-001).
     *
     * @return HasOne<ProjectShare, $this>
     */
    public function share(): HasOne
    {
        return $this->hasOne(ProjectShare::class);
    }

    /**
     * The hosted install's abuse check of its public app and share page (PUB-003).
     *
     * @return HasOne<AbuseReview, $this>
     */
    public function abuseReview(): HasOne
    {
        return $this->hasOne(AbuseReview::class);
    }

    /**
     * A DNS-safe hostname for the published app, unique per project.
     */
    public function publishHostname(): string
    {
        $slug = Str::of($this->name)->slug()->limit(40, '')->trim('-')->toString();

        return ($slug !== '' ? "{$slug}-" : 'project-').$this->id;
    }
}
