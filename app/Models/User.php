<?php

namespace App\Models;

use App\Enums\AgentHarness;
use App\Enums\AgentProvider;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
 * @property array{harness: string, provider: string, model: string, variant: string|null}|null $agent_preference
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read GroupMember $pivot Set on users loaded through a group's members
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
            'agent_preference' => 'array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /** Session flag: this browser opened the installer's setup link (see setupAllowed()). */
    public const SETUP_SESSION_KEY = 'setup_link_opened';

    /**
     * Whether an account can be created now: always, except for the first account on a server install, which
     * needs the installer's setup link to have been opened in this session.
     */
    public static function setupAllowed(): bool
    {
        return blank(config('auth.setup_token'))
            || session(self::SETUP_SESSION_KEY) === true
            || static::query()->exists();
    }

    /**
     * Whether new accounts must verify their email: AUTH_VERIFY_EMAIL when set, otherwise everywhere but local installs.
     */
    public static function emailVerificationRequired(): bool
    {
        return (bool) (config('auth.verify_email') ?? ! app()->isLocal());
    }

    /**
     * Remember that this session opened the setup link, when $token is the install's setup token.
     */
    public static function openSetupLink(mixed $token): void
    {
        $expected = config('auth.setup_token');

        if (is_string($token) && is_string($expected) && $expected !== '' && hash_equals($expected, $token)) {
            session()->put(self::SETUP_SESSION_KEY, true);
        }
    }

    /**
     * Make the first person to sign up on a new install its admin, so nobody needs a command to get in.
     */
    public function becomeAdminIfFirst(): void
    {
        if (! static::whereKeyNot($this->getKey())->exists()) {
            $this->forceFill(['is_admin' => true])->save();
        }
    }

    /**
     * Remember the agent, model and reasoning level the user chose, for their next new project.
     *
     * @param  array{agent_harness: AgentHarness, agent_provider: AgentProvider, agent_model: string, agent_variant: string|null}  $agent
     */
    public function preferAgent(array $agent): void
    {
        $this->forceFill(['agent_preference' => [
            'harness' => $agent['agent_harness']->value,
            'provider' => $agent['agent_provider']->value,
            'model' => $agent['agent_model'],
            'variant' => $agent['agent_variant'],
        ]])->save();
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
     * @return BelongsToMany<Group, $this, GroupMember, 'pivot'>
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class)
            ->using(GroupMember::class)
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
     * Installations of the platform's GitHub App this user can reach (Tools → Git).
     *
     * @return HasMany<GitHubInstallation, $this>
     */
    public function githubInstallations(): HasMany
    {
        return $this->hasMany(GitHubInstallation::class);
    }

    /**
     * The user's sign-in through the platform's GitHub App, if they've connected GitHub.
     *
     * @return HasOne<GitHubAuthorization, $this>
     */
    public function githubAuthorization(): HasOne
    {
        return $this->hasOne(GitHubAuthorization::class);
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
     * Tokens and estimated cost of the agent runs the user's AI connections paid for (USAGE-001).
     *
     * @return HasMany<AgentUsage, $this>
     */
    public function agentUsages(): HasMany
    {
        return $this->hasMany(AgentUsage::class);
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
