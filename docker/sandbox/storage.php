<?php

/**
 * App Storage tool behind the workspace's Tools → App Storage panel.
 *
 * Buckets are folders in $APP_STORAGE_DIR (/data/storage), on the sandbox's disk but outside the app's code;
 * objects are the files inside them. The app reads and writes them directly (see guides/storage.md).
 * This lists, creates and deletes buckets, and lists, searches, uploads, reads and deletes objects.
 *
 * Reads one JSON request from $APP_STORAGE_REQUEST and prints one JSON response:
 * {"ok": true, "data": ...} or {"ok": false, "error": "..."}.
 *
 * Usage: APP_STORAGE_REQUEST='{"op":"buckets"}' php /opt/onedrop/storage.php
 */

declare(strict_types=1);

ini_set('display_errors', 'stderr');
error_reporting(E_ALL);

const BUCKET_PATTERN = '/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/';
const UPLOAD_PATTERN = '/^[a-f0-9]{16,64}$/';
const PATH_BYTES_MAX = 1024;
const SEARCH_RESULTS_MAX = 500;
const LIST_ENTRIES_MAX = 5000;

/** Unfinished uploads older than this are removed. */
const UPLOAD_TTL_SECONDS = 86400;

final class ToolError extends RuntimeException {}

function root(): string
{
    return rtrim(getenv('APP_STORAGE_DIR') ?: '/data/storage', '/');
}

function respond(array $response): never
{
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
    exit(0);
}

function ensureRoot(): void
{
    if (! is_dir(root()) && ! @mkdir(root(), 0755, true)) {
        throw new ToolError("App Storage isn't set up in this sandbox. Rebuild the sandbox image and recreate the sandbox.");
    }
}

function validBucket(mixed $name): string
{
    if (! is_string($name) || ! preg_match(BUCKET_PATTERN, $name)) {
        throw new ToolError('Use 3–63 lowercase letters, digits and dashes for the bucket name, starting and ending with a letter or digit.');
    }

    return $name;
}

/**
 * An existing bucket's folder.
 */
function bucketDir(mixed $name): string
{
    $dir = root().'/'.validBucket($name);

    if (! is_dir($dir)) {
        throw new ToolError("There's no bucket called {$name}. Refresh to see the current list.");
    }

    return $dir;
}

/**
 * A cleaned object path inside a bucket: relative, no empty, "." or ".." parts.
 */
function validPath(mixed $path, bool $allowEmpty = false): string
{
    $path = is_string($path) ? trim($path, '/') : null;

    if ($path === '' && $allowEmpty) {
        return '';
    }

    if ($path === null || $path === '' || strlen($path) > PATH_BYTES_MAX || preg_match('/[\x00-\x1f]/', $path)) {
        throw new ToolError('That path isn\'t valid.');
    }

    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.' || $part === '..' || strlen($part) > 255) {
            throw new ToolError('That path isn\'t valid.');
        }
    }

    return $path;
}

/**
 * Refuse anything that resolves (e.g. through a symlink) outside the bucket.
 */
function insideBucket(string $bucketDir, string $target): string
{
    $real = realpath($target);
    $base = realpath($bucketDir);

    if ($real === false || $base === false || ($real !== $base && ! str_starts_with($real, $base.'/'))) {
        throw new ToolError("That isn't in the bucket anymore. Refresh to see what's there.");
    }

    return $real;
}

/**
 * @return iterable<SplFileInfo>
 */
function filesIn(string $dir): iterable
{
    return new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
    );
}

/**
 * @return array{name: string, path: string, size: int, modified: int}
 */
function objectInfo(string $bucketDir, SplFileInfo $file): array
{
    return [
        'name' => $file->getFilename(),
        'path' => substr($file->getPathname(), strlen($bucketDir) + 1),
        'size' => (int) $file->getSize(),
        'modified' => (int) $file->getMTime(),
    ];
}

/**
 * @return array{buckets: list<array{name: string, objects: int, bytes: int}>}
 */
function listBuckets(): array
{
    ensureRoot();
    $buckets = [];

    foreach (scandir(root()) ?: [] as $name) {
        if (! preg_match(BUCKET_PATTERN, $name) || ! is_dir(root().'/'.$name)) {
            continue;
        }

        $objects = 0;
        $bytes = 0;

        foreach (filesIn(root().'/'.$name) as $file) {
            if ($file->isFile()) {
                $objects++;
                $bytes += (int) $file->getSize();
            }
        }

        $buckets[] = ['name' => $name, 'objects' => $objects, 'bytes' => $bytes];
    }

    return ['buckets' => $buckets];
}

