<?php

namespace App\Models;

use App\Enums\OrganizationRole;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The boundary between companies (ORG-001): its projects, groups, invites and skills are only ever seen by its
 * members. A self-hosted install has one; the hosted install (`app.multi_tenant`) has many.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read OrganizationMember $pivot Set on organizations loaded through a user's organizations
 */
#[Fillable(['name', 'slug'])]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    /**
     * Whether this install serves many organizations, rather than being one company's own.
     */
    public static function multiTenant(): bool
    {
        return (bool) config('app.multi_tenant');
    }

    /**
     * Make an organization with a slug nobody else has.
     */
    public static function createNamed(string $name): self
    {
        $base = Str::slug($name) ?: 'organization';
        $slug = $base;

        for ($n = 2; static::query()->where('slug', $slug)->exists(); $n++) {
            $slug = "{$base}-{$n}";
        }

        return static::create(['name' => $name, 'slug' => $slug]);
    }

    /**
     * A self-hosted install's one organization, made (named after the install) when the first account needs it.
     */
    public static function install(): self
    {
        return static::query()->oldest('id')->first()
            ?? static::createNamed((string) config('app.name', 'OneDrop'));
    }

    /**
     * Use the slug in addresses (`/o/{slug}`).
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * The users that belong to the organization.
     *
     * @return BelongsToMany<User, $this, OrganizationMember, 'pivot'>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(OrganizationMember::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * @return HasMany<Group, $this>
     */
    public function groups(): HasMany
    {
        return $this->hasMany(Group::class);
    }

    /**
     * @return HasMany<Invitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    /**
     * @return HasMany<Skill, $this>
     */
    public function skills(): HasMany
    {
        return $this->hasMany(Skill::class);
    }

    /**
     * Add someone to the organization. Someone already in it keeps their role.
     */
    public function addMember(User $user, OrganizationRole $role = OrganizationRole::Member): void
    {
        if ($user->belongsToOrganization($this)) {
            return;
        }

        $this->members()->attach($user, ['role' => $role->value]);
        $user->forgetOrganizationRoles();
    }

    /**
     * Whether the user runs this organization: an owner or admin, or on a self-hosted install, a platform admin
     * (who is the company's admin there, and can be made one from the Users page).
     */
    public function isManagedBy(User $user): bool
    {
        return (bool) $user->organizationRole($this)?->manages()
            || ($user->is_admin && ! static::multiTenant() && $user->belongsToOrganization($this));
    }
}
