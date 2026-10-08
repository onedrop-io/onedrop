<?php

namespace App\Models;

use Database\Factories\OrganizationSecretFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * An environment variable an organization shares with its projects' sandboxes (SECRET-003), like a Codespaces
 * organization secret: every project's, or only chosen ones'. Its value is never shown again once saved.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property string $value
 * @property bool $all_projects
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 */
#[Fillable(['name', 'value', 'all_projects'])]
#[Hidden(['value'])]
class OrganizationSecret extends Model
{
    /** @use HasFactory<OrganizationSecretFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'encrypted',
            'all_projects' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * The projects it reaches when it isn't for all of them.
     *
     * @return BelongsToMany<Project, $this>
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class);
    }

    /**
     * The secrets that reach a project: its organization's for all projects, and those it was chosen for. A computer
     * is one person's own (CMP-001), so it gets none.
     *
     * @param  Builder<self>  $query
     */
    public function scopeReaching(Builder $query, Project $project): void
    {
        if ($project->isComputer()) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where('organization_id', $project->organization_id)
            ->where(fn (Builder $query) => $query->where('all_projects', true)
                ->orWhereHas('projects', fn (Builder $query) => $query->whereKey($project->id)));
    }
}
