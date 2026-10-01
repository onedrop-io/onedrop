<?php

namespace App\Models;

use App\Concerns\BelongsToOrganization;
use App\Enums\SkillSource;
use Database\Factories\SkillFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * One of a user's agent skills (SKILL-001): a SKILL.md and its other files, in the open Agent Skills format.
 * It's used in the projects it's turned on in, and put in their sandboxes before each run (SandboxSkills).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $user_id
 * @property string $name
 * @property string $description
 * @property string $content The whole SKILL.md, frontmatter included.
 * @property list<array{path: string, data: string}>|null $files The other files, base64-encoded.
 * @property bool $shared Everyone in its organization can see it and turn it on.
 * @property SkillSource $source
 * @property string|null $source_url
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['organization_id', 'name', 'description', 'content', 'files', 'shared', 'source', 'source_url'])]
class Skill extends Model
{
    /** @use HasFactory<SkillFactory> */
    use BelongsToOrganization, HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'files' => 'array',
            'shared' => 'boolean',
            'source' => SkillSource::class,
        ];
    }

    /**
     * The user who made it.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * A new skill goes in its maker's current organization, unless the code creating it says otherwise.
     */
    protected function defaultOrganization(): ?Organization
    {
        return $this->user?->currentOrganization();
    }

    /**
     * The projects it's turned on in.
     *
     * @return BelongsToMany<Project, $this>
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class)->withTimestamps();
    }

    /**
     * Skills the user can see in the organization: their own and shared ones (ORG-007).
     *
     * @param  Builder<Skill>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user, Organization $organization): void
    {
        $query->inOrganization($organization)
            ->where(fn (Builder $query) => $query->where('user_id', $user->id)->orWhere('shared', true));
    }

    /**
     * Whether the user can see it.
     */
    public function isVisibleTo(User $user): bool
    {
        return ($this->user_id === $user->id || $this->shared) && $user->belongsToOrganization($this->organization_id);
    }
}
