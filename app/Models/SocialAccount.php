<?php

namespace App\Models;

use App\Enums\SocialProvider;
use Database\Factories\SocialAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An identity provider account (Google, GitHub, ...) a user logs in with.
 *
 * @property int $id
 * @property int $user_id
 * @property SocialProvider $provider
 * @property string $provider_id
 * @property string|null $email
 * @property string|null $avatar
 * @property string|null $token GitHub's, kept only when it can download packages (GIT-016)
 * @property string|null $scopes What the token was granted, comma-separated
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['provider', 'provider_id', 'email', 'avatar', 'token', 'scopes'])]
#[Hidden(['token'])]
class SocialAccount extends Model
{
    /** @use HasFactory<SocialAccountFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => SocialProvider::class,
            'token' => 'encrypted',
        ];
    }

    /**
     * Whether this is a GitHub connection whose token can download the person's private packages (GIT-016).
     */
    public function canReadPackages(): bool
    {
        return $this->provider === SocialProvider::GitHub
            && $this->token !== null
            && in_array(SocialProvider::GITHUB_PACKAGES_SCOPE, explode(',', (string) $this->scopes), true);
    }

    /**
     * The user this account logs in as.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
