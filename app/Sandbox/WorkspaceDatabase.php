<?php

namespace App\Sandbox;

use App\Models\HostedService;
use App\Models\Sandbox;
use App\Sandbox\Hosting\FlyApi;
use App\Sandbox\Hosting\HostedServices;
use App\Sandbox\Hosting\HostingException;

/**
 * The app's own databases, reached through docker/sandbox/db.php inside the sandbox, or on a hosted app's machine
 * (its Fly app's HostedService, HOST-007). Whichever runs the tool finds and connects to them; credentials never
 * leave it.
 */
class WorkspaceDatabase
{
    public const SCRIPT = '/opt/onedrop/db.php';

    /** Largest request sent per exec; keeps the env value under Linux's 128 KiB limit. */
    public const MAX_REQUEST_BYTES = 120_000;

    /** Runs the rest of the command as the sandbox user when it starts as root, as hosted apps' commands do. */
    protected const AS_SANDBOX_USER = 'if [ "$(id -u)" = 0 ]; then exec runuser -u sandbox -- "$@"; fi; exec "$@"';

    /** The release's own copy of the tool (docker/sandbox/hosting packs it), or the base image's for older releases. */
    protected const HOSTED_SCRIPT = 'f=/opt/onedrop-hosting/db.php; [ -f "$f" ] || f='.self::SCRIPT.'; exec php "$f"';

    public function __construct(protected SandboxProvider $provider, protected HostedServices $services) {}

    /**
     * Databases found in the workspace.
     *
     * @return list<array{id: string, driver: string, label: string, summary: string, source: ?string, error: ?string}>
     *
     * @throws SandboxException|DatabaseException
     */
    public function connections(Sandbox|HostedService $sandbox): array
    {
        return $this->call($sandbox, ['op' => 'connections']);
    }

    /**
     * @return list<array{name: string, type: 'table'|'view', columns: list<string>, database?: string, collection?: string}>
     *
     * @throws SandboxException|DatabaseException
     */
    public function tables(Sandbox|HostedService $sandbox, string $connection): array
    {
        return $this->call($sandbox, ['op' => 'tables', 'connection' => $connection]);
    }

    /**
     * One page of a table: its columns, rows and total count.
     *
     * @param  array{table: string, page?: int, per_page?: int, sort?: ?string, direction?: string, filters?: list<array{column: string, operator: string, value?: mixed}>}  $options
     * @return array<string, mixed>
     *
     * @throws SandboxException|DatabaseException
     */
    public function rows(Sandbox|HostedService $sandbox, string $connection, array $options): array
    {
        return $this->call($sandbox, ['op' => 'rows', 'connection' => $connection, ...$options]);
    }

    /**
     * Insert, update and delete rows of one table, all or nothing.
     *
     * @param  array{table: string, inserts?: list<array<string, mixed>>, updates?: list<array{key: array<string, mixed>, values: array<string, mixed>}>, deletes?: list<array<string, mixed>>}  $changes
     * @return array{inserted: int, updated: int, deleted: int}
     *
     * @throws SandboxException|DatabaseException
     */
    public function change(Sandbox|HostedService $sandbox, string $connection, array $changes): array
    {
        return $this->call($sandbox, ['op' => 'changes', 'connection' => $connection, ...$changes]);
    }

    /**
     * Run one SQL statement.
     *
     * @return array{columns: list<string>, rows: list<list<mixed>>, truncated: bool, affected: ?int, duration_ms: float}
     *
     * @throws SandboxException|DatabaseException
     */
    public function query(Sandbox|HostedService $sandbox, string $connection, string $sql): array
    {
        return $this->call($sandbox, ['op' => 'query', 'connection' => $connection, 'sql' => $sql]);
    }

    /**
     * Copy a SQLite database to a signed upload link, consistently, and return its size (HOST-007).
     *
     * @param  array<string, string|list<string>>  $headers
     * @return array{bytes: int}
     *
     * @throws SandboxException|DatabaseException
     */
    public function backup(Sandbox|HostedService $sandbox, string $connection, string $url, array $headers): array
    {
        return $this->call($sandbox, ['op' => 'backup', 'connection' => $connection, 'upload_url' => $url, 'upload_headers' => $headers]);
    }

    /**
     * @param  array<string, mixed>  $request
     *
     * @throws SandboxException|DatabaseException
     */
    protected function call(Sandbox|HostedService $sandbox, array $request): mixed
    {
        $payload = json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        if (strlen($payload) > self::MAX_REQUEST_BYTES) {
            throw new DatabaseException(__('That is too much to send at once. Save fewer changes at a time.'));
        }

        $result = $sandbox instanceof HostedService
            ? $this->onHostedApp($sandbox, $payload)
            : $this->provider->exec($sandbox->external_id, ['php', self::SCRIPT], ['APP_DB_REQUEST' => $payload]);
        $response = json_decode(trim($result->output), true);

        if ((! is_array($response) || ! isset($response['ok'])) && $sandbox instanceof HostedService) {
            throw new SandboxException(__("Couldn't read the hosted app's database: :error", ['error' => strtok(trim($result->errorOutput ?: $result->output), "\n") ?: __('no answer')]));
        }

        if (! is_array($response) || ! isset($response['ok'])) {
            throw new SandboxException(__("This sandbox doesn't have the database tool yet. Rebuild the sandbox image and recreate the sandbox."));
        }

        if ($response['ok'] !== true) {
            throw new DatabaseException((string) ($response['error'] ?? __('The database request failed.')));
        }

        return $response['data'];
    }

    /**
     * Run the tool on a hosted app's machine (waking it first), as the sandbox user so files it writes stay the app's,
     * with the hosted services' addresses it reads and the request in its environment, as in the sandbox.
     *
     * @throws SandboxException
     */
    protected function onHostedApp(HostedService $app, string $payload): ExecResult
    {
        $machine = $app->details['machine'] ?? null;

        if ($machine === null) {
            throw new SandboxException(__("The hosted app isn't running. Publish it to Hosting first."));
        }

        $environment = collect(['ONEDROP_HOSTED' => '1', ...$this->services->environment($app->project)])
            ->map(fn (string $value, string $key) => "{$key}={$value}")
            ->values()->all();

        try {
            $fly = new FlyApi($this->services->accountFor($app));
            $fly->wake($app->name, $machine);

            return $fly->exec($app->name, $machine, ['/bin/sh', '-c', self::AS_SANDBOX_USER, 'sh', 'env', ...$environment, "APP_DB_REQUEST={$payload}", 'sh', '-c', self::HOSTED_SCRIPT]);
        } catch (HostingException $e) {
            throw new SandboxException($e->getMessage(), previous: $e);
        }
    }
}
