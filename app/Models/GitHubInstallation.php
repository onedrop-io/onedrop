<?php

namespace App\Models;

use Database\Factories\GitHubInstallationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An installation of the platform's GitHub App (on the user's account or an organization) that GitHub confirmed
 * the user can reach. Its repositories can be connected to the user's projects (Tools → Git).
 *
 * @property int $id
 * @property int $user_id
 * @property int $installation_id
 * @property string $account_login
 * @property string $account_type
 * @property string|null $account_avatar_url
 * @property string|null $repository_selection
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table('github_installations')]
#[Fillable(['installation_id', 'account_login', 'account_type', 'account_avatar_url', 'repository_selection'])]
class GitHubInstallation extends Model
{
    /** @use HasFactory<GitHubInstallationFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'installation_id' => 'integer',
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
