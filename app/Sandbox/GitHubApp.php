<?php

namespace App\Sandbox;

use App\Models\GitHubInstallation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The platform's GitHub App (config/services.php "github_app"): installing it lets people connect repositories
 * in Tools → Git without pasting tokens.
 *
 * Two kinds of token: the user's own (from signing in through the app, kept encrypted and refreshed), which only
 * sees repositories both the user and the app can reach, so listing and connecting are limited to what the user
 * may use; and short-lived installation tokens the platform makes with the app's private key, for pushing and
 * pulling (GitRemote) and for creating organization repositories.
 */
class GitHubApp
{
    protected const API = 'https://api.github.com';

    /** Installation tokens last an hour; reuse one for most of that. */
    protected const TOKEN_SECONDS = 3000;

    /** The environment variables the app needs, by config key. */
    protected const SETTINGS = [
        'id' => 'GITHUB_APP_ID',
        'slug' => 'GITHUB_APP_SLUG',
        'client_id' => 'GITHUB_APP_CLIENT_ID',
        'client_secret' => 'GITHUB_APP_CLIENT_SECRET',
        'private_key' => 'GITHUB_APP_PRIVATE_KEY',
    ];

    /**
     * Whether the admin has filled in every GitHub App setting.
     */
    public function configured(): bool
    {
        return $this->missingSettings() === [];
    }

    /**
     * Whether any GitHub App setting is filled in (so a half-finished setup can be pointed out).
     */
    public function started(): bool
    {
        return count($this->missingSettings()) < count(self::SETTINGS);
    }

    /**
     * @return list<string> environment variable names
     */
    public function missingSettings(): array
    {
        return array_values(array_filter(self::SETTINGS, fn (string $env, string $key) => blank(config("services.github_app.{$key}")), ARRAY_FILTER_USE_BOTH));
    }

    /**
     * What's wrong with the setup, for admins: missing settings, an app GitHub doesn't recognise, or missing permissions.
     * Creating repositories needing Administration is reported by canCreateRepositories(), not here.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        if (! $this->started()) {
            return [];
        }

        if (($missing = $this->missingSettings()) !== []) {
            return [__('Add :settings to .env to finish setting up the GitHub App.', ['settings' => implode(', ', $missing)])];
        }

        $app = $this->app();

        if ($app === null) {
            return [__('GitHub didn\'t accept the app\'s ID and private key. Check GITHUB_APP_ID and GITHUB_APP_PRIVATE_KEY.')];
        }

        $permissions = $app['permissions'] ?? [];
        $problems = [];

        if (($permissions['contents'] ?? null) !== 'write') {
            $problems[] = __('Give the GitHub App the Contents: Read and write repository permission, so it can push and pull.');
        }

        if (! in_array($permissions['metadata'] ?? null, ['read', 'write'], true)) {
            $problems[] = __('Give the GitHub App the Metadata: Read-only repository permission, so it can list repositories.');
        }

        // Installed on GitHub, yet nobody ever came back: GitHub isn't sending people to OneDrop after installing.
        if (($app['installations_count'] ?? 0) > 0 && ! GitHubInstallation::query()->exists()) {
            $problems[] = __('The app is installed on GitHub, but nobody has come back to OneDrop from installing it. Set its Callback URL and Setup URL to :url, turn on "Redirect on update" and "Request user authorization (OAuth) during installation".', ['url' => $this->callbackUrl()]);
        }

        return $problems;
    }

    /**
     * Whether the app may create repositories (in organizations; GitHub Apps can't create them on personal accounts).
     */
    public function canCreateRepositories(): bool
    {
        return ($this->app()['permissions']['administration'] ?? null) === 'write';
    }

    /**
     * Where the admin changes the app's permissions on GitHub.
     */
    public function settingsUrl(): string
    {
        $slug = config('services.github_app.slug');
        $owner = $this->app()['owner'] ?? null;

        return ($owner['type'] ?? null) === 'Organization'
            ? "https://github.com/organizations/{$owner['login']}/settings/apps/{$slug}/permissions"
            : "https://github.com/settings/apps/{$slug}/permissions";
    }

