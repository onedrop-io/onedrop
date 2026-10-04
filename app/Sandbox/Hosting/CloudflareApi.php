<?php

namespace App\Sandbox\Hosting;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mime\MimeTypes;

/**
 * Cloudflare's API (https://developers.cloudflare.com/api): R2 buckets with keys scoped to one bucket, and Workers
 * that only serve a front end's files (Workers static assets).
 */
class CloudflareApi
{
    public const URL = 'https://api.cloudflare.com/client/v4';

    /** "Workers R2 Storage Bucket Item Write": read and write objects in one bucket. */
    public const BUCKET_WRITE = '2efd5506f9c8494dacb1fa10a3e7d5b6';

    /** Files per upload request. */
    protected const UPLOAD_BATCH = 50;

    public function __construct(protected HostingAccount $account) {}

    /**
     * Make a bucket and a key that can only use it, returning what an S3 client needs.
     *
     * @return array{bucket: string, token_id: string, access_key_id: string, secret_access_key: string, endpoint: string}
     *
     * @throws HostingException
     */
    public function createBucket(string $name): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->post($this->account('/r2/buckets'), ['name' => $name]));

        if ($response->failed() && ! str_contains(strtolower($response->body()), 'already exists')) {
            $this->fail($response, "Couldn't make the bucket");
        }

        $token = $this->check($this->send(fn (PendingRequest $http) => $http->post($this->account('/tokens'), [
            'name' => "onedrop-{$name}",
            'policies' => [[
                'effect' => 'allow',
                'resources' => ["com.cloudflare.edge.r2.bucket.{$this->accountId()}_default_{$name}" => '*'],
                'permission_groups' => [['id' => self::BUCKET_WRITE]],
            ]],
        ])), "Couldn't make the bucket's key");

        return [
            'bucket' => $name,
            'token_id' => (string) $token->json('result.id'),
            'access_key_id' => (string) $token->json('result.id'),
            'secret_access_key' => hash('sha256', (string) $token->json('result.value')),
            'endpoint' => "https://{$this->accountId()}.r2.cloudflarestorage.com",
        ];
    }

    /**
     * Empty and delete a bucket, and revoke its key. Deleting one that's gone is not an error.
     *
     * @throws HostingException
     */
    public function deleteBucket(string $name, ?string $tokenId): void
    {
        $emptied = $this->send(fn (PendingRequest $http) => $http->post($this->account("/r2/buckets/{$name}/jobs"), ['jobType' => 'prefixDelete', 'prefix' => '']));

        if ($emptied->status() !== 404) {
            $this->check($emptied, "Couldn't empty the bucket");
            $deleted = $this->send(fn (PendingRequest $http) => $http->delete($this->account("/r2/buckets/{$name}")));

            if ($deleted->status() !== 404) {
                // Emptying runs in the background; the next try deletes it.
                $this->check($deleted, "Couldn't delete the bucket yet (it's being emptied)");
            }
        }

        if ($tokenId) {
            $revoked = $this->send(fn (PendingRequest $http) => $http->delete($this->account("/tokens/{$tokenId}")));

            if ($revoked->status() !== 404) {
                $this->check($revoked, "Couldn't revoke the bucket's key");
            }
        }
    }

    /**
     * Serve a folder's files from a Worker (single-page app routing: unknown paths get index.html), and return its
     * workers.dev address.
     *
     * @throws HostingException
     */
    public function deploySite(string $name, string $directory): string
    {
        $files = $this->files($directory);
        $manifest = array_map(fn (array $file) => ['hash' => $file['hash'], 'size' => $file['size']], $files);

        $session = $this->check($this->send(fn (PendingRequest $http) => $http->post(
            $this->account("/workers/scripts/{$name}/assets-upload-session"),
            ['manifest' => $manifest],
        )), "Couldn't start uploading the site");

        $jwt = (string) $session->json('result.jwt');
        $byHash = collect($files)->keyBy('hash');

        foreach ($session->json('result.buckets') ?? [] as $bucket) {
            foreach (array_chunk($bucket, self::UPLOAD_BATCH) as $batch) {
                $upload = $this->send(function (PendingRequest $http) use ($batch, $byHash, $jwt) {
                    $request = $http->withToken($jwt);

                    foreach ($batch as $hash) {
                        $file = $byHash[$hash];
                        $request = $request->attach($hash, base64_encode((string) file_get_contents($file['path'])), $hash, ['Content-Type' => $file['mime']]);
                    }

                    return $request->post($this->account('/workers/assets/upload?base64=true'));
                }, withToken: false);

                $this->check($upload, "Couldn't upload the site's files");
                $jwt = $upload->json('result.jwt') ?: $jwt;
            }
        }

        $this->check($this->send(fn (PendingRequest $http) => $http
            ->attach('metadata', (string) json_encode([
                'assets' => ['jwt' => $jwt, 'config' => ['not_found_handling' => 'single-page-application', 'html_handling' => 'auto-trailing-slash']],
                'compatibility_date' => '2026-10-01',
            ]), 'metadata.json', ['Content-Type' => 'application/json'])
            ->put($this->account("/workers/scripts/{$name}"))), "Couldn't publish the site");

        $this->check($this->send(fn (PendingRequest $http) => $http->post($this->account("/workers/scripts/{$name}/subdomain"), ['enabled' => true])), "Couldn't give the site its address");

        $subdomain = $this->send(fn (PendingRequest $http) => $http->get($this->account('/workers/subdomain')))->json('result.subdomain');

        if (blank($subdomain)) {
            throw new HostingException('The Cloudflare account has no workers.dev subdomain yet. Pick one under Workers & Pages in the Cloudflare dashboard, then publish again.');
        }

        return "https://{$name}.{$subdomain}.workers.dev";
    }

    /**
     * Take a site down. Deleting one that's gone is not an error.
     *
     * @throws HostingException
     */
    public function deleteSite(string $name): void
    {
        $response = $this->send(fn (PendingRequest $http) => $http->delete($this->account("/workers/scripts/{$name}?force=true")));

        if ($response->status() !== 404) {
            $this->check($response, "Couldn't delete the site");
        }
    }

    /**
     * Serve a site's Worker at a hostname in a zone of this account (DOM-001); Cloudflare adds the DNS record and the
     * certificate itself. Returns the Workers domain's id.
     *
     * @throws HostingException
     */
    public function addWorkerDomain(string $hostname, string $service, string $zoneId): string
    {
        $response = $this->check($this->send(fn (PendingRequest $http) => $http->put($this->account('/workers/domains'), [
            'hostname' => $hostname,
            'service' => $service,
            'zone_id' => $zoneId,
            'environment' => 'production',
        ])), "Couldn't add the domain to the site");

        return (string) $response->json('result.id');
    }

    /**
     * @throws HostingException
     */
    public function deleteWorkerDomain(string $id): void
    {
        $response = $this->send(fn (PendingRequest $http) => $http->delete($this->account("/workers/domains/{$id}")));

        if ($response->status() !== 404) {
            $this->check($response, "Couldn't remove the domain from the site");
        }
    }

    /**
     * The id of the zone in this account a hostname belongs to (the longest matching name), or null.
     *
     * @throws HostingException
     */
    public function zoneFor(string $hostname): ?string
    {
        $labels = explode('.', $hostname);

        while (count($labels) >= 2) {
            $name = implode('.', $labels);
            $response = $this->check($this->send(fn (PendingRequest $http) => $http->get('/zones', ['name' => $name, 'account.id' => $this->accountId()])), "Couldn't look up the domain in Cloudflare");
            $id = $response->json('result.0.id');

            if (filled($id)) {
                return (string) $id;
            }

            array_shift($labels);
        }

        return null;
    }

    /**
     * Add a custom hostname to a Cloudflare for SaaS zone (the gateway Worker's, DOM-001), with a certificate
     * validated over HTTP once its CNAME points at the zone. Returns its id.
     *
     * @throws HostingException
     */
    public function createCustomHostname(string $zoneId, string $hostname): string
    {
        $response = $this->check($this->send(fn (PendingRequest $http) => $http->post("/zones/{$zoneId}/custom_hostnames", [
            'hostname' => $hostname,
            'ssl' => ['method' => 'http', 'type' => 'dv', 'settings' => ['min_tls_version' => '1.2']],
        ])), "Couldn't add the domain");

        return (string) $response->json('result.id');
    }

    /**
     * Whether a custom hostname and its certificate are active, and if not, why; null when it's gone.
     *
     * @return array{active: bool, problem: string|null}|null
     *
     * @throws HostingException
     */
    public function customHostname(string $zoneId, string $id): ?array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get("/zones/{$zoneId}/custom_hostnames/{$id}"));

        if ($response->status() === 404) {
            return null;
        }

        $this->check($response, "Couldn't check the domain");
        $result = $response->json('result');
        $active = ($result['status'] ?? null) === 'active' && ($result['ssl']['status'] ?? null) === 'active';
        $problem = $result['verification_errors'][0] ?? $result['ssl']['validation_errors'][0]['message'] ?? null;

        return ['active' => $active, 'problem' => $active ? null : $problem];
    }

    /**
     * @throws HostingException
     */
    public function deleteCustomHostname(string $zoneId, string $id): void
    {
        $response = $this->send(fn (PendingRequest $http) => $http->delete("/zones/{$zoneId}/custom_hostnames/{$id}"));

        if ($response->status() !== 404) {
            $this->check($response, "Couldn't remove the domain");
        }
    }

    /**
     * Every file under a folder, keyed by its path from there ("/index.html"), with a hash of its contents.
     *
     * @return array<string, array{path: string, hash: string, size: int, mime: string}>
     */
    protected function files(string $directory): array
    {
        $files = [];
        $mimes = new MimeTypes;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $path = $file->getPathname();
            $extension = strtolower($file->getExtension());
            $files['/'.ltrim(str_replace('\\', '/', substr($path, strlen($directory))), '/')] = [
                'path' => $path,
                // Any 32 hex characters derived from the contents (Cloudflare skips files it already has).
                'hash' => substr(hash('sha256', base64_encode((string) file_get_contents($path)).$extension), 0, 32),
                'size' => (int) $file->getSize(),
                'mime' => $mimes->getMimeTypes($extension)[0] ?? 'application/octet-stream',
            ];
        }

        if ($files === []) {
            throw new HostingException('The site has no files.');
        }

        return $files;
    }

    protected function accountId(): string
    {
        return (string) $this->account->get('account_id');
    }

    protected function account(string $path): string
    {
        return "/accounts/{$this->accountId()}{$path}";
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     *
     * @throws HostingException
     */
    protected function send(callable $call, bool $withToken = true): Response
    {
        $http = Http::baseUrl(self::URL)->acceptJson()->timeout(120);

        try {
            return $call($withToken ? $http->withToken((string) $this->account->get('api_token')) : $http);
        } catch (ConnectionException $e) {
            throw new HostingException("Couldn't reach Cloudflare: {$e->getMessage()}", previous: $e);
        }
    }

    /**
     * @throws HostingException
     */
    protected function check(Response $response, string $what): Response
    {
        if ($response->failed() || $response->json('success') === false) {
            $this->fail($response, $what);
        }

        return $response;
    }

    /**
     * @throws HostingException
     */
    protected function fail(Response $response, string $what): never
    {
        throw new HostingException(in_array($response->status(), [401, 403], true)
            ? "{$what}: Cloudflare didn't accept the API token (check its permissions)."
            : "{$what}: ".($response->json('errors.0.message') ?? trim(substr($response->body(), 0, 200))));
    }
}
