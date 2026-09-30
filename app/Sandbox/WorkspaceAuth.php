<?php

namespace App\Sandbox;

use App\Models\Sandbox;
use Illuminate\Support\Arr;

/**
 * The app's own sign-in, reached through docker/sandbox/auth.php inside the sandbox.
 * The agent builds it (following docker/sandbox/guides/auth.md) and describes it in .onedrop/auth.json;
 * users stay in the app's database and provider secrets stay in its env file.
 */
class WorkspaceAuth
{
    public const SCRIPT = '/opt/onedrop/auth.php';

    public const GUIDE = '/opt/onedrop/guides/auth.md';

    /** Sign-in methods the guide knows how to build, with their names in plain language. */
    public const METHODS = [
        'password' => 'email and password',
        'google' => 'Google',
        'github' => 'GitHub',
        'microsoft' => 'Microsoft',
        'onedrop' => 'OneDrop accounts',
    ];

    /** Methods that need keys from the provider. */
    public const PROVIDERS = ['google', 'github', 'microsoft'];

    public function __construct(protected SandboxProvider $provider) {}

    /**
     * How the app's sign-in is set up: ['configured' => false] until the agent has built it.
     *
     * @return array{configured: false}|array{configured: true, library: ?string, login_path: string, callback_path: string, methods: list<string>, env_file: string, users_table: string, keys: array<string, array{client_id: bool, client_secret: bool}>}
     *
     * @throws SandboxException|DatabaseException
     */
    public function status(Sandbox $sandbox): array
    {
        return $this->call($sandbox, ['op' => 'status']);
    }

    /**
     * One page of the app's users, newest first.
     *
     * @return array{users: list<array{id: mixed, name: mixed, email: mixed, created_at: mixed, last_login_at: mixed}>, total: int, page: int, per_page: int}
     *
     * @throws SandboxException|DatabaseException
     */
    public function users(Sandbox $sandbox, string $search = '', int $page = 1, int $perPage = 25): array
    {
        return $this->call($sandbox, ['op' => 'users', 'search' => $search, 'page' => $page, 'per_page' => $perPage]);
    }

    /**
     * How many of the app's users signed in at or after $since (unix seconds); null when the app can't tell.
     *
     * @throws SandboxException|DatabaseException
     */
    public function signedInSince(Sandbox $sandbox, int $since): ?int
    {
        return $this->call($sandbox, ['op' => 'active', 'since' => gmdate('Y-m-d H:i:s', $since)])['count'];
    }

    /**
     * Create a user with a password, through the app's own .onedrop/users helper (it hashes the password).
     *
     * @return array{created: bool, id: mixed}
     *
     * @throws SandboxException|DatabaseException
     */
    public function createUser(Sandbox $sandbox, string $name, string $email, string $password): array
    {
        return $this->call($sandbox, ['op' => 'create-user', 'name' => $name, 'email' => $email, 'password' => $password]);
    }

    /**
     * Change a user's name or email, turn their account off or on, or require a new password,
     * all in the app's database (the app enforces the last two, see guides/auth.md).
     *
     * @param  array{name?: string, email?: string, disabled?: bool, password_change_required?: bool}  $values
     *
     * @throws SandboxException|DatabaseException
     */
    public function updateUser(Sandbox $sandbox, string $id, array $values): void
    {
        $this->call($sandbox, ['op' => 'update-user', 'id' => $id, ...$values]);
    }

    /**
     * Set a user's password, through the app's own .onedrop/users helper.
     *
     * @throws SandboxException|DatabaseException
     */
    public function setPassword(Sandbox $sandbox, string $id, string $password, bool $signOut = false): void
    {
        $this->call($sandbox, ['op' => 'set-password', 'id' => $id, 'password' => $password, 'sign_out' => $signOut]);
    }

    /**
     * End all of a user's sessions, through the app's own .onedrop/users helper.
     *
     * @throws SandboxException|DatabaseException
     */
    public function signOut(Sandbox $sandbox, string $id): void
    {
        $this->call($sandbox, ['op' => 'sign-out', 'id' => $id]);
    }

    /**
     * Which account controls the app supports.
     *
     * @return array{helper: bool, disable: bool, require_password_change: bool}
     *
     * @throws SandboxException|DatabaseException
     */
    public function capabilities(Sandbox $sandbox): array
    {
        return $this->call($sandbox, ['op' => 'capabilities']);
    }

    /**
     * Every user, for a CSV download.
     *
     * @return array{users: list<array<string, mixed>>, truncated: bool}
     *
     * @throws SandboxException|DatabaseException
     */
    public function export(Sandbox $sandbox): array
    {
        return $this->call($sandbox, ['op' => 'export']);
    }

