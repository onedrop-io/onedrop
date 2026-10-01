<?php

namespace App\Sandbox;

use App\Models\Group;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * "Sign in with OneDrop": the app builder as an OAuth 2.0 provider for the apps it builds
 * (authorization code, optional PKCE, and a user-info endpoint). Each project's app is one client.
 * Codes and access tokens live in the cache, stored under their hashes.
 */
class OneDropSignIn
{
    public const CODE_SECONDS = 300;

    public const TOKEN_SECONDS = 3600;

    public const DEFAULT_CALLBACK_PATH = '/auth/onedrop/callback';

    public function __construct(protected AppAddresses $addresses) {}

    /**
     * Turn it on for a project with fresh credentials; returns the settings the app needs.
     *
     * @return array{client_id: string, client_secret: string, authorize_url: string, token_url: string, userinfo_url: string}
     */
    public function enable(Project $project, string $callbackPath): array
    {
        $secret = Str::random(48);

        $project->update([
            'onedrop_enabled' => true,
            'onedrop_client_id' => $project->onedrop_client_id ?? 'od_'.Str::lower(Str::random(24)),
            'onedrop_client_secret' => $secret,
            'onedrop_callback_path' => $callbackPath,
        ]);

        // The browser goes to the public address; the app's server calls the one sandboxes can reach.
        $internal = rtrim((string) config('sandbox.callback_url'), '/');

        return [
            'client_id' => $project->onedrop_client_id,
            'client_secret' => $secret,
            'authorize_url' => route('onedrop.authorize'),
            'token_url' => $internal.route('onedrop.token', absolute: false),
            'userinfo_url' => $internal.route('onedrop.userinfo', absolute: false),
        ];
    }

    public function disable(Project $project): void
    {
        $project->update(['onedrop_enabled' => false]);
    }

    public function client(?string $clientId): ?Project
    {
        return $clientId ? Project::where('onedrop_client_id', $clientId)->where('onedrop_enabled', true)->first() : null;
    }

    /**
     * Whether the client's credentials are right (the app authenticating itself).
     */
    public function authenticate(?string $clientId, ?string $secret): ?Project
    {
        $project = $this->client($clientId);

        return $project && is_string($secret) && Hash::check($secret, $project->onedrop_client_secret) ? $project : null;
    }

    /**
     * Callback addresses the app can be sent back to: its preview and published addresses.
     *
     * @return list<string>
     */
    public function redirectUris(Project $project): array
    {
        return array_column($this->addresses->everywhere($project, $project->onedrop_callback_path ?? self::DEFAULT_CALLBACK_PATH), 'url');
    }

    /**
     * Whether this person may sign in to the project's app: people in its organization (never anyone else on the
     * install, ORG-007), and of those its owner and everyone or the chosen groups.
     */
    public function allows(Project $project, User $user): bool
    {
        $groups = $project->onedrop_group_ids;

        return $user->belongsToOrganization($project->organization_id) && (
            $groups === null
            || $user->id === $project->user_id
            || $user->groups()->inOrganization($project->organization)->whereIn('groups.id', $groups)->exists()
        );
    }

    /**
     * @param  array{redirect_uri: string, code_challenge: ?string}  $request
     */
    public function issueCode(Project $project, User $user, array $request): string
    {
        $code = Str::random(48);

        Cache::put($this->key('code', $code), [
            'project' => $project->id,
            'user' => $user->id,
            'redirect_uri' => $request['redirect_uri'],
            'code_challenge' => $request['code_challenge'],
        ], self::CODE_SECONDS);

        return $code;
    }

    /**
     * Trade a code (once) for an access token, or null when anything doesn't match.
     */
    public function exchange(Project $project, string $code, ?string $redirectUri, ?string $verifier): ?string
    {
        $grant = Cache::pull($this->key('code', $code));

        if (! is_array($grant) || $grant['project'] !== $project->id || $grant['redirect_uri'] !== $redirectUri) {
            return null;
        }

        if ($grant['code_challenge'] !== null) {
            $expected = rtrim(strtr(base64_encode(hash('sha256', (string) $verifier, true)), '+/', '-_'), '=');

            if (! is_string($verifier) || ! hash_equals($grant['code_challenge'], $expected)) {
                return null;
            }
        }

        $token = Str::random(48);
        Cache::put($this->key('token', $token), ['project' => $project->id, 'user' => $grant['user']], self::TOKEN_SECONDS);

        return $token;
    }

    /**
     * The app and person an access token belongs to, while the app still has OneDrop sign-in and still lets them in.
     *
     * @return array{project: Project, user: User}|null
     */
    public function grantForToken(string $token): ?array
    {
        $grant = Cache::get($this->key('token', $token));
        $project = is_array($grant) ? Project::query()->whereKey($grant['project'])->first() : null;
        $user = $project?->onedrop_enabled ? User::query()->whereKey($grant['user'])->first() : null;

        return $user && $this->allows($project, $user) ? ['project' => $project, 'user' => $user] : null;
    }

    /**
     * Standard OpenID Connect claims for the app, with the person's groups in its organization.
     *
     * @return array{sub: string, name: string, email: string, email_verified: bool, groups: list<string>}
     */
    public function claims(User $user, Project $project): array
    {
        return [
            'sub' => (string) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified' => $user->email_verified_at !== null,
            'groups' => array_values($user->groups()->inOrganization($project->organization)->orderBy('name')->get()->map(fn (Group $group): string => $group->name)->all()),
        ];
    }

    protected function key(string $kind, string $value): string
    {
        return "onedrop:{$kind}:".hash('sha256', $value);
    }
}
