<?php

namespace App\Models;

use App\Enums\SandboxStatus;
use Database\Factories\SandboxFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
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
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['provider', 'external_id', 'status', 'preview_url', 'shell_url', 'ssh_address', 'error', 'events_token_hash'])]
#[Hidden(['events_token_hash'])]
class Sandbox extends Model
{
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
