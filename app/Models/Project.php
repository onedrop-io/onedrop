<?php

namespace App\Models;

use App\Concerns\BelongsToOrganization;
use App\Concerns\BroadcastsProjectChanges;
use App\Enums\AgentHarness;
use App\Enums\AgentProvider;
use App\Enums\GitSyncStatus;
use App\Enums\ProjectKind;
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
use Laravel\Sanctum\PersonalAccessToken;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $user_id
 * @property string $name
 * @property string $prompt
 * @property ProjectStatus $status
 * @property ProjectKind $kind An app, or its owner's computer (CMP-001)
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
 * @property string|null $published_url the address to use: its primary custom domain when that's active (DOM-002)
 * @property string|null $published_default_url the publish target's own address
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
 * @property array{count: int, commits: list<array{sha: string, message: string}>}|null $hosting_changes What the sandbox has that the hosted app doesn't yet (HOST-004)
 * @property bool $auto_deploy Update the hosted app by itself after a turn that went well (HOST-006)
 * @property bool $commit_turns Whether the agent commits each turn to the branch, or leaves its changes for the user to commit (SCM-003)
 * @property string|null $hosting_sqlite_import The hosted SQLite file a Move to Postgres copies into the app's new Postgres, until it has (HOST-009)
 * @property string|null $hosting_size The hosted app's machine size; null is the install's default (HOST-010)
 * @property int|null $device_id The computer (a desktop app sign-in) it runs on, or null for the install's provider (DESK-010)
 * @property list<array{host: string, port: int}>|null $network_hosts Hosts on its people's network it may reach through their desktop app (DESK-009)
 * @property bool $apps_listed Whether the organization's Apps page lists it once it's published (APPS-003)
 * @property Carbon|null $apps_featured_at When an organization admin featured it on the Apps page (APPS-003)
 */
#[Fillable(['organization_id', 'kind', 'name', 'prompt', 'status', 'agent_session_id', 'sign_in_retry_message_id', 'agent_harness', 'agent_provider', 'agent_model', 'agent_variant', 'agent_auto', 'publish_status', 'publish_visibility', 'publish_target', 'published_url', 'published_at', 'published_by', 'publish_error', 'publish_login_url', 'publish_waiting_for', 'onedrop_enabled', 'onedrop_client_id', 'onedrop_client_secret', 'onedrop_callback_path', 'onedrop_group_ids', 'pinned_at', 'read_at', 'archived_at', 'sidebar_position', 'backup_commit', 'backed_up_at', 'icon_path', 'icon_mime', 'icon_hash', 'git_remote_url', 'git_remote_username', 'git_remote_token', 'git_sync_status', 'git_sync_error', 'git_synced_at', 'github_installation_id', 'autofix', 'track_requirements', 'turn_outcome', 'hosting_changes', 'auto_deploy', 'commit_turns', 'hosting_sqlite_import', 'hosting_size', 'published_default_url', 'device_id', 'network_hosts', 'apps_listed', 'apps_featured_at'])]
#[Hidden(['onedrop_client_secret', 'git_remote_token'])]
class Project extends Model implements Conversation
{
    /** @use HasFactory<ProjectFactory> */
    use BelongsToOrganization, HasFactory;

    use BroadcastsProjectChanges;