    /**
     * Where to send the user to install the app (or change which repositories it can reach).
     */
    public function installUrl(string $state): string
    {
        return 'https://github.com/apps/'.config('services.github_app.slug').'/installations/new?'.http_build_query(['state' => $state]);
    }

    /**
     * Where to send the user to sign in through the app (when it's installed but their token has lapsed).
     */
    public function authorizeUrl(string $state): string
    {
        // GitHub sends people to the app's Callback URL: /github/callback, or /login/github/callback for an app
        // shared with logging in, which passes Tools → Git returns on (SocialLoginController).
        return 'https://github.com/login/oauth/authorize?'.http_build_query([
            'client_id' => config('services.github_app.client_id'),
            'state' => $state,
        ]);
    }

    /**
     * Where GitHub must send people back to: the app's Callback URL and Setup URL.
     */
    public function callbackUrl(): string
    {
        return route('github-app.callback');
    }

    /**
     * Where the user changes an installation's repositories on GitHub.
     */
    public function manageUrl(GitHubInstallation $installation): string
    {
        return $installation->account_type === 'Organization'
            ? "https://github.com/organizations/{$installation->account_login}/settings/installations/{$installation->installation_id}"
            : "https://github.com/settings/installations/{$installation->installation_id}";
    }

    /**
     * GitHub's new-repository page, filled in, for accounts the app can't create repositories in.
     */
    public function newRepositoryUrl(string $owner, string $name, bool $private): string
    {
        return 'https://github.com/new?'.http_build_query(['owner' => $owner, 'name' => $name, 'visibility' => $private ? 'private' : 'public']);
    }

    /**
     * Finish signing the user in through the app (GitHub's OAuth code): keep their token, and return the
     * installations GitHub says they can reach.
     *
     * @return list<array{installation_id: int, account_login: string, account_type: string, account_avatar_url: ?string, repository_selection: ?string}>
     *
     * @throws GitException
     */
    public function authorize(User $user, string $code): array
    {
        $tokens = $this->exchange(['code' => $code]);

        if ($tokens === null) {
            throw new GitException(__('GitHub didn\'t confirm the sign-in. Try connecting again.'));
        }

        $login = (string) $this->check($this->api()->withToken($tokens['access_token'])->get(self::API.'/user'))->json('login');
        $user->githubAuthorization()->updateOrCreate([], ['github_login' => $login, ...$tokens]);

        $installations = $this->check(
            $this->api()->withToken($tokens['access_token'])->get(self::API.'/user/installations', ['per_page' => 100]),
        )->json('installations') ?? [];

        return array_values(array_map(fn (array $installation) => [
            'installation_id' => (int) $installation['id'],
            'account_login' => (string) $installation['account']['login'],
            'account_type' => (string) $installation['account']['type'],
            'account_avatar_url' => is_string($avatarUrl = $installation['account']['avatar_url'] ?? null) ? $avatarUrl : null,
            'repository_selection' => is_string($selection = $installation['repository_selection'] ?? null) ? $selection : null,
        ], $installations));
    }

    /**
     * The user's GitHub token, refreshed if it has expired; null when they haven't signed in or it can't be refreshed.
     */
    public function userToken(User $user): ?string
    {
        $authorization = $user->githubAuthorization;

        if ($authorization === null) {
            return null;
        }

        if ($authorization->expires_at === null || $authorization->expires_at->isAfter(now()->addMinute())) {
            return $authorization->access_token;
        }

        $tokens = $authorization->refresh_token && ($authorization->refresh_expires_at?->isFuture() ?? true)
            ? $this->exchange(['grant_type' => 'refresh_token', 'refresh_token' => $authorization->refresh_token])
            : null;

        if ($tokens === null) {
            $authorization->delete();
            $user->unsetRelation('githubAuthorization');

            return null;
        }

        $authorization->update($tokens);

        return $tokens['access_token'];
    }

