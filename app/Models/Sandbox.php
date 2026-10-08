<?php

namespace App\Models;

use App\Concerns\BroadcastsProjectChanges;
use App\Enums\SandboxStatus;
use App\Jobs\CreateSandbox;
use App\Jobs\SyncOrganizationSecrets;
use App\Jobs\UpdateSandbox;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxProvider;
use Database\Factories\SandboxFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $project_id
 * @property int|null $task_id
 * @property string $provider
 * @property string|null $external_id
 * @property SandboxStatus $status
 * @property string|null $preview_url
 * @property string|null $shell_url
 * @property string|null $ssh_address
 * @property int $files_version
 * @property string|null $error
 * @property string|null $events_token_hash
 * @property Carbon|null $last_active_at
 * @property Carbon|null $suspended_at
 * @property Carbon|null $stopped_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['provider', 'external_id', 'status', 'preview_url', 'shell_url', 'ssh_address', 'error', 'events_token_hash'])]
#[Hidden(['events_token_hash'])]
class Sandbox extends Model
{
    use BroadcastsProjectChanges;

    /** @use HasFactory<SandboxFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SandboxStatus::class,
            'files_version' => 'integer',
            'last_active_at' => 'datetime',
            'suspended_at' => 'datetime',
            'stopped_at' => 'datetime',
        ];
    }

    /**
     * A task's copy of the project's sandbox belongs to the project too.
     */
    protected static function booted(): void
    {
        static::creating(function (self $sandbox) {
            if ($sandbox->task_id !== null && $sandbox->getAttribute('project_id') === null) {
                $sandbox->project_id = Task::query()->whereKey($sandbox->task_id)->value('project_id');
            }
        });
    }

    /**
     * The task this sandbox is a copy for (TASK-003), or null for the project's main sandbox.
     *
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * The project running in this sandbox.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Note that someone or something used the sandbox just now, so it isn't suspended for sitting idle (SBX-007).
     */
    public function markActive(): void
    {
        // The gateway asks on every request a page makes: every 15 seconds is plenty.
        if ($this->last_active_at?->gt(now()->subSeconds(15))) {
            return;
        }

        $this->forceFill(['last_active_at' => now()])->saveQuietly();
        $this->updateWhenIdle();
    }

    /**
     * Have the project's main sandbox brought up to date once nobody uses it (SBX-002). One job waits per project;
     * a sandbox nobody uses never gets one, so it's never woken for an update.
     */
    public function updateWhenIdle(): void
    {
        if ($this->task_id === null) {
            UpdateSandbox::dispatch($this->project)->delay(now()->addSeconds(UpdateSandbox::IDLE_SECONDS));
        }
    }

    /**
     * Wake the sandbox if it was suspended for sitting idle, and note that it's in use (SBX-007). A Docker container
     * can also be stopped outside the app (Docker Desktop, a restart), so it's checked too, at most every 30 seconds.
     * Returns whether it had been asleep, so a preview showing it can reload.
     */
    public function wake(SandboxProvider $provider): bool
    {
        $check = $this->suspended_at !== null
            || ($this->provider === 'docker' && Cache::add("sandbox-awake-check:{$this->id}", true, 30));

        if (! $check || $this->external_id === null || $this->status !== SandboxStatus::Running) {
            $this->markActive();

            return false;
        }

        try {
            // A container that had to start again may be on other ports than the saved addresses.
            $started = $provider->wake($this->external_id);
            $addresses = $started ? CreateSandbox::addresses($provider, $this->external_id) : [];
        } catch (SandboxException) {
            // The workspace shows the sandbox as it is; the next visit tries again.
            return false;
        }

        // Something else (a command the platform ran in it) may have woken a suspended one first.
        $wasAsleep = $started || $this->suspended_at !== null;
        $this->forceFill([...$addresses, 'suspended_at' => null, 'stopped_at' => null, 'last_active_at' => now()])->save();
        $this->updateWhenIdle();

        if ($wasAsleep) {
            // The organization's secrets may have changed while it slept (SECRET-003).
            SyncOrganizationSecrets::dispatch($this->project->organization, $this);
        }

        return $wasAsleep;
    }

    /**
     * Issue a fresh secret for the agent's event forwarder, replacing any old one.
     */
    public function issueEventsToken(): string
    {
        $token = Str::random(48);
        $this->update(['events_token_hash' => hash('sha256', $token)]);

        return $token;
    }

    /**
     * Whether the given token is this sandbox's current events secret.
     */
    public function acceptsEventsToken(?string $token): bool
    {
        return $token !== null && $this->events_token_hash !== null
            && hash_equals($this->events_token_hash, hash('sha256', $token));
    }
}
