<?php

namespace App\Models;

use Database\Factories\InvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A single-use link that brings someone into the app builder.
 *
 * @property int $id
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
#[Fillable(['email', 'token', 'token_hash', 'invited_by', 'accepted_by', 'accepted_at', 'expires_at', 'revoked_at'])]
#[Hidden(['token', 'token_hash'])]
class Invitation extends Model
{
    /** @use HasFactory<InvitationFactory> */
    use HasFactory;

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
     * Create an invite from the given user, optionally for one email address.
     */
    public static function issue(User $inviter, ?string $email = null): self
    {
        $token = Str::random(40);

        return self::create([
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
     * Mark the invite used by a newly registered user. Returns false if someone got there first.
     */
    public function acceptFor(User $user): bool
    {
        $claimed = self::whereKey($this->id)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->update(['accepted_by' => $user->id, 'accepted_at' => now()]);

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
     * @return BelongsTo<User, $this>
     */
    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }
}