    /**
     * Errors the preview shows after a turn go back to the agent unless turned off (ERR-001), the agent
     * keeps the project's requirements unless turned off (REQ-001), and commits each turn until a repository is
     * connected (SCM-003).
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['kind' => 'app', 'autofix' => true, 'track_requirements' => true, 'auto_deploy' => false, 'commit_turns' => true, 'apps_listed' => true];

    /**
     * Connecting a repository (or importing one) stops the agent committing each turn (SCM-003): the user's
     * history is theirs to write. They can turn it back on.
     */
    protected static function booted(): void
    {
        static::saving(function (Project $project) {
            if ($project->isDirty('git_remote_url') && $project->getOriginal('git_remote_url') === null && $project->git_remote_url !== null && ! $project->isDirty('commit_turns')) {
                $project->commit_turns = false;
            }
        });
    }

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
            'kind' => ProjectKind::class,
            'autofix' => 'boolean',
            'hosting_changes' => 'array',
            'auto_deploy' => 'boolean',
            'commit_turns' => 'boolean',
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
            'network_hosts' => 'array',
            'apps_listed' => 'boolean',
            'apps_featured_at' => 'datetime',
        ];
    }

    /**
     * Only apps, leaving out people's computers (CMP-001).
     *
     * @param  Builder<static>  $query
     */
    public function scopeApps(Builder $query): void
    {
        $query->where('projects.kind', ProjectKind::App);
    }

    /**
     * Apps that are live where anyone in the organization can open them, for its Apps page (APPS-001): on Your domain
     * or Hosting, or public on Tailscale (a private one needs the tailnet). Hidden ones are included; see apps_listed.
     *
     * @param  Builder<static>  $query
     */
    public function scopeOpenToOrganization(Builder $query): void
    {
        $query->apps()
            ->where('projects.publish_status', PublishStatus::Live)
            ->whereNotNull('projects.published_url')
            ->where(fn (Builder $query) => $query
                ->where('projects.publish_target', '!=', PublishTarget::Tailscale)
                ->orWhere('projects.publish_visibility', PublishVisibility::Public));
    }

    /**
     * Whether it's on its organization's Apps page for everyone (APPS-001, APPS-003).
     */
    public function listedInApps(): bool
    {
        return $this->apps_listed && static::query()->whereKey($this->id)->openToOrganization()->exists();
    }

    /**
     * Whether this is its owner's computer (CMP-001) rather than an app.
     */
    public function isComputer(): bool
    {
        return $this->kind === ProjectKind::Computer;
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
     * The sandbox provider its sandboxes run on: the computer it was moved to (DESK-010), else the install's.
     */
    public function sandboxProvider(): string
    {
        return $this->device_id ? 'device' : (string) config('sandbox.provider');
    }

    /**
     * The computer it runs on (DESK-010): a desktop app sign-in.
     *
     * @return BelongsTo<PersonalAccessToken, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'device_id');
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
     * Most task copies of the app the project may run at once: the lower of the install's limit (Settings → Sandboxes)
     * and its organization's, or null when neither has one.
     */
    public function taskCopyLimit(): ?int
    {
        $limits = array_filter([config('sandbox.max_task_copies'), $this->organization->max_task_copies], fn (mixed $limit) => $limit !== null);

        return $limits === [] ? null : (int) min($limits);
    }

    /**
     * Whether the project already runs as many task copies of the app as it may.
     */
    public function taskCopyLimitReached(): bool
    {
        $limit = $this->taskCopyLimit();

        return $limit !== null && $this->sandboxes()->whereNotNull('task_id')->count() >= $limit;
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
     * Its sandboxes' moves to new ones (SBX-005), oldest first.
     *
     * @return HasMany<SandboxMove, $this>
     */
    public function sandboxMoves(): HasMany
    {
        return $this->hasMany(SandboxMove::class);
    }

    /**
     * Its deployments to hosting, oldest first (HOST-001).
     *
     * @return HasMany<Deployment, $this>
     */
    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    /**
     * What was made for it at hosting providers (HOST-001, HOST-002).
     *
     * @return HasMany<HostedService, $this>
     */
    public function hostedServices(): HasMany
    {
        return $this->hasMany(HostedService::class);
    }

    /**
     * Domains its owner has, pointed at the published app (DOM-001).
     *
     * @return HasMany<ProjectDomain, $this>
     */
    public function domains(): HasMany
    {
        return $this->hasMany(ProjectDomain::class);
    }

    /**
     * The groups its owner put the app in, for the Apps page (APPS-002).
     *
     * @return BelongsToMany<Group, $this>
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class)->withTimestamps();
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