    /**
     * The repositories of an installation that the user can reach too (up to 300), most recently pushed first.
     *
     * @return list<array{full_name: string, name: string, private: bool, default_branch: string, clone_url: string, html_url: string, pushed_at: ?string, empty: bool}>
     *
     * @throws GitException
     */
    public function repositories(User $user, int $installationId): array
    {
        $repositories = [];

        for ($page = 1; $page <= 3; $page++) {
            $batch = $this->check($this->asUser($user)->get(self::API."/user/installations/{$installationId}/repositories", ['per_page' => 100, 'page' => $page]))->json('repositories') ?? [];
            array_push($repositories, ...array_map($this->repository(...), $batch));

            if (count($batch) < 100) {
                break;
            }
        }

        usort($repositories, fn (array $a, array $b) => strcmp((string) $b['pushed_at'], (string) $a['pushed_at']));

        return $repositories;
    }

    /**
     * One repository, as the user sees it (so only if they can reach it).
     *
     * @return array{full_name: string, name: string, private: bool, default_branch: string, clone_url: string, html_url: string, pushed_at: ?string, empty: bool}
     *
     * @throws GitException
     */
    public function find(User $user, string $fullName): array
    {
        return $this->repository($this->check($this->asUser($user)->get(self::API."/repos/{$fullName}"))->json());
    }

    /**
     * Whether a repository name is free on an account (as far as the user can tell).
     *
     * @throws GitException
     */
    public function available(User $user, string $owner, string $name): bool
    {
        $response = $this->asUser($user)->get(self::API."/repos/{$owner}/{$name}");

        if ($response->status() === 404) {
            return true;
        }

        $this->check($response);

        return false;
    }

    /**
     * A repository's branch names (up to 300), as the user sees them.
     *
     * @return list<string>
     *
     * @throws GitException
     */
    public function branches(User $user, string $fullName): array
    {
        $branches = [];

        for ($page = 1; $page <= 3; $page++) {
            $batch = $this->check($this->asUser($user)->get(self::API."/repos/{$fullName}/branches", ['per_page' => 100, 'page' => $page]))->json() ?? [];
            array_push($branches, ...array_column($batch, 'name'));

            if (count($batch) < 100) {
                break;
            }
        }

        return $branches;
    }

    /**
     * Create an empty repository in an organization the app is installed on (needs Administration: write).
     * Repositories an app creates are added to its installation.
     *
     * @return array{full_name: string, name: string, private: bool, default_branch: string, clone_url: string, html_url: string, pushed_at: ?string, empty: bool}
     *
     * @throws GitException
     */
    public function createOrganizationRepository(GitHubInstallation $installation, string $name, bool $private): array
    {
        $response = $this->api()->withToken($this->installationToken($installation->installation_id))
            ->post(self::API."/orgs/{$installation->account_login}/repos", ['name' => $name, 'private' => $private, 'auto_init' => false]);

        if ($response->status() === 422) {
            throw new GitException(__(':owner already has a repository called :name.', ['owner' => $installation->account_login, 'name' => $name]));
        }

        return $this->repository($this->check($response)->json());
    }

    /**
     * A token for pushing and pulling an installation's repositories, made when needed and reused while it's fresh.
     *
     * @throws GitException
     */
    public function installationToken(int $installationId): string
    {
        return Cache::remember("github-app-token:{$installationId}", self::TOKEN_SECONDS, function () use ($installationId) {
            $token = $this->check($this->api()->withToken($this->jwt())->post(self::API."/app/installations/{$installationId}/access_tokens"))->json('token');

            if (! is_string($token)) {
                throw new GitException(__('GitHub didn\'t give the app a token.'));
            }

            return $token;
        });
    }

