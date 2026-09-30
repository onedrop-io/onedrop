<?php

namespace App\Sandbox;

use App\Models\SystemSetting;
use Illuminate\Database\Connection;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FileAttributes;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Backups of the app's own database (ADMIN-005): SQLite copied with VACUUM INTO and gzipped, Postgres dumped with
 * pg_dump, kept on this server's disk or in S3-compatible storage, the newest few kept, and restorable.
 */
class DatabaseBackups
{
    public const SETTING = 'backups';

    public const DEFAULT_SCHEDULE = '0 0 * * *';

    public const DEFAULT_KEEP = 10;

    /** Backup file names: onedrop-<date>-<time>[-before-restore].<sqlite.gz|pgdump>. */
    public const NAME = '/^onedrop-\d{8}-\d{6}(-before-restore)?\.(sqlite\.gz|pgdump)$/';

    /**
     * The saved settings, with defaults.
     *
     * @return array{enabled: bool, schedule: string, keep: int, destination: 'local'|'s3', prefix: string, s3: array{bucket: string|null, region: string|null, endpoint: string|null, key: string|null, secret: string|null, path_style: bool}, last: array{at: string, ok: bool, message: string|null, file: string|null}|null, last_restore: array{at: string, ok: bool, message: string|null, file: string|null}|null}
     */
    public function settings(): array
    {
        $saved = SystemSetting::group(self::SETTING);

        return [
            'enabled' => (bool) ($saved['enabled'] ?? false),
            'schedule' => (string) ($saved['schedule'] ?? self::DEFAULT_SCHEDULE),
            'keep' => (int) ($saved['keep'] ?? self::DEFAULT_KEEP),
            'destination' => ($saved['destination'] ?? 'local') === 's3' ? 's3' : 'local',
            'prefix' => trim((string) ($saved['prefix'] ?? ''), '/'),
            's3' => [
                'bucket' => $saved['s3']['bucket'] ?? null,
                'region' => $saved['s3']['region'] ?? null,
                'endpoint' => $saved['s3']['endpoint'] ?? null,
                'key' => $saved['s3']['key'] ?? null,
                'secret' => $saved['s3']['secret'] ?? null,
                'path_style' => (bool) ($saved['s3']['path_style'] ?? false),
            ],
            'last' => $saved['last'] ?? null,
            'last_restore' => $saved['last_restore'] ?? null,
        ];
    }

    /**
     * Save the settings. A blank S3 secret keeps the saved one.
     *
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): void
    {
        $saved = SystemSetting::group(self::SETTING);
        $s3 = $values['s3'] ?? [];

        SystemSetting::put(self::SETTING, [
            ...$saved,
            'enabled' => (bool) $values['enabled'],
            'schedule' => (string) $values['schedule'],
            'keep' => (int) $values['keep'],
            'destination' => $values['destination'],
            'prefix' => trim((string) ($values['prefix'] ?? ''), '/'),
            's3' => [
                'bucket' => $s3['bucket'] ?? null,
                'region' => $s3['region'] ?? null,
                'endpoint' => $s3['endpoint'] ?? null,
                'key' => $s3['key'] ?? null,
                'secret' => filled($s3['secret'] ?? null) ? $s3['secret'] : ($saved['s3']['secret'] ?? null),
                'path_style' => (bool) ($s3['path_style'] ?? false),
            ],
        ]);
    }

    /**
     * Whether scheduled backups are on.
     */
    public function scheduled(): bool
    {
        return $this->settings()['enabled'];
    }

