<?php

namespace App\Sandbox;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * The platform's tools in a sandbox (scripts, guides, the proxy): every file the image copies from docker/sandbox into
 * /opt/onedrop. A running sandbox gets changed ones copied in, only those, instead of a new sandbox (SBX-002). The
 * base files can't be swapped in place, so a change to them waits for a new image and a new sandbox.
 */
class SandboxTools
{
    /** Where the image puts the tools. */
    public const PATH = '/opt/onedrop';

    /** Where the image keeps a copy of its base files, so a sandbox can say which base it was made from. */
    public const BASE_PATH = '/opt/onedrop-base';

    /**
     * Files a running sandbox can't take in place: the Dockerfile (packages, users, settings), start.sh (the
     * container's main process), dockerd (the sandbox user's only sudo rule) and shell-keys.js (built into
     * shell.html with ttyd's page when the image is made).
     */
    public const BASE_FILES = ['Dockerfile', 'start.sh', 'dockerd', 'shell-keys.js'];

    /**
     * Tool files that only need what every image has (bash, coreutils, tar, curl), so they go into sandboxes on an older
     * base too: snapshots and hosting then work for every sandbox, old ones included (SBX-009, HOST-001).
     */
    public const ANY_BASE = ['snapshot', 'hosting'];

    /**
     * Long-running processes that load a tool file once, by the pattern `pkill -f` finds them with. start.sh starts
     * each again within a second. Everything else is read each time it's used.
     */
    public const RESTARTS = [
        'host-proxy.mjs' => 'node /opt/onedrop/host-proxy.mjs',
        'inspector.js' => 'node /opt/onedrop/host-proxy.mjs',
        'preview-frame.mjs' => 'node /opt/onedrop/host-proxy.mjs',
        'file-watcher.mjs' => 'node /opt/onedrop/file-watcher.mjs',
        'sshd_config' => 'sshd -D -e -f /opt/onedrop/sshd_config',
    ];

    /** @var array<string, string>|null */
    protected ?array $files = null;

    protected string $source;

    public function __construct(?string $source = null)
    {
        $this->source = $source ?? base_path('docker/sandbox');
    }

    /**
     * Whether the image's source is here to copy tools from (the one-container install's app image may lack it).
     */
    public function available(): bool
    {
        return is_file("{$this->source}/Dockerfile");
    }

    /**
     * The tool files, read from the Dockerfile's COPY lines into /opt/onedrop.
     *
     * @return array<string, string> path under /opt/onedrop => source file
     */
    public function files(): array
    {
        if ($this->files !== null) {
            return $this->files;
        }

        $files = [];

        foreach (preg_split('/\R/', File::get("{$this->source}/Dockerfile")) ?: [] as $line) {
            // COPY [--chown=…] source… destination; never --from (another image's files).
            if (! preg_match('/^COPY\s+((?:--[a-z]+=\S+\s+)*)(.+)$/', trim($line), $match) || str_contains($match[1], '--from')) {
                continue;
            }

            $words = preg_split('/\s+/', trim($match[2])) ?: [];
            $destination = array_pop($words);

            if ($destination === null || ! str_starts_with($destination, self::PATH.'/')) {
                continue;
            }

            $relative = trim(substr($destination, strlen(self::PATH) + 1), '/');

            foreach ($words as $word) {
                $path = "{$this->source}/{$word}";

                if (is_dir($path)) {
                    foreach (File::allFiles($path) as $file) {
                        $files[ltrim("{$relative}/".$file->getRelativePathname(), '/')] = $file->getPathname();
                    }
                } elseif (is_file($path)) {
                    $files[str_ends_with($destination, '/') || count($words) > 1 ? ltrim("{$relative}/".basename($word), '/') : $relative] = $path;
                }
            }
        }

        ksort($files);

        return $this->files = array_diff_key($files, array_flip(self::BASE_FILES));
    }

    /**
     * What every tool and base file should hash to in a sandbox made from the current source.
     *
     * @return array<string, string> absolute path in the sandbox => sha256
     */
    public function expected(): array
    {
        $hashes = [];

        foreach ($this->files() as $relative => $file) {
            $hashes[self::PATH."/{$relative}"] = $this->hash($file);
        }

        foreach (self::BASE_FILES as $name) {
            $hashes[self::BASE_PATH."/{$name}"] = $this->hash("{$this->source}/{$name}");
        }

        return $hashes;
    }