    /**
     * A one-time path in the app that signs the browser in as this user.
     *
     * @throws SandboxException|DatabaseException
     */
    public function signInLink(Sandbox $sandbox, string $id): string
    {
        return $this->call($sandbox, ['op' => 'sign-in-link', 'id' => $id])['path'];
    }

    /**
     * Write the app's OneDrop sign-in settings into its env file, then restart the app to use them.
     *
     * @param  array{client_id: string, client_secret: string, authorize_url: string, token_url: string, userinfo_url: string}  $settings
     *
     * @throws SandboxException|DatabaseException
     */
    public function saveOneDrop(Sandbox $sandbox, array $settings): void
    {
        $this->call($sandbox, ['op' => 'onedrop', ...$settings]);
        $this->provider->exec($sandbox->external_id, ['/opt/onedrop/restart']);
    }

    /**
     * @throws SandboxException|DatabaseException
     */
    public function deleteUser(Sandbox $sandbox, string $id): void
    {
        $this->call($sandbox, ['op' => 'delete-user', 'id' => $id]);
    }

    /**
     * Write a provider's client ID and/or secret into the app's env file, then restart the app to use them.
     *
     * @return array<string, mixed> the new status
     *
     * @throws SandboxException|DatabaseException
     */
    public function saveKeys(Sandbox $sandbox, string $provider, ?string $clientId, ?string $clientSecret): array
    {
        $status = $this->call($sandbox, ['op' => 'keys', 'provider' => $provider, 'client_id' => $clientId, 'client_secret' => $clientSecret]);

        $this->provider->exec($sandbox->external_id, ['/opt/onedrop/restart']);

        return $status;
    }

    /**
     * The chat message asking the agent for the account controls an app set up before them is missing.
     *
     * @param  array{helper: bool, disable: bool, require_password_change: bool, roles?: bool, sign_in_as?: bool}  $capabilities
     */
    public static function upgradeRequest(array $capabilities): ?string
    {
        $missing = array_keys(array_filter([
            'adding users, setting passwords and signing people out' => ! $capabilities['helper'],
            'turning accounts off' => ! $capabilities['disable'],
            'requiring a new password' => ! $capabilities['require_password_change'],
            'roles' => ! ($capabilities['roles'] ?? true),
            'signing in as a user' => ! ($capabilities['sign_in_as'] ?? true),
        ]));

        if ($missing === []) {
            return null;
        }

        return 'Let me manage users from Tools → Users & Auth: add '.Arr::join($missing, ', ', ' and ').'. Follow the "Account controls", "Roles" and ".onedrop/users" parts of the guide at '.self::GUIDE.'.';
    }

    /**
     * The chat message asking the agent to add sign-in, or to change it to exactly $methods.
     *
     * @param  list<string>  $methods
     * @param  list<string>|null  $current  methods the app has now, or null when sign-in isn't set up
     */
    public static function request(array $methods, ?array $current = null): string
    {
        $names = fn (array $keys): string => Arr::join(array_map(fn ($key) => self::METHODS[$key], $keys), ', ', ' and ');
        $methods = array_values(array_intersect(array_keys(self::METHODS), $methods));
        $guide = 'Follow the guide at '.self::GUIDE.'.';

        if ($current === null) {
            return "Add user sign-in to my app with {$names($methods)}. {$guide}";
        }

        $changes = array_filter([
            ($on = array_diff($methods, $current)) ? 'turn on '.$names(array_values($on)) : null,
            ($off = array_diff($current, $methods)) ? 'turn off '.$names(array_values($off)) : null,
        ]);

        return 'Change how people sign in to my app: '.implode(', and ', $changes).". {$guide}";
    }

    /**
     * @param  array<string, mixed>  $request
     *
     * @throws SandboxException|DatabaseException
     */
    protected function call(Sandbox $sandbox, array $request): mixed
    {
        $result = $this->provider->exec($sandbox->external_id, ['php', self::SCRIPT], [
            'APP_AUTH_REQUEST' => json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
        $response = json_decode(trim($result->output), true);

        if (! is_array($response) || ! isset($response['ok'])) {
            throw new SandboxException(__("This sandbox doesn't have the Users & Auth tool yet. Rebuild the sandbox image and recreate the sandbox."));
        }

        if ($response['ok'] !== true) {
            // The tool's messages are written for the user, like the database's.
            throw new DatabaseException((string) ($response['error'] ?? __('The Users & Auth request failed.')));
        }

        return $response['data'];
    }
}
