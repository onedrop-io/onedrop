<?php

namespace App\Concerns;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A model that lives in one organization (ORG-001). Code that creates one should say which; when it doesn't, it
 * goes in its person's current organization (see defaultOrganization()).
 *
 * @property int $organization_id
 * @property-read Organization $organization
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::creating(function (self $model): void {
            $model->organization_id ??= $model->defaultOrganization()?->id;
        });
    }

    /**
     * The organization to put it in when the code creating it didn't say.
     */
    protected function defaultOrganization(): ?Organization
    {
        return null;
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Only the ones in the given organization.
     *
     * @param  Builder<static>  $query
     */
    public function scopeInOrganization(Builder $query, Organization $organization): void
    {
        $query->where($this->qualifyColumn('organization_id'), $organization->id);
    }
}
