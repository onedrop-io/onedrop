<?php

namespace App\Sandbox;

use App\Models\Sandbox;

/**
 * Access to a sandbox's /workspace through short provider exec calls.
 */
class WorkspaceFiles
{
    public const ROOT = '/workspace';

    /** Folders listed but never expanded. */
    public const COLLAPSED = ['node_modules', '.git', 'vendor', '.cache'];

    public const MAX_ENTRIES = 5000;

    public const MAX_BYTES = 200_000;

    /** Bytes sent per exec when writing; keeps each env value under Linux's 128 KiB limit. */
    public const WRITE_CHUNK_BYTES = 64_000;

    /** Largest single uploaded file, in kilobytes. */
    public const MAX_UPLOAD_KILOBYTES = 10_240;

    /**
     * Zips the folder $argv[2] into $argv[1], naming entries from $argv[3] down and skipping folders named in the
     * rest of $argv. Runs inside the sandbox.
     */
    protected const ZIP_SCRIPT = <<<'PHP'
        $root = $argv[2];
        $base = $argv[3];
        $skip = array_slice($argv, 4);
        $zip = new ZipArchive;
        $zip->open($argv[1], ZipArchive::CREATE | ZipArchive::OVERWRITE) === true || exit(1);
        $files = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                fn (SplFileInfo $file) => ! ($file->isDir() && in_array($file->getFilename(), $skip, true)),
            ),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($files as $file) {
            $name = substr($file->getPathname(), strlen($base) + 1);
            $file->isDir() ? $zip->addEmptyDir($name) : ($file->isReadable() && $zip->addFile($file->getPathname(), $name));
        }
        $empty = $zip->numFiles === 0;
        $zip->close() || exit(1);
        $empty && file_put_contents($argv[1], "PK\x05\x06".str_repeat("\0", 18));
        PHP;

    public function __construct(protected SandboxProvider $provider) {}

    /**
     * Every file and folder, relative to the workspace, folders first then by name.
     *
     * @return list<array{path: string, type: 'file'|'dir'}>
     *
     * @throws SandboxException
     */
    public function list(Sandbox $sandbox): array
    {
        $prune = [];
        foreach (self::COLLAPSED as $name) {
            if ($prune !== []) {
                $prune[] = '-o';
            }

            array_push($prune, '-name', $name);
        }

        $result = $this->provider->exec($sandbox->external_id, [
            'find', self::ROOT, '-mindepth', '1',
            '(', ...$prune, ')', '-prune', '-printf', '%y %P\n',
            '-o', '-printf', '%y %P\n',
        ]);

        // find still lists everything else when it can't read a folder (e.g. a database's data folder owned by
        // its container's user, SBX-008), but exits with an error; only nothing listed at all is a failure.
        if (! $result->successful() && trim($result->output) === '') {
            throw new SandboxException("Couldn't list the project's files.");
        }

        $entries = [];

        foreach (explode("\n", trim($result->output)) as $line) {
            if (strlen($line) < 3 || count($entries) >= self::MAX_ENTRIES) {
                continue;
            }

            $entries[] = ['path' => substr($line, 2), 'type' => $line[0] === 'd' ? 'dir' : 'file'];
        }

        usort($entries, fn (array $a, array $b) => strnatcasecmp($a['path'], $b['path']));

        return $entries;
    }

    /**
     * A file's text, or a reason it can't be shown.
     *
     * @return array{path: string, content: string|null, notice: string|null}
     *
     * @throws SandboxException
     */
    public function read(Sandbox $sandbox, string $path): array
    {
        $result = $this->provider->exec($sandbox->external_id, [
            'head', '--bytes', (string) (self::MAX_BYTES + 1), '--', self::ROOT.'/'.$path,
        ]);

        if (! $result->successful()) {
            throw new SandboxException("Couldn't open {$path}.");
        }

        $content = $result->output;

        $notice = match (true) {
            str_contains($content, "\0") || ! mb_check_encoding($content, 'UTF-8') => "This file isn't text, so it can't be shown here.",
            strlen($content) > self::MAX_BYTES => 'This file is too large to show here.',
            default => null,
        };

        return ['path' => $path, 'content' => $notice ? null : $content, 'notice' => $notice];
    }

    /**
     * Replace a file's text. Content goes through an env var (exec has no stdin),
     * in chunks, into a temp file that is then copied over the original (keeping its permissions).
     *
     * @throws SandboxException
     */
    public function write(Sandbox $sandbox, string $path, string $content): void
    {
        $target = self::ROOT.'/'.$path;
        $temp = $target.'.onedrop-tmp';

        $this->writeChunks($sandbox, $temp, $content, "Couldn't save {$path}.");

        $result = $this->provider->exec($sandbox->external_id, [
            'sh', '-c', 'cat "$1" > "$2" && rm -f "$1"', 'sh', $temp, $target,
        ]);

        if (! $result->successful()) {
            throw new SandboxException("Couldn't save {$path}.");
        }
    }

    /**
     * Create an empty file or folder (and any missing parent folders).
     * Returns false, changing nothing, when something already exists at that path.
     *
     * @param  'file'|'dir'  $type
     *
     * @throws SandboxException
     */
    public function create(Sandbox $sandbox, string $path, string $type): bool
    {
        $make = $type === 'dir'
            ? 'mkdir -p -- "$1"'
            : 'mkdir -p -- "$(dirname -- "$1")" && : > "$1"';

        $result = $this->provider->exec($sandbox->external_id, [
            'sh', '-c', 'if [ -e "$1" ]; then exit 3; fi; '.$make, 'sh', self::ROOT.'/'.$path,
        ]);

        if ($result->exitCode === 3) {
            return false;
        }

        if (! $result->successful()) {
            throw new SandboxException("Couldn't create {$path}.");
        }

        return true;
    }

    /**
     * Write an uploaded file (any bytes), creating its folders. Content goes
     * base64-encoded through env vars, then is decoded over the target.
     *
     * @throws SandboxException
     */
    public function upload(Sandbox $sandbox, string $path, string $bytes): void
    {
        $target = self::ROOT.'/'.$path;
        $temp = $target.'.onedrop-upload';
        $error = "Couldn't upload {$path}.";

        $result = $this->provider->exec($sandbox->external_id, ['mkdir', '-p', '--', dirname($target)]);

        if (! $result->successful()) {
            throw new SandboxException($error);
        }

        $this->writeChunks($sandbox, $temp, base64_encode($bytes), $error);

        $result = $this->provider->exec($sandbox->external_id, [
            'sh', '-c', 'base64 -d "$1" > "$2"; status=$?; rm -f "$1"; exit $status', 'sh', $temp, $target,
        ]);

        if (! $result->successful()) {
            throw new SandboxException($error);
        }
    }

    /**
     * The workspace, or one folder in it, as zip bytes, without the folders that are never expanded.
     * A folder's entries sit inside a folder of its name. The zip is built inside the sandbox (its PHP has the zip extension).
     *
     * @throws SandboxException
     */
    public function zip(Sandbox $sandbox, ?string $path = null): string
    {
        $temp = '/tmp/onedrop-download-'.bin2hex(random_bytes(6)).'.zip';
        $root = $path === null ? self::ROOT : self::ROOT.'/'.$path;
        $base = $path === null ? self::ROOT : dirname($root);

        $build = $this->provider->exec($sandbox->external_id, ['php', '-r', self::ZIP_SCRIPT, '--', $temp, $root, $base, ...self::COLLAPSED]);

        $result = $build->successful()
            ? $this->provider->exec($sandbox->external_id, [
                'sh', '-c', 'base64 -w0 "$1"; status=$?; rm -f "$1"; exit $status', 'sh', $temp,
            ])
            : $build;

        $bytes = base64_decode(trim($result->output), true);

        if (! $result->successful() || $bytes === false) {
            throw new SandboxException($path === null ? "Couldn't zip the project's files." : "Couldn't zip {$path}.");
        }

        return $bytes;
    }

    /**
     * Whether a path is a folder.
     *
     * @throws SandboxException
     */
    public function isDirectory(Sandbox $sandbox, string $path): bool
    {
        $result = $this->provider->exec($sandbox->external_id, [
            'sh', '-c', 'if [ -d "$1" ]; then exit 0; elif [ -e "$1" ]; then exit 1; else exit 3; fi', 'sh', self::ROOT.'/'.$path,
        ]);

        return match ($result->exitCode) {
            0 => true,
            1 => false,
            default => throw new SandboxException("{$path} doesn't exist."),
        };
    }

    /**
     * A file's bytes, whatever they are.
     *
     * @throws SandboxException
     */
    public function bytes(Sandbox $sandbox, string $path): string
    {
        $result = $this->provider->exec($sandbox->external_id, ['base64', '-w0', '--', self::ROOT.'/'.$path]);
        $bytes = base64_decode(trim($result->output), true);

        if (! $result->successful() || $bytes === false) {
            throw new SandboxException("Couldn't download {$path}.");
        }

        return $bytes;
    }

    /**
     * Rename or move a file or folder, creating the target's folders.
     * Returns false, changing nothing, when something already exists at the target.
     *
     * @throws SandboxException
     */
    public function move(Sandbox $sandbox, string $from, string $to): bool
    {
        $result = $this->provider->exec($sandbox->external_id, [
            'sh', '-c', 'if [ -e "$2" ]; then exit 3; fi; mkdir -p -- "$(dirname -- "$2")" && mv -- "$1" "$2"',
            'sh', self::ROOT.'/'.$from, self::ROOT.'/'.$to,
        ]);

        if ($result->exitCode === 3) {
            return false;
        }

        if (! $result->successful()) {
            throw new SandboxException("Couldn't rename {$from}.");
        }

        return true;
    }

    /**
     * Delete a file, or a folder and everything in it.
     *
     * @throws SandboxException
     */
    public function delete(Sandbox $sandbox, string $path): void
    {
        $result = $this->provider->exec($sandbox->external_id, ['rm', '-rf', '--', self::ROOT.'/'.$path]);

        if (! $result->successful()) {
            throw new SandboxException("Couldn't delete {$path}.");
        }
    }

    /**
     * Write content to a sandbox file through env vars (exec has no stdin),
     * in chunks that each stay under Linux's 128 KiB env limit.
     *
     * @throws SandboxException
     */
    public function writeChunks(Sandbox $sandbox, string $file, string $content, string $error): void
    {
        $chunks = $content === '' ? [''] : str_split($content, self::WRITE_CHUNK_BYTES);

        foreach ($chunks as $index => $chunk) {
            $redirect = $index === 0 ? '>' : '>>';

            $result = $this->provider->exec($sandbox->external_id, [
                'sh', '-c', 'printf %s "$APP_CONTENT" '.$redirect.' "$1"', 'sh', $file,
            ], ['APP_CONTENT' => $chunk]);

            if (! $result->successful()) {
                throw new SandboxException($error);
            }
        }
    }

    /**
     * Whether a user-supplied path stays inside the workspace.
     */
    public static function isSafePath(string $path): bool
    {
        return $path !== ''
            && ! str_starts_with($path, '/')
            && ! str_contains($path, "\0")
            && ! in_array('..', explode('/', $path), true);
    }

    /**
     * Whether a path names one file or folder inside the workspace, never the workspace itself
     * (no `.` or empty parts), so renaming or deleting it can't touch anything else.
     */
    public static function isEntryPath(string $path): bool
    {
        return self::isSafePath($path)
            && array_intersect(explode('/', $path), ['', '.']) === [];
    }
}
