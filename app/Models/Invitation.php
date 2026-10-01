<?php

namespace App\Models;

use App\Concerns\BelongsToOrganization;
use Database\Factories\InvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A single-use link that brings someone into an organization (INV-001, ORG-004).
 *
 * @property int $id
 * @property int $organization_id
 * @property string|null $email
 * @property string $token
 * @property string $token_hash
 * @property int $invited_by
 * @property int|null $accepted_by
 * @property Carbon|null $accepted_at
 * @property Carbon $expires_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['organization_id', 'email', 'token', 'token_hash', 'invited_by', 'accepted_by', 'accepted_at', 'expires_at', 'revoked_at'])]
#[Hidden(['token', 'token_hash'])]
class Invitation extends Model
{
    /** @use HasFactory<InvitationFactory> */
    use BelongsToOrganization, HasFactory;

    public const LIFETIME_DAYS = 7;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'accepted_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Create an invite from the given user to an organization (their current one by default), optionally for one
     * email address.
     */
    public static function issue(User $inviter, ?string $email = null, ?Organization $organization = null): self
    {
        $token = Str::random(40);

        return self::create([
            'organization_id' => ($organization ?? $inviter->currentOrganization())->id,
            'email' => $email ? Str::lower($email) : null,
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'invited_by' => $inviter->id,
            'expires_at' => now()->addDays(self::LIFETIME_DAYS),
        ]);
    }

    /**
     * Look an invite up by the token in its link.
     */
    public static function findByToken(string $token): ?self
    {
        return self::firstWhere('token_hash', hash('sha256', $token));
    }

    /**
     * waiting | accepted | expired | revoked
     */
    public function status(): string
    {
        return match (true) {
            $this->accepted_at !== null => 'accepted',
            $this->revoked_at !== null => 'revoked',
            $this->expires_at->isPast() => 'expired',
            default => 'waiting',
        };
    }

    /**
     * Whether the link can still be used to sign up.
     */
    public function isUsable(): bool
    {
        return $this->status() === 'waiting';
    }

    /**
     * The link to share.
     */
    public function url(): string
    {
        return route('invitations.accept', $this->token);
    }

    /**
     * Mark the invite used by a newly registered user and add them to its organization. Returns false if someone
     * got there first.
     */
    public function acceptFor(User $user): bool
    {
        $claimed = self::whereKey($this->id)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->update(['accepted_by' => $user->id, 'accepted_at' => now()]);

        if ($claimed) {
            $this->organization->addMember($user);
            $user->switchOrganization($this->organization);
        }

        if ($claimed && $this->email !== null && Str::lower($user->email) === $this->email && $user->email_verified_at === null) {
            // Receiving the link at that address proves they own it.
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return $claimed === 1;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * An invite is for its inviter's current organization, unless the code creating it says otherwise.
     */
    protected function defaultOrganization(): ?Organization
    {
        return $this->inviter?->currentOrganization();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }
}
