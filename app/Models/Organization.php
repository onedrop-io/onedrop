<?php

namespace App\Models;

use App\Enums\OrganizationRole;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The boundary between companies (ORG-001): its projects, groups, invites and skills are only ever seen by its
 * members. A self-hosted install has one; the hosted install (`app.multi_tenant`) has many.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $logo_path Its logo on the local disk (ORG-005), or null for its initial
 * @property string|null $logo_hash Changes with the logo, so browsers can cache it
 * @property string|null $ai_credits_key Its OpenRouter key for runs on AI credits (CREDIT-001), limited to its balance
 * @property string|null $ai_credits_key_hash That key's id at OpenRouter
 * @property float $ai_credits_charged What that key had spent (USD) when its usage was last charged to the credits
 * @property array<string, array<string, mixed>>|null $hosting_accounts Its own hosting accounts (HOST-003): provider => settings
 * @property int|null $max_task_copies Most task copies one of its projects runs at once (TASK-003), or null for no limit of its own
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read OrganizationMember $pivot Set on organizations loaded through a user's organizations
 */
#[Fillable(['name', 'slug', 'logo_path', 'logo_hash', 'max_task_copies'])]
#[Hidden(['ai_credits_key', 'ai_credits_key_hash', 'hosting_accounts'])]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ai_credits_key' => 'encrypted',
            'ai_credits_charged' => 'float',
            'hosting_accounts' => 'encrypted:array',
            'max_task_copies' => 'integer',
        ];
    }

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
     * Replace its logo with an uploaded image (ORG-005).
     */
    public function storeLogo(UploadedFile $file): void
    {
        $this->removeLogo();

        $path = $file->storeAs('organization-logos', $this->id.'-'.Str::random(8).'.'.($file->extension() === 'svg' ? 'svg' : $file->guessExtension()), 'local');

        $this->update(['logo_path' => $path, 'logo_hash' => substr((string) hash_file('sha256', $file->getRealPath()), 0, 12)]);
    }

    /**
     * Go back to its initial.
     */
    public function removeLogo(): void
    {
        if ($this->logo_path !== null) {
            Storage::disk('local')->delete($this->logo_path);
            $this->update(['logo_path' => null, 'logo_hash' => null]);
        }
    }

    /**
     * Its logo's address (changing with the file, so browsers can cache it), or null for its initial.
     */
    public function logoUrl(): ?string
    {
        return $this->logo_hash !== null ? route('organizations.logo', [$this, 'v' => $this->logo_hash], false) : null;
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
     * Take someone out of the organization and its groups (ORG-004). Their projects stay, out of their reach.
     */
    public function removeMember(User $user): void
    {
        $this->groups()->each(fn (Group $group) => $group->members()->detach($user));
        $this->members()->detach($user);
        $user->forgetOrganizationRoles();

        if ($user->current_organization_id === $this->id) {
            $user->forceFill(['current_organization_id' => null])->saveQuietly();
        }
    }

    /**
     * How many owners it has. It always keeps at least one.
     */
    public function ownerCount(): int
    {
        return $this->members()->wherePivot('role', OrganizationRole::Owner->value)->count();
    }

    /**
     * Whether the user owns it, or on a self-hosted install is a platform admin (who stands in for its owner).
     */
    public function isOwnedBy(User $user): bool
    {
        return $user->organizationRole($this) === OrganizationRole::Owner
            || ($user->is_admin && ! static::multiTenant() && $user->belongsToOrganization($this));
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
