<?php

namespace App\Models;

use App\Enums\DriveSpaceKind;
use App\Sandbox\Drive\DriveSpace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A file or folder in Drive (DRIVE-001), in one place (a person's My Drive, the organization's or a group's), under a
 * parent folder or at the top. A trashed folder hides everything in it (DRIVE-002).
 *
 * @property int $id
 * @property int $organization_id
 * @property DriveSpaceKind $space
 * @property int|null $user_id
 * @property int|null $group_id
 * @property int|null $parent_id
 * @property string $name
 * @property bool $is_folder
 * @property int $size
 * @property string|null $mime_type
 * @property string|null $sha256
 * @property string|null $blob Where its contents are on Drive's disk
 * @property int $revision The Drive revision of its latest change, which sandboxes follow (DRIVE-003)
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $trashed_at
 * @property int|null $trashed_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['organization_id', 'space', 'user_id', 'group_id', 'parent_id', 'name', 'is_folder', 'size', 'mime_type', 'sha256', 'blob', 'revision', 'created_by', 'updated_by', 'trashed_at', 'trashed_by'])]
class DriveItem extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'space' => DriveSpaceKind::class,
            'is_folder' => 'boolean',
            'size' => 'integer',
            'revision' => 'integer',
            'trashed_at' => 'datetime',
        ];
    }

    /**
     * Items in the place, trashed or not.
     *
     * @param  Builder<static>  $query
     */
    public function scopeIn(Builder $query, DriveSpace $space): void
    {
        $query->where('drive_items.organization_id', $space->organizationId)
            ->where('drive_items.space', $space->kind)
            ->where('drive_items.user_id', $space->userId)
            ->where('drive_items.group_id', $space->groupId);
    }

    /**
     * Items not in Trash themselves (one may still be inside a trashed folder).
     *
     * @param  Builder<static>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->whereNull('drive_items.trashed_at');
    }

    /**
     * The place it's in.
     */
    public function driveSpace(): DriveSpace
    {
        return new DriveSpace($this->space, $this->organization_id, $this->user_id, $this->group_id);
    }

    /**
     * @return BelongsTo<DriveItem, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(DriveItem::class, 'parent_id');
    }

    /**
     * @return HasMany<DriveItem, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(DriveItem::class, 'parent_id');
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Who last changed it.
     *
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Who put it in Trash.
     *
     * @return BelongsTo<User, $this>
     */
    public function trasher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'trashed_by');
    }
}
