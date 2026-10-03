<?php

namespace App\Models;

use App\Enums\HostedServiceKind;
use Database\Factories\HostedServiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Something made for a hosted project at a provider (HOST-001, HOST-002): its Fly app, a volume, a Neon or Upstash
 * database, an R2 bucket, or a Cloudflare site. It stays in the account it was made in.
 *
 * @property int $id
 * @property int $project_id
 * @property HostedServiceKind $kind
 * @property string $provider
 * @property string $owner organization or platform
 * @property string $name
 * @property string|null $external_id
 * @property string|null $region
 * @property array<string, mixed>|null $details
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['kind', 'provider', 'owner', 'name', 'external_id', 'region', 'details'])]
#[Hidden(['details'])]
class HostedService extends Model
{
    /** @use HasFactory<HostedServiceFactory> */
    use HasFactory;

    public const OWNER_ORGANIZATION = 'organization';

    public const OWNER_PLATFORM = 'platform';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => HostedServiceKind::class,
            'details' => 'encrypted:array',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