/**
 * @param  array<string, mixed>  $request
 * @return array{buckets: list<array{name: string, objects: int, bytes: int}>}
 */
function createBucket(array $request): array
{
    ensureRoot();
    $name = validBucket($request['name'] ?? null);

    if (file_exists(root().'/'.$name)) {
        throw new ToolError("There's already a bucket called {$name}.");
    }

    if (! @mkdir(root().'/'.$name, 0755)) {
        throw new ToolError("Couldn't create the bucket.");
    }

    return listBuckets();
}

/**
 * @param  array<string, mixed>  $request
 * @return array{buckets: list<array{name: string, objects: int, bytes: int}>}
 */
function deleteBucket(array $request): array
{
    removeTree(bucketDir($request['name'] ?? null));

    return listBuckets();
}

/**
 * The folders and objects directly inside a folder of a bucket, folders first, each by name.
 *
 * @param  array<string, mixed>  $request
 * @return array{folders: list<array{name: string, path: string}>, objects: list<array{name: string, path: string, size: int, modified: int}>}
 */
function listObjects(array $request): array
{
    $bucketDir = bucketDir($request['bucket'] ?? null);
    $prefix = validPath($request['prefix'] ?? '', allowEmpty: true);
    $dir = $prefix === '' ? $bucketDir : $bucketDir.'/'.$prefix;

    if (! is_dir($dir)) {
        throw new ToolError("That folder isn't in the bucket anymore. Refresh to see what's there.");
    }

    insideBucket($bucketDir, $dir);
    $folders = [];
    $objects = [];

    foreach (new FilesystemIterator($dir) as $entry) {
        if (count($folders) + count($objects) >= LIST_ENTRIES_MAX) {
            break;
        }

        if ($entry->isDir()) {
            $folders[] = ['name' => $entry->getFilename(), 'path' => substr($entry->getPathname(), strlen($bucketDir) + 1)];
        } elseif ($entry->isFile()) {
            $objects[] = objectInfo($bucketDir, $entry);
        }
    }

    $byName = fn (array $a, array $b) => strnatcasecmp($a['name'], $b['name']);
    usort($folders, $byName);
    usort($objects, $byName);

    return ['folders' => $folders, 'objects' => $objects];
}

/**
 * Objects anywhere in the bucket whose path contains the query (ignoring case).
 *
 * @param  array<string, mixed>  $request
 * @return array{objects: list<array{name: string, path: string, size: int, modified: int}>}
 */
function searchObjects(array $request): array
{
    $bucketDir = bucketDir($request['bucket'] ?? null);
    $query = mb_strtolower(trim((string) ($request['query'] ?? '')));
    $objects = [];

    foreach (filesIn($bucketDir) as $file) {
        if (! $file->isFile()) {
            continue;
        }

        $info = objectInfo($bucketDir, $file);

        if ($query === '' || str_contains(mb_strtolower($info['path']), $query)) {
            $objects[] = $info;
        }

        if (count($objects) >= SEARCH_RESULTS_MAX) {
            break;
        }
    }

    usort($objects, fn (array $a, array $b) => strnatcasecmp($a['path'], $b['path']));

    return ['objects' => $objects];
}

/**
 * @param  array<string, mixed>  $request
 * @return array{path: string}
 */
function createFolder(array $request): array
{
    $bucketDir = bucketDir($request['bucket'] ?? null);
    $path = validPath($request['path'] ?? null);
    $target = $bucketDir.'/'.$path;

    if (file_exists($target)) {
        throw new ToolError("{$path} already exists.");
    }

    makeParents($bucketDir, $target);

    if (! @mkdir($target, 0755)) {
        throw new ToolError("Couldn't create {$path}.");
    }

    return ['path' => $path];
}

/**
 * Delete an object, or a folder and everything in it.
 *
 * @param  array<string, mixed>  $request
 * @return array{path: string}
 */
function deleteObject(array $request): array
{
    $bucketDir = bucketDir($request['bucket'] ?? null);
    $path = validPath($request['path'] ?? null);
    $target = $bucketDir.'/'.$path;

    if (is_link($target)) {
        unlink($target);

        return ['path' => $path];
    }

    if (! file_exists($target)) {
        throw new ToolError("{$path} isn't in the bucket anymore. Refresh to see what's there.");
    }

    $real = insideBucket($bucketDir, $target);
    is_dir($real) ? removeTree($real) : unlink($real);

    return ['path' => $path];
}

/**
 * Append one base64 chunk (a multiple of 4 characters) to an unfinished upload.
 *
 * @param  array<string, mixed>  $request
 * @return array{bytes: int}
 */
