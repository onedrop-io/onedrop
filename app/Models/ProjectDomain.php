<?php

namespace App\Models;

use App\Enums\DomainStatus;
use Database\Factories\ProjectDomainFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A domain the project's owner has, pointed at its published app (DOM-001..004).
 *
 * @property int $id
 * @property int $project_id
 * @property string $hostname
 * @property bool $primary
 * @property DomainStatus $status
 * @property string|null $via caddy, cloudflare-saas, fly or cloudflare-worker (see App\Sandbox\Domains\Connector)
 * @property string|null $external_id
 * @property list<array{type: string, name: string, value: string}>|null $records
 * @property string|null $error
 * @property Carbon|null $checking_since
 * @property Carbon|null $checked_at
 * @property Carbon|null $verified_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['hostname', 'primary', 'status', 'via', 'external_id', 'records', 'error', 'checking_since', 'checked_at', 'verified_at'])]
class ProjectDomain extends Model
{
    /** @use HasFactory<ProjectDomainFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'primary' => 'boolean',
            'status' => DomainStatus::class,
            'records' => 'array',
            'checking_since' => 'datetime',
            'checked_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function isActive(): bool
    {
        return $this->status === DomainStatus::Active;
    }

    public function url(): string
    {
        return "https://{$this->hostname}";
    }
}
