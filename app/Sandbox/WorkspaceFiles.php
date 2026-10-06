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

    /** Most matches one search inside files returns (FILE-008). */
    public const SEARCH_LIMIT = 1000;

    /** Most lines of ripgrep's JSON read per search: each match, plus a begin and an end line per file. */
    public const SEARCH_OUTPUT_LINES = 3000;

    /** Longest a search inside files runs before answering with what it found. */
    public const SEARCH_SECONDS = 10;

    /** Most of a matching line shown in search results; longer lines are cut around the match. */
    public const SEARCH_LINE_BYTES = 300;

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
     * Search inside the workspace's text files with ripgrep (FILE-008), in the whole workspace or one folder.
     * Hidden files are searched; .gitignore'd files, binaries, files over 1 MB and the COLLAPSED folders aren't.
     * Stops at SEARCH_LIMIT matches or after SEARCH_SECONDS, saying so with `truncated`.
     *
     * @param  array{case?: bool, word?: bool, regex?: bool}  $options
     * @return array{results: list<array{path: string, matches: list<array{line: int, column: array{int, int}, text: string, ranges: list<array{int, int}>}>}>, truncated: bool}
     *
     * @throws SandboxException when the search can't run, or InvalidSearchException when a regex doesn't parse
     */
    public function search(Sandbox $sandbox, string $query, string $folder = '', array $options = []): array
    {
        $args = ['--json', '--hidden', '--max-filesize', '1M', '--max-count', (string) self::SEARCH_LIMIT];

        foreach (self::COLLAPSED as $name) {
            array_push($args, '--glob', "!{$name}");
        }

        $args[] = ($options['case'] ?? false) ? '--case-sensitive' : '--ignore-case';

        if ($options['word'] ?? false) {
            $args[] = '--word-regexp';
        }

        if (! ($options['regex'] ?? false)) {
            $args[] = '--fixed-strings';
        }

        array_push($args, '--regexp', $query, '--');

        if ($folder !== '') {
            $args[] = $folder;
        }

        // ripgrep's own exit status goes to stderr, since head's is the pipeline's; head stops it at the limit.
        $result = $this->provider->exec($sandbox->external_id, [
            'sh', '-c',
            'cd '.self::ROOT.' || exit 1; { timeout '.self::SEARCH_SECONDS.' rg "$@"; echo "rg-exit:$?" >&2; } | head -n '.self::SEARCH_OUTPUT_LINES,
            'sh', ...$args,
        ]);

        preg_match('/rg-exit:(\d+)/', $result->errorOutput, $exit);
        $status = isset($exit[1]) ? (int) $exit[1] : null;

        if ($status === 127) {
            throw new SandboxException('Searching inside files needs a newer sandbox. Recreate it to get one.');
        }

        if ($status === 2 && trim($result->output) === '') {
            if (str_contains($result->errorOutput, 'regex parse error')) {
                throw new InvalidSearchException("That isn't a valid regular expression.");
            }

            if ($folder !== '' && str_contains($result->errorOutput, 'No such file or directory')) {
                return ['results' => [], 'truncated' => false];
            }
        }

        if ($status === null && ! $result->successful()) {
            throw new SandboxException("Couldn't search the project's files.");
        }

        $results = [];
        $count = 0;
        $lines = explode("\n", trim($result->output));

        foreach ($lines as $line) {
            $event = json_decode($line, true);

            if (($event['type'] ?? null) !== 'match' || $count >= self::SEARCH_LIMIT) {
                continue;
            }

            $path = $event['data']['path']['text'] ?? null;
            $text = $event['data']['lines']['text'] ?? null;

            if (! is_string($path) || ! is_string($text)) {
                continue;
            }

            $results[$path] ??= ['path' => $path, 'matches' => []];
            $results[$path]['matches'][] = self::searchLine(
                (int) $event['data']['line_number'],
                rtrim($text, "\r\n"),
                $event['data']['submatches'] ?? [],
            );
            $count++;
        }

        // ripgrep searches files in parallel, so they come in any order.
        uksort($results, 'strnatcasecmp');

        return [
            'results' => array_values($results),
            'truncated' => $count >= self::SEARCH_LIMIT || count($lines) >= self::SEARCH_OUTPUT_LINES || $status === 124,
        ];
    }

    /**
     * One matching line for the results list: long lines are cut to the part around the first match, and ripgrep's
     * byte offsets become character offsets into the text that's kept (`ranges`) and, for the first match, into the
     * whole line (`column`, where the editor selects it).
     *
     * @param  list<array{start: int, end: int}>  $submatches
     * @return array{line: int, column: array{int, int}, text: string, ranges: list<array{int, int}>}
     */
    protected static function searchLine(int $number, string $text, array $submatches): array
    {
        $start = 0;
        $first = $submatches[0]['start'] ?? 0;

        if (strlen($text) > self::SEARCH_LINE_BYTES && $first > 40) {
            $start = $first - 40;

            // Don't cut a character in half.
            while ($start > 0 && (ord($text[$start]) & 0xC0) === 0x80) {
                $start--;
            }
        }

        $kept = mb_strcut($text, $start, self::SEARCH_LINE_BYTES, 'UTF-8');
        $ranges = [];

        foreach ($submatches as $submatch) {
            $from = $submatch['start'] - $start;
            $to = min($submatch['end'] - $start, strlen($kept));

            if ($from < 0 || $from >= $to) {
                continue;
            }

            $ranges[] = [mb_strlen(substr($kept, 0, $from), 'UTF-8'), mb_strlen(substr($kept, 0, $to), 'UTF-8')];
        }

        $firstEnd = $submatches[0]['end'] ?? 0;

        return [
            'line' => $number,
            'column' => [mb_strlen(substr($text, 0, $first), 'UTF-8'), mb_strlen(substr($text, 0, $firstEnd), 'UTF-8')],
            'text' => ($start > 0 ? '…' : '').$kept,
            'ranges' => $start > 0 ? array_map(fn (array $range) => [$range[0] + 1, $range[1] + 1], $ranges) : $ranges,
        ];
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