function uploadChunk(array $request): array
{
    ensureRoot();
    $file = uploadFile($request['upload'] ?? null);
    $bytes = base64_decode((string) ($request['data'] ?? ''), true);

    if ($bytes === false) {
        throw new ToolError('The upload was damaged. Try again.');
    }

    if (($request['first'] ?? false) === true) {
        pruneUploads();
        @mkdir(dirname($file), 0700);
    }

    if (file_put_contents($file, $bytes, ($request['first'] ?? false) === true ? 0 : FILE_APPEND) === false) {
        throw new ToolError("Couldn't save the upload.");
    }

    return ['bytes' => (int) filesize($file)];
}

/**
 * Move a finished upload into the bucket, replacing any object at that path.
 *
 * @param  array<string, mixed>  $request
 * @return array{object: array{name: string, path: string, size: int, modified: int}}
 */
function finishUpload(array $request): array
{
    $file = uploadFile($request['upload'] ?? null);
    $bucketDir = bucketDir($request['bucket'] ?? null);
    $path = validPath($request['path'] ?? null);
    $target = $bucketDir.'/'.$path;

    if (! is_file($file)) {
        throw new ToolError('The upload was lost. Try again.');
    }

    if (is_dir($target)) {
        @unlink($file);

        throw new ToolError("There's already a folder called {$path}.");
    }

    makeParents($bucketDir, $target);

    if (is_link($target)) {
        unlink($target);
    }

    if (! @rename($file, $target)) {
        @unlink($file);

        throw new ToolError("Couldn't save {$path}.");
    }

    chmod($target, 0644);

    return ['object' => objectInfo($bucketDir, new SplFileInfo($target))];
}

/**
 * An object's bytes, base64-encoded, if it's no larger than "max" bytes.
 *
 * @param  array<string, mixed>  $request
 * @return array{name: string, path: string, size: int, modified: int, data: string}
 */
function readObject(array $request): array
{
    $bucketDir = bucketDir($request['bucket'] ?? null);
    $path = validPath($request['path'] ?? null);
    $target = $bucketDir.'/'.$path;

    if (! is_file($target)) {
        throw new ToolError("{$path} isn't in the bucket anymore. Refresh to see what's there.");
    }

    $real = insideBucket($bucketDir, $target);
    $max = (int) ($request['max'] ?? 0);

    if ($max > 0 && filesize($real) > $max) {
        throw new ToolError(sprintf('%s is too large to download here (the limit is %d MB).', $path, intdiv($max, 1_000_000)));
    }

    $bytes = file_get_contents($real);

    if ($bytes === false) {
        throw new ToolError("Couldn't read {$path}.");
    }

    return [...objectInfo($bucketDir, new SplFileInfo($target)), 'data' => base64_encode($bytes)];
}

function uploadFile(mixed $id): string
{
    if (! is_string($id) || ! preg_match(UPLOAD_PATTERN, $id)) {
        throw new ToolError('The upload was damaged. Try again.');
    }

    return root().'/.uploads/'.$id;
}

function pruneUploads(): void
{
    foreach (glob(root().'/.uploads/*') ?: [] as $file) {
        if (is_file($file) && filemtime($file) < time() - UPLOAD_TTL_SECONDS) {
            @unlink($file);
        }
    }
}

/**
 * Create the folders above a target, refusing to go through a file or out of the bucket.
 */
function makeParents(string $bucketDir, string $target): void
{
    $parent = dirname($target);

    if (file_exists($parent) && ! is_dir($parent)) {
        throw new ToolError('Part of that path is a file, not a folder.');
    }

    if (! is_dir($parent) && ! @mkdir($parent, 0755, true)) {
        throw new ToolError('Part of that path is a file, not a folder.');
    }

    insideBucket($bucketDir, $parent);
}

function removeTree(string $dir): void
{
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($entries as $entry) {
        $entry->isDir() && ! $entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }

    rmdir($dir);
}

try {
    $request = json_decode((string) getenv('APP_STORAGE_REQUEST'), true);

    if (! is_array($request)) {
        throw new ToolError('The request was not valid JSON.');
    }

    respond(['ok' => true, 'data' => match ($request['op'] ?? null) {
        'buckets' => listBuckets(),
        'create-bucket' => createBucket($request),
        'delete-bucket' => deleteBucket($request),
        'list' => listObjects($request),
        'search' => searchObjects($request),
        'mkdir' => createFolder($request),
        'delete' => deleteObject($request),
        'upload-chunk' => uploadChunk($request),
        'upload-finish' => finishUpload($request),
        'read' => readObject($request),
        default => throw new ToolError('Unknown operation.'),
    }]);
} catch (ToolError $e) {
    respond(['ok' => false, 'error' => $e->getMessage()]);
}