    /**
     * The app as GitHub describes it (permissions, owner), cached briefly; null when GitHub doesn't accept its credentials.
     *
     * @return array<string, mixed>|null
     */
    protected function app(): ?array
    {
        if (! $this->configured()) {
            return null;
        }

        return Cache::remember('github-app:'.config('services.github_app.id'), 300, function () {
            try {
                $response = $this->api()->withToken($this->jwt())->get(self::API.'/app');
            } catch (Throwable) {
                return null;
            }

            return $response->successful() ? $response->json() : null;
        });
    }

    /**
     * Trade an OAuth code or refresh token for the user's tokens.
     *
     * @param  array<string, string>  $grant
     * @return array{access_token: string, refresh_token: ?string, expires_at: ?CarbonImmutable, refresh_expires_at: ?CarbonImmutable}|null
     */
    protected function exchange(array $grant): ?array
    {
        $response = Http::acceptJson()->timeout(20)->post('https://github.com/login/oauth/access_token', [
            'client_id' => config('services.github_app.client_id'),
            'client_secret' => config('services.github_app.client_secret'),
            ...$grant,
        ]);
        $token = $response->json('access_token');
        $refreshToken = $response->json('refresh_token');

        if (! is_string($token)) {
            return null;
        }

        // Apps with expiring user tokens off give tokens without an expiry or refresh token.
        return [
            'access_token' => $token,
            'refresh_token' => is_string($refreshToken) ? $refreshToken : null,
            'expires_at' => ($seconds = $response->json('expires_in')) ? now()->addSeconds((int) $seconds) : null,
            'refresh_expires_at' => ($seconds = $response->json('refresh_token_expires_in')) ? now()->addSeconds((int) $seconds) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $repository
     * @return array{full_name: string, name: string, private: bool, default_branch: string, clone_url: string, html_url: string, pushed_at: ?string, empty: bool}
     */
    protected function repository(array $repository): array
    {
        return [
            'full_name' => (string) $repository['full_name'],
            'name' => (string) ($repository['name'] ?? explode('/', (string) $repository['full_name'])[1]),
            'private' => (bool) ($repository['private'] ?? false),
            'default_branch' => (string) ($repository['default_branch'] ?? 'main'),
            'clone_url' => (string) $repository['clone_url'],
            'html_url' => (string) $repository['html_url'],
            'pushed_at' => $repository['pushed_at'] ?? null,
            'empty' => ($repository['size'] ?? 1) === 0,
        ];
    }

    /**
     * @throws GitException when the user needs to sign in through the app again
     */
    protected function asUser(User $user): PendingRequest
    {
        $token = $this->userToken($user);

        if ($token === null) {
            throw new GitHubSignInNeeded(__('Reconnect GitHub to see your repositories.'));
        }

        return $this->api()->withToken($token);
    }

    protected function api(): PendingRequest
    {
        return Http::acceptJson()->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])->timeout(20);
    }

    /**
     * The app's own short-lived credential, signed with its private key.
     */
    protected function jwt(): string
    {
        $key = str_replace('\n', "\n", (string) config('services.github_app.private_key'));

        return JWT::encode(['iat' => time() - 60, 'exp' => time() + 540, 'iss' => (string) config('services.github_app.id')], $key, 'RS256');
    }

    /**
     * @throws GitException
     */
    protected function check(Response $response): Response
    {
        return match (true) {
            $response->successful() => $response,
            $response->status() === 401 => throw new GitHubSignInNeeded(__('GitHub signed you out. Reconnect GitHub.')),
            $response->status() === 404 => throw new GitException(__('GitHub couldn\'t find that, or it isn\'t shared with the app. Check which repositories the app can reach on GitHub.')),
            $response->status() === 403 => throw new GitException(__('GitHub refused: :message', ['message' => $response->json('message') ?? $response->status()])),
            default => throw new GitException(__('GitHub didn\'t answer: :message', ['message' => $response->json('message') ?? $response->status()])),
        };
    }
}
