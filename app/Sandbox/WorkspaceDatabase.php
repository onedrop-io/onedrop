<?php

namespace App\Sandbox;

use App\Models\Sandbox;

/**
 * The app's own databases, reached through docker/sandbox/db.php inside the sandbox.
 * The sandbox finds and connects to them; credentials never leave it.
 */
class WorkspaceDatabase
{
    public const SCRIPT = '/opt/zap/db.php';

    /** Largest request sent per exec; keeps the env value under Linux's 128 KiB limit. */
    public const MAX_REQUEST_BYTES = 120_000;

    public function __construct(protected SandboxProvider $provider) {}

    /**
     * Databases found in the workspace.
     *
     * @return list<array{id: string, driver: string, label: string, summary: string, source: ?string, error: ?string}>
     *
     * @throws SandboxException|DatabaseException
     */
    public function connections(Sandbox $sandbox): array
    {
        return $this->call($sandbox, ['op' => 'connections']);
    }

    /**
     * @return list<array{name: string, type: 'table'|'view'}>
     *
     * @throws SandboxException|DatabaseException
     */
    public function tables(Sandbox $sandbox, string $connection): array
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
    public function rows(Sandbox $sandbox, string $connection, array $options): array
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
    public function change(Sandbox $sandbox, string $connection, array $changes): array
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
    public function query(Sandbox $sandbox, string $connection, string $sql): array
    {
        return $this->call($sandbox, ['op' => 'query', 'connection' => $connection, 'sql' => $sql]);
    }

    /**
     * @param  array<string, mixed>  $request
     *
     * @throws SandboxException|DatabaseException
     */
    protected function call(Sandbox $sandbox, array $request): mixed
    {
        $payload = json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        if (strlen($payload) > self::MAX_REQUEST_BYTES) {
            throw new DatabaseException(__('That is too much to send at once. Save fewer changes at a time.'));
        }

        $result = $this->provider->exec($sandbox->external_id, ['php', self::SCRIPT], ['APP_DB_REQUEST' => $payload]);
        $response = json_decode(trim($result->output), true);

        if (! is_array($response) || ! isset($response['ok'])) {
            throw new SandboxException(__("This sandbox doesn't have the database tool yet. Rebuild the sandbox image and recreate the sandbox."));
        }

        if ($response['ok'] !== true) {
            throw new DatabaseException((string) ($response['error'] ?? __('The database request failed.')));
        }

        return $response['data'];
    }
}
