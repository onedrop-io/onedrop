<?php

namespace App\Models;

use App\Enums\AgentHarness;
use App\Enums\AgentProvider;
use App\Enums\BuildMode;
use App\Enums\OrganizationRole;
use App\Enums\ProjectKind;
use App\Enums\ProjectSort;
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
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $avatar
 * @property Carbon|null $email_verified_at
 * @property string|null $password
 * @property bool $is_admin
 * @property int|null $current_organization_id The organization they used last: where signing in lands (ORG-002)
 * @property list<string>|null $favorite_models
 * @property list<string>|null $recent_models
 * @property array{harness: string, provider: string, model: string, variant: string|null}|null $agent_preference
 * @property ProjectSort $project_sort How the sidebar orders their projects (PRJ-010)
 * @property Carbon|null $last_login_at When they last signed in (USR-002)
 * @property string|null $last_login_method How: password, passkey, or a sign-in provider (google, github, ...)
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read GroupMember|OrganizationMember $pivot Set on users loaded through a group's or organization's members
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * The sidebar sorts projects by last updated until they choose otherwise (PRJ-010), and Simple or Advanced
     * is asked on the new-project page (PRJ-013).
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['project_sort' => 'updated', 'build_mode' => null];

    /** @var array<int, OrganizationRole|null> the user's role in each organization looked up so far */
    protected array $organizationRoles = [];

    /**
     * On a self-hosted install, everyone is in its one organization from the start (ORG-001). On the hosted
     * install, signing up joins the inviter's organization or makes their own, the first time one is needed.
     */
    protected static function booted(): void
    {
        static::created(function (User $user): void {
            if (! Organization::multiTenant()) {
                $user->currentOrganization();
            }
        });
    }

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
            'project_sort' => ProjectSort::class,
            'build_mode' => BuildMode::class,
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
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

            // ...and the owner of a self-hosted install's organization.
            if (! Organization::multiTenant()) {
                $this->currentOrganization()->members()->updateExistingPivot($this->id, ['role' => OrganizationRole::Owner->value]);
                $this->forgetOrganizationRoles();
            }
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
     * The organizations the user belongs to (ORG-001).
     *
     * @return BelongsToMany<Organization, $this, OrganizationMember, 'pivot'>
     */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class)
            ->using(OrganizationMember::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * The user's role in the organization, or null when they aren't in it. Remembered for the rest of the request.
     */
    public function organizationRole(Organization $organization): ?OrganizationRole
    {
        if (! array_key_exists($organization->id, $this->organizationRoles)) {
            $role = $this->organizations()->whereKey($organization->id)->value('organization_user.role');
            $this->organizationRoles[$organization->id] = $role ? OrganizationRole::from($role) : null;
        }

        return $this->organizationRoles[$organization->id];
    }

    /**
     * Whether the user is a member of the organization.
     */
    public function belongsToOrganization(Organization|int|null $organization): bool
    {
        if ($organization === null) {
            return false;
        }

        $organization = $organization instanceof Organization ? $organization : Organization::find($organization);

        return $organization !== null && $this->organizationRole($organization) !== null;
    }

    /**
     * Forget the roles looked up so far, after joining or leaving an organization.
     */
    public function forgetOrganizationRoles(): void
    {
        $this->organizationRoles = [];
    }

    /**
     * The organization the user is working in when the address doesn't say: the one they used last, else their
     * first. Someone in none joins the install's one (self-hosted) or gets their own (hosted, ORG-003).
     */
    public function currentOrganization(): Organization
    {
        $organization = $this->current_organization_id
            ? $this->organizations()->whereKey($this->current_organization_id)->first()
            : null;

        $organization ??= $this->organizations()->oldest('organization_user.id')->first();

        if ($organization === null) {
            $organization = Organization::multiTenant()
                ? Organization::createNamed(__(":name's organization", ['name' => str($this->name)->before(' ')->toString()]))
                : Organization::install();

            $organization->addMember($this, Organization::multiTenant() ? OrganizationRole::Owner : OrganizationRole::Member);
        }

        $this->switchOrganization($organization);

        return $organization;
    }

    /**
     * Remember the organization the user is working in, so signing in lands there next time (ORG-002).
     */
    public function switchOrganization(Organization $organization): void
    {
        if ($this->current_organization_id !== $organization->id) {
            $this->forceFill(['current_organization_id' => $organization->id])->saveQuietly();
        }
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
        return $this->hasMany(Project::class)->where('projects.kind', ProjectKind::App);
    }

    /**
     * The user's computers, one in each organization they opened theirs in (CMP-001).
     *
     * @return HasMany<Project, $this>
     */
    public function computers(): HasMany
    {
        return $this->hasMany(Project::class)->where('projects.kind', ProjectKind::Computer);
    }

    /**
     * The user's computer in the organization, if they've opened it (CMP-001).
     *
     * @phpstan-impure
     */
    public function computerIn(Organization $organization): ?Project
    {
        return $this->computers()->where('organization_id', $organization->id)->first();
    }

    /**
     * The agent skills the user made (SKILL-001).
     *
     * @return HasMany<Skill, $this>
     */
    public function skills(): HasMany
    {
        return $this->hasMany(Skill::class);
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
