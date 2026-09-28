<?php

namespace App\Models;

use App\Enums\AgentProvider;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $avatar
 * @property Carbon|null $email_verified_at
 * @property string|null $password
 * @property bool $is_admin
 * @property list<string>|null $favorite_models
 * @property list<string>|null $recent_models
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'favorite_models' => 'array',
            'recent_models' => 'array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * Put a model ("provider:model") at the front of the user's recently chosen models.
     */
    public function rememberModel(AgentProvider $provider, string $model): void
    {
        $key = "{$provider->value}:{$model}";
        $recent = collect($this->recent_models ?? [])->reject(fn ($item) => $item === $key)->prepend($key);

        $this->forceFill(['recent_models' => $recent->take(10)->values()->all()])->save();
    }

    /**
     * The groups the user belongs to.
     *
     * @return BelongsToMany<Group, $this>
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * The projects the user owns.
     *
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * The identity provider accounts (Google, GitHub, ...) the user logs in with.
     *
     * @return HasMany<SocialAccount, $this>
     */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    /**
     * The SSH public keys the user signs in to their projects' sandboxes with.
     *
     * @return HasMany<SshKey, $this>
     */
    public function sshKeys(): HasMany
    {
        return $this->hasMany(SshKey::class);
    }

    /**
     * Whether the user has set a password (people who signed up with a
     * provider start without one).
     */
    public function hasPassword(): bool
    {
        return $this->password !== null;
    }

    /**
     * How many ways the user can log in: their password, connected
     * providers, and passkeys.
     */
    public function loginMethodCount(): int
    {
        return (int) $this->hasPassword()
            + $this->socialAccounts()->count()
            + $this->passkeys()->count();
    }

    /**
     * The user's AI provider connections.
     *
     * @return HasMany<AgentConnection, $this>
     */
    public function agentConnections(): HasMany
    {
        return $this->hasMany(AgentConnection::class);
    }
}