    /**
     * Where backups go.
     */
    public function disk(): FilesystemAdapter
    {
        $settings = $this->settings();

        if ($settings['destination'] === 's3') {
            return $this->build([
                'driver' => 's3',
                'key' => $settings['s3']['key'],
                'secret' => $settings['s3']['secret'],
                'region' => $settings['s3']['region'] ?: 'us-east-1',
                'bucket' => $settings['s3']['bucket'],
                'endpoint' => $settings['s3']['endpoint'] ?: null,
                'use_path_style_endpoint' => $settings['s3']['path_style'],
                'throw' => true,
            ]);
        }

        return $this->build(['driver' => 'local', 'root' => self::localRoot(), 'throw' => true]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function build(array $config): FilesystemAdapter
    {
        $disk = Storage::build($config);

        if (! $disk instanceof FilesystemAdapter) {
            throw new RuntimeException("The backup disk isn't a Flysystem disk.");
        }

        return $disk;
    }

    /**
     * Where backups go on this server's disk.
     */
    public static function localRoot(): string
    {
        return storage_path('app/backups');
    }

    /**
     * The backups at the destination, newest first.
     *
     * @return list<array{name: string, size: int, created_at: string|null}>
     */
    public function list(): array
    {
        $prefix = $this->settings()['prefix'];
        $backups = [];

        foreach ($this->disk()->getDriver()->listContents($prefix, false) as $item) {
            $name = basename($item->path());

            if ($item instanceof FileAttributes && preg_match(self::NAME, $name)) {
                $backups[] = [
                    'name' => $name,
                    'size' => (int) $item->fileSize(),
                    'created_at' => $item->lastModified() ? Carbon::createFromTimestamp($item->lastModified())->toIso8601String() : null,
                ];
            }
        }

        usort($backups, fn (array $a, array $b) => strcmp(substr($b['name'], 8, 15), substr($a['name'], 8, 15)) ?: strcmp($b['name'], $a['name']));

        return $backups;
    }

    /**
     * Back up the database now, then delete all but the newest few. Returns the backup's name.
     *
     * @throws RuntimeException
     */
    public function backUp(bool $beforeRestore = false): string
    {
        $name = 'onedrop-'.now()->format('Ymd-His').($beforeRestore ? '-before-restore' : '').'.'.$this->extension();
        $directory = $this->scratch();

        try {
            $file = $this->driver() === 'sqlite' ? $this->copySqlite($directory) : $this->dumpPostgres($directory);
            $stream = $this->open($file, 'rb');
            $this->disk()->writeStream($this->path($name), $stream);
            fclose($stream);
        } finally {
            File::deleteDirectory($directory);
        }

        $this->prune();

        return $name;
    }

    /**
     * Back up, recording how it went for the Backups page.
     */
    public function run(): ?string
    {
        try {
            $name = $this->backUp();
            $this->remember('last', true, null, $name);

            return $name;
        } catch (Throwable $e) {
            report($e);
            $this->remember('last', false, $e->getMessage(), null);

            return null;
        }
    }

    /**
     * Replace the database with a backup, after backing up the current one. Queue workers restart afterwards so
     * none keeps writing to the old database.
     *
     * @throws RuntimeException
     */
    public function restore(string $name): void
    {
        $this->ensureValidName($name);

        if (! $this->disk()->exists($this->path($name))) {
            throw new RuntimeException("There's no backup called {$name}.");
        }

        if (! str_ends_with($name, '.'.$this->extension())) {
            throw new RuntimeException("{$name} is a backup of a ".($this->driver() === 'sqlite' ? 'Postgres' : 'SQLite').' database; this app uses '.($this->driver() === 'sqlite' ? 'SQLite' : 'Postgres').'.');
        }

        $directory = $this->scratch();

        try {
            $download = "{$directory}/{$name}";
            $stream = $this->disk()->readStream($this->path($name)) ?? throw new RuntimeException("Couldn't read the backup {$name}.");
            $local = $this->open($download, 'wb');
            stream_copy_to_stream($stream, $local);
            fclose($local);
            fclose($stream);

            $this->backUp(beforeRestore: true);

            $this->driver() === 'sqlite' ? $this->restoreSqlite($download) : $this->restorePostgres($download);
        } finally {
            File::deleteDirectory($directory);
        }

        SystemSetting::flush();
        Artisan::call('queue:restart');
    }

    /**
     * Delete a backup.
     */
    public function delete(string $name): void
    {
        $this->ensureValidName($name);
        $this->disk()->delete($this->path($name));
    }

    /**
     * A backup's path at the destination.
     */
    public function path(string $name): string
    {
        $prefix = $this->settings()['prefix'];

        return $prefix === '' ? $name : "{$prefix}/{$name}";
    }

    /**
     * Save how a backup or restore went.
     */
    public function remember(string $key, bool $ok, ?string $message, ?string $file): void
    {
        SystemSetting::merge(self::SETTING, [$key => ['at' => now()->toIso8601String(), 'ok' => $ok, 'message' => $message, 'file' => $file]]);
    }

    /**
     * @throws RuntimeException
     */
    public function ensureValidName(string $name): void
    {
        if (! preg_match(self::NAME, $name)) {
            throw new RuntimeException("{$name} isn't a backup.");
        }
    }

    /**
     * The app's database driver: "sqlite" or "pgsql".
     *
     * @throws RuntimeException
     */
    protected function driver(): string
    {
        $driver = $this->connection()->getDriverName();

        if (! in_array($driver, ['sqlite', 'pgsql'], true)) {
            throw new RuntimeException("Backups support SQLite and Postgres, not {$driver}.");
        }

        return $driver;
    }

    /**
     * The app's database connection.
     */
    protected function connection(): Connection
    {
        return DB::connection();
    }

    /**
     * @return resource
     *
     * @throws RuntimeException
     */
    protected function open(string $file, string $mode)
    {
        return fopen($file, $mode) ?: throw new RuntimeException("Couldn't open {$file}.");
    }

    protected function extension(): string
    {
        return $this->driver() === 'sqlite' ? 'sqlite.gz' : 'pgdump';
    }

    /**
     * A consistent copy of the SQLite database (VACUUM INTO works while the app keeps using it), gzipped.
     */
    protected function copySqlite(string $directory): string
    {
        $copy = "{$directory}/database.sqlite";
        $this->connection()->statement('VACUUM INTO ?', [$copy]);

        $gzipped = "{$copy}.gz";
        $in = $this->open($copy, 'rb');
        $out = gzopen($gzipped, 'wb6') ?: throw new RuntimeException("Couldn't write {$gzipped}.");

        while (! feof($in)) {
            gzwrite($out, (string) fread($in, 1 << 20));
        }

        fclose($in);
        gzclose($out);

        return $gzipped;
    }

    /**
     * @throws RuntimeException
     */
    protected function dumpPostgres(string $directory): string
    {
        $file = "{$directory}/database.pgdump";
        $this->postgres(['pg_dump', '--format=custom', '--no-owner', '--no-privileges', "--file={$file}"]);

        return $file;
    }

    /**
     * Swap the SQLite database file for the backup's, once it checks out.
     *
     * @throws RuntimeException
     */
    protected function restoreSqlite(string $download): void
    {
        $database = (string) $this->connection()->getConfig('database');

        if ($database === '' || $database === ':memory:' || ! File::exists($database)) {
            throw new RuntimeException("This app's SQLite database isn't a file, so it can't be restored.");
        }

        // Next to the database, so the swap is a rename on the same disk.
        $restored = "{$database}.restoring";
        $in = gzopen($download, 'rb') ?: throw new RuntimeException("Couldn't read {$download}.");
        $out = $this->open($restored, 'wb');

        while (! gzeof($in)) {
            fwrite($out, (string) gzread($in, 1 << 20));
        }

        gzclose($in);
        fclose($out);

        try {
            $check = new PDO("sqlite:{$restored}");
            $integrity = $check->query('PRAGMA integrity_check');
            $migrations = $check->query("SELECT count(*) FROM sqlite_master WHERE type = 'table' AND name = 'migrations'");
            $ok = $integrity !== false && $integrity->fetchColumn() === 'ok'
                && $migrations !== false && $migrations->fetchColumn() > 0;
            $integrity = $migrations = null;
            $check = null;
        } catch (Throwable) {
            $ok = false;
        }

        if (! $ok) {
            File::delete($restored);

            throw new RuntimeException("The backup isn't a valid OneDrop database.");
        }

        // Fold any write-ahead log into the old file, then swap; a leftover -wal would be applied to the new one.
        $this->connection()->statement('PRAGMA wal_checkpoint(TRUNCATE)');
        DB::disconnect();
        File::move($restored, $database);
        File::delete(["{$database}-wal", "{$database}-shm"]);
        DB::reconnect();
    }

    /**
     * @throws RuntimeException
     */
    protected function restorePostgres(string $download): void
    {
        $this->postgres(['pg_restore', '--clean', '--if-exists', '--no-owner', '--no-privileges', '--single-transaction', $download]);
    }

    /**
     * Run a Postgres tool against the app's database.
     *
     * @param  list<string>  $command
     *
     * @throws RuntimeException
     */
    protected function postgres(array $command): void
    {
        $config = $this->connection()->getConfig();

        $result = Process::timeout(3600)
            ->env(array_filter([
                'PGHOST' => $config['host'] ?? null,
                'PGPORT' => isset($config['port']) ? (string) $config['port'] : null,
                'PGUSER' => $config['username'] ?? null,
                'PGPASSWORD' => $config['password'] ?? null,
                'PGDATABASE' => $config['database'] ?? null,
                'PGSSLMODE' => $config['sslmode'] ?? null,
            ]))
            ->run([...$command, '--dbname='.($config['database'] ?? '')]);

        if ($result->failed()) {
            throw new RuntimeException(trim($result->errorOutput()) ?: "{$command[0]} failed.");
        }
    }

    /**
     * Delete all but the newest backups.
     */
    protected function prune(): void
    {
        $disk = $this->disk();

        foreach (array_slice($this->list(), max(1, $this->settings()['keep'])) as $backup) {
            $disk->delete($this->path($backup['name']));
        }
    }

    protected function scratch(): string
    {
        $directory = storage_path('app/private/backup-'.bin2hex(random_bytes(6)));
        File::ensureDirectoryExists($directory);

        return $directory;
    }
}
