<?php

namespace App\Models;

use Database\Factories\ProjectSnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A project's whole state at one moment, in layers on the snapshot disk (SBX-009). A layer that didn't change since
 * the previous snapshot points at the same stored file.
 *
 * @property int $id
 * @property int $project_id
 * @property int|null $task_id
 * @property string $reason
 * @property string $status
 * @property string|null $external_id
 * @property array<string, array{path: string, fingerprint: string, compression: string, size: int}> $layers
 * @property int $size
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['task_id', 'reason', 'status', 'external_id', 'layers', 'size'])]
class ProjectSnapshot extends Model
{
    /** @use HasFactory<ProjectSnapshotFactory> */
    use HasFactory;

    /** Being packed and uploaded by its sandbox, in the background. */
    public const PENDING = 'pending';

    public const READY = 'ready';

    public const FAILED = 'failed';

    /** Files of a sandbox that had stopped answering, saved when it came back (SBX-013); never restored by itself. */
    public const RECOVERED = 'recovered';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'layers' => 'array',
            'size' => 'integer',
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
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }
}