    /**
     * A source file's hash; a file the Dockerfile copies but the source lacks would build a broken image.
     *
     * @throws RuntimeException
     */
    protected function hash(string $file): string
    {
        $hash = hash_file('sha256', $file);

        if ($hash === false) {
            throw new RuntimeException("Couldn't read the sandbox tool file {$file}.");
        }

        return $hash;
    }

    /**
     * One hash for the whole source, to remember that a sandbox already matches it.
     */
    public function version(): string
    {
        return hash('sha256', json_encode($this->expected(), JSON_THROW_ON_ERROR));
    }

    /**
     * Compare a sandbox's tools with the source, in one command.
     *
     * @return array{base: bool, changed: list<string>} whether its base files match, and the tool files (paths under
     *                                                  /opt/onedrop) that differ or are missing
     *
     * @throws SandboxException
     */
    public function compare(SandboxProvider $provider, string $id): array
    {
        if (Cache::get($this->cacheKey($id)) === $this->version()) {
            return ['base' => true, 'changed' => []];
        }

        $expected = $this->expected();
        // The paths go in the environment, one per line: as arguments they're more than Runtime's exec takes (128).
        $result = $provider->exec($id, ['sh', '-c', 'printf "%s\n" "$ONEDROP_TOOL_PATHS" | tr "\n" "\000" | xargs -0 sha256sum -- 2>/dev/null; exit 0'], [
            'ONEDROP_TOOL_PATHS' => implode("\n", array_keys($expected)),
        ]);

        if (! $result->successful()) {
            throw new SandboxException("Couldn't read the sandbox's tools: ".(strtok(trim($result->errorOutput), "\n") ?: 'unknown error'));
        }

        $actual = [];

        foreach (preg_split('/\R/', trim($result->output)) ?: [] as $line) {
            if (preg_match('/^([0-9a-f]{64})\s+\*?(\S.*)$/', $line, $match)) {
                $actual[$match[2]] = $match[1];
            }
        }

        $base = collect(self::BASE_FILES)->every(fn (string $name) => ($actual[self::BASE_PATH."/{$name}"] ?? null) === $expected[self::BASE_PATH."/{$name}"]);
        $changed = array_values(collect($this->files())->keys()
            ->reject(fn (string $relative) => ($actual[self::PATH."/{$relative}"] ?? null) === $expected[self::PATH."/{$relative}"])
            ->all());

        if ($base && $changed === []) {
            $this->remember($id);
        }

        return ['base' => $base, 'changed' => $changed];
    }

    /**
     * Copy tool files into a running sandbox and restart the processes that loaded them. Returns false when the
     * provider can't write there (Blaxel), so a new sandbox has to bring them.
     *
     * @param  list<string>  $files  paths under /opt/onedrop
     *
     * @throws SandboxException
     */
    public function install(SandboxProvider $provider, string $id, array $files): bool
    {
        if ($files === []) {
            return true;
        }

        $directory = storage_path('framework/sandbox-tools-'.uniqid());
        $sources = $this->files();

        try {
            foreach ($files as $relative) {
                File::ensureDirectoryExists(dirname("{$directory}/{$relative}"));
                File::copy($sources[$relative], "{$directory}/{$relative}");
                // Scripts stay executable.
                chmod("{$directory}/{$relative}", fileperms($sources[$relative]) & 0777);
            }

            if (! $provider->installFiles($id, $directory, self::PATH)) {
                return false;
            }
        } finally {
            File::deleteDirectory($directory);
        }

        foreach (collect($files)->map(fn (string $relative) => self::RESTARTS[$relative] ?? null)->filter()->unique() as $pattern) {
            $provider->exec($id, ['pkill', '-f', $pattern]);
        }

        // Remembers the sandbox as current when it now matches.
        $this->compare($provider, $id);

        return true;
    }

    /**
     * Make sure a sandbox has the current copy of the given ANY_BASE tool files, whatever its base. Returns false when
     * it doesn't and the provider can't copy them in.
     *
     * @param  list<string>  $files  paths under /opt/onedrop
     *
     * @throws SandboxException
     */
    public function ensure(SandboxProvider $provider, string $id, array $files): bool
    {
        $missing = array_values(array_intersect($files, $this->compare($provider, $id)['changed']));

        return $missing === [] || $this->install($provider, $id, $missing);
    }

    protected function remember(string $id): void
    {
        Cache::put($this->cacheKey($id), $this->version(), now()->addDay());
    }

    protected function cacheKey(string $id): string
    {
        return "sandbox-tools:{$id}";
    }
}
