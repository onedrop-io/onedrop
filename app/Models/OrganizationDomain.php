<?php

namespace App\Models;

use Database\Factories\OrganizationDomainFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * An email domain whose people can join an organization (ORG-008), once a TXT record proves the organization owns it.
 * Several organizations can be waiting on the same domain, but only one can verify it.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $domain
 * @property string $verification_token
 * @property Carbon|null $verified_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 */
#[Fillable(['domain'])]
class OrganizationDomain extends Model
{
    /** @use HasFactory<OrganizationDomainFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (OrganizationDomain $domain): void {
            $domain->verification_token ??= Str::random(32);
        });
    }

    /**
     * The domain of an email address, lowercased.
     */
    public static function ofEmail(string $email): string
    {
        return Str::lower(Str::afterLast($email, '@'));
    }

    /**
     * The TXT record's value that proves the organization owns the domain.
     */
    public function txtValue(): string
    {
        return 'onedrop-verification='.$this->verification_token;
    }

    /**
     * Whether another organization has already verified the domain.
     */
    public function takenElsewhere(): bool
    {
        return static::query()
            ->where('domain', $this->domain)
            ->where('organization_id', '!=', $this->organization_id)
            ->whereNotNull('verified_at')
            ->exists();
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
