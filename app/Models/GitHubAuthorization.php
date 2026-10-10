<?php

namespace App\Models;

use Database\Factories\GitHubAuthorizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A user's sign-in through the platform's GitHub App: their GitHub user token (encrypted), used to list and
 * check the repositories they and the app can both reach (Source Control). Refreshed when it expires.
 *
 * @property int $id
 * @property int $user_id
 * @property string $github_login
 * @property string $access_token
 * @property string|null $refresh_token
 * @property Carbon|null $expires_at
 * @property Carbon|null $refresh_expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table('github_authorizations')]
#[Fillable(['github_login', 'access_token', 'refresh_token', 'expires_at', 'refresh_expires_at'])]
#[Hidden(['access_token', 'refresh_token'])]
class GitHubAuthorization extends Model
{
    /** @use HasFactory<GitHubAuthorizationFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
            'refresh_expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
