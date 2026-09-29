<?php

namespace App\Models;

use App\Enums\GroupRole;
use Database\Factories\GroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'description'])]
class Group extends Model
{
    /** @use HasFactory<GroupFactory> */
    use HasFactory;

    /**
     * The users that belong to the group.
     *
     * @return BelongsToMany<User, $this, GroupMember, 'pivot'>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(GroupMember::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Get the role the given user holds in this group, if any.
     */
    public function roleOf(User $user): ?GroupRole
    {
        $role = $this->members()->whereKey($user->id)->value('group_user.role');

        return $role ? GroupRole::from($role) : null;
    }

    /**
     * Determine whether the given user owns this group.
     */
    public function isOwnedBy(User $user): bool
    {
        return $this->roleOf($user) === GroupRole::Owner;
    }

    /**
     * Count the owners of this group.
     */
    public function ownerCount(): int
    {
        return $this->members()->wherePivot('role', GroupRole::Owner->value)->count();
    }
}
