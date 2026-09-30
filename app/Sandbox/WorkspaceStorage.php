<?php

namespace App\Sandbox;

use App\Models\Sandbox;

/**
 * The app's storage buckets: folders in $APP_STORAGE_DIR on the sandbox's disk, outside the app's code,
 * reached through docker/sandbox/storage.php inside the sandbox. The app reads and writes them directly
 * (docker/sandbox/guides/storage.md); the platform never keeps a copy.
 */
class WorkspaceStorage
{
    public const SCRIPT = '/opt/onedrop/storage.php';

    public const GUIDE = '/opt/onedrop/guides/storage.md';

    public const BUCKET_PATTERN = '/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/';

    /** Largest single uploaded file, in kilobytes. */
    public const MAX_UPLOAD_KILOBYTES = 10_240;

    /** Largest object that can be downloaded or previewed, in bytes. */
    public const MAX_DOWNLOAD_BYTES = 25_000_000;

    /** Base64 characters sent per exec when uploading: a multiple of 4, under Linux's 128 KiB env limit. */
    public const UPLOAD_CHUNK_BYTES = 64_000;

    public function __construct(protected SandboxProvider $provider) {}

    /**
     * @return list<array{name: string, objects: int, bytes: int}>
     *
     * @throws SandboxException|StorageException
     */
    public function buckets(Sandbox $sandbox): array
    {
        return $this->call($sandbox, ['op' => 'buckets'])['buckets'];
    }

    /**
     * @return list<array{name: string, objects: int, bytes: int}>
     *
     * @throws SandboxException|StorageException
     */
    public function createBucket(Sandbox $sandbox, string $name): array
    {
        return $this->call($sandbox, ['op' => 'create-bucket', 'name' => $name])['buckets'];
    }

    /**
     * Delete a bucket and everything in it.
     *
     * @return list<array{name: string, objects: int, bytes: int}>
     *
     * @throws SandboxException|StorageException
     */
    public function deleteBucket(Sandbox $sandbox, string $name): array
    {
        return $this->call($sandbox, ['op' => 'delete-bucket', 'name' => $name])['buckets'];
    }

    /**
     * The folders and objects directly inside a folder ('' for the top of the bucket).
     *
     * @return array{folders: list<array{name: string, path: string}>, objects: list<array{name: string, path: string, size: int, modified: int}>}
     *
     * @throws SandboxException|StorageException
     */
    public function list(Sandbox $sandbox, string $bucket, string $prefix = ''): array
    {
        return $this->call($sandbox, ['op' => 'list', 'bucket' => $bucket, 'prefix' => $prefix]);
    }

    /**
     * Objects anywhere in the bucket whose path contains the query.
     *
     * @return list<array{name: string, path: string, size: int, modified: int}>
     *
     * @throws SandboxException|StorageException
     */
    public function search(Sandbox $sandbox, string $bucket, string $query): array
    {
        return $this->call($sandbox, ['op' => 'search', 'bucket' => $bucket, 'query' => $query])['objects'];
    }

    /**
     * @throws SandboxException|StorageException
     */
    public function createFolder(Sandbox $sandbox, string $bucket, string $path): void
    {
        $this->call($sandbox, ['op' => 'mkdir', 'bucket' => $bucket, 'path' => $path]);
    }

    /**
     * Delete an object, or a folder and everything in it.
     *
     * @throws SandboxException|StorageException
     */
    public function delete(Sandbox $sandbox, string $bucket, string $path): void
    {
        $this->call($sandbox, ['op' => 'delete', 'bucket' => $bucket, 'path' => $path]);
    }

    /**
     * Save bytes as an object, replacing any at that path. The bytes go base64-encoded in chunks
     * (exec has no stdin) into a temporary upload, which is then moved into the bucket.
     *
     * @return array{name: string, path: string, size: int, modified: int}
     *
     * @throws SandboxException|StorageException
     */
    public function upload(Sandbox $sandbox, string $bucket, string $path, string $bytes): array
    {
        $upload = bin2hex(random_bytes(16));
        $encoded = base64_encode($bytes);

        foreach ($encoded === '' ? [''] : str_split($encoded, self::UPLOAD_CHUNK_BYTES) as $index => $chunk) {
            $this->call($sandbox, ['op' => 'upload-chunk', 'upload' => $upload, 'data' => $chunk, 'first' => $index === 0]);
        }

        return $this->call($sandbox, ['op' => 'upload-finish', 'upload' => $upload, 'bucket' => $bucket, 'path' => $path])['object'];
    }

    /**
     * An object's bytes.
     *
     * @throws SandboxException|StorageException
     */
    public function read(Sandbox $sandbox, string $bucket, string $path): string
    {
        $data = $this->call($sandbox, ['op' => 'read', 'bucket' => $bucket, 'path' => $path, 'max' => self::MAX_DOWNLOAD_BYTES]);

        return (string) base64_decode($data['data'], true);
    }

    /** The chat message asking the agent to use a bucket for the app's uploads. */
    public static function setupRequest(string $bucket, string $uses): string
    {
        $uses = trim($uses);

        return "Use the App Storage bucket \"{$bucket}\" to store files in the app"
            .($uses === '' ? '.' : ": {$uses}".(preg_match('/[.!?]$/', $uses) ? '' : '.'))
            .' Follow the guide at '.self::GUIDE.'.';
    }

    /**
     * @param  array<string, mixed>  $request
     *
     * @throws SandboxException|StorageException
     */
    protected function call(Sandbox $sandbox, array $request): mixed
    {
        $result = $this->provider->exec($sandbox->external_id, ['php', self::SCRIPT], [
            'APP_STORAGE_REQUEST' => json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
        $response = json_decode(trim($result->output), true);

        if (! is_array($response) || ! isset($response['ok'])) {
            throw new SandboxException(__("This sandbox doesn't have App Storage yet. Rebuild the sandbox image and recreate the sandbox."));
        }

        if ($response['ok'] !== true) {
            throw new StorageException((string) ($response['error'] ?? __('The App Storage request failed.')));
        }

        return $response['data'];
    }
}
