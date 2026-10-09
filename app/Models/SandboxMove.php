<?php

namespace App\Models;

use App\Enums\MoveSource;
use App\Enums\OldSandboxStatus;
use App\Enums\SandboxMovePhase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A sandbox being moved to a new one with its files (SBX-005): to another provider, a newer image, or a computer.
 * Each queue job advances it a step (SandboxMover), so no job runs long; it remembers the old sandbox, which is kept
 * while it may hold newer files (SBX-013).
 *
 * @property int $id
 * @property int $project_id
 * @property int $sandbox_id
 * @property string $reason
 * @property bool $keep_files
 * @property SandboxMovePhase $phase
 * @property string|null $from_provider
 * @property string|null $from_external_id
 * @property bool|null $from_answered
 * @property string|null $to_provider
 * @property string|null $to_external_id
 * @property MoveSource|null $source
 * @property int|null $snapshot_id
 * @property array<string, mixed>|null $work
 * @property array<string, mixed>|null $options
 * @property OldSandboxStatus|null $old_status
 * @property int|null $recovered_snapshot_id
 * @property string|null $error
 * @property list<string>|null $messages
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['project_id', 'sandbox_id', 'reason', 'keep_files', 'phase', 'from_provider', 'from_external_id', 'from_answered', 'to_provider', 'to_external_id', 'source', 'snapshot_id', 'work', 'options', 'old_status', 'recovered_snapshot_id', 'error', 'messages', 'finished_at'])]
class SandboxMove extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'keep_files' => 'boolean',
            'phase' => SandboxMovePhase::class,
            'from_answered' => 'boolean',
            'source' => MoveSource::class,
            'work' => 'array',
            'options' => 'array',
            'old_status' => OldSandboxStatus::class,
            'messages' => 'array',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Sandbox, $this>
     */
    public function sandbox(): BelongsTo
    {
        return $this->belongsTo(Sandbox::class);
    }

    /**
     * @return BelongsTo<ProjectSnapshot, $this>
     */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(ProjectSnapshot::class);
    }

    /**
     * @return BelongsTo<ProjectSnapshot, $this>
     */
    public function recoveredSnapshot(): BelongsTo
    {
        return $this->belongsTo(ProjectSnapshot::class, 'recovered_snapshot_id');
    }

    /**
     * Moves not done or failed yet.
     *
     * @param  Builder<SandboxMove>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('phase', SandboxMovePhase::active());
    }

    /**
     * Note what the move did, for the admin page and `sandbox:update`.
     */
    public function note(string $message): void
    {
        $this->messages = [...($this->messages ?? []), $message];
        $this->save();
    }
}
