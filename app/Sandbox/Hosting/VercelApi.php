<?php

namespace App\Sandbox\Hosting;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Vercel's API (https://vercel.com/docs/rest-api), for projects hosted there (HOST-011): one Vercel project per app,
 * its services' addresses as the project's environment, deployments made from files sent by their SHA-1 (only the ones
 * Vercel doesn't have yet), and its custom domains.
 */
class VercelApi
{
    public const URL = 'https://api.vercel.com';

    public function __construct(protected HostingAccount $account) {}

    /**
     * Make a project and return its id. Its vercel.app address is public: new projects otherwise ask visitors to sign
     * in to Vercel.
     *
     * @throws HostingException
     */
    public function createProject(string $name): string
    {
        $response = $this->check($this->send(fn (PendingRequest $http) => $http->post($this->path('/v11/projects'), [
            'name' => $name,
            'ssoProtection' => null,
        ])), "Couldn't make the Vercel project");

        return (string) $response->json('id');
    }

    /**
     * Set how Vercel builds the project, and keep its address public.
     *
     * @param  array{framework: string|null}  $settings
     *
     * @throws HostingException
     */
    public function configureProject(string $project, array $settings): void
    {
        $this->check($this->send(fn (PendingRequest $http) => $http->patch($this->path("/v9/projects/{$project}"), [
            'framework' => $settings['framework'],
            'buildCommand' => null,
            'installCommand' => null,
            'outputDirectory' => null,
            'ssoProtection' => null,
        ])), "Couldn't set up the Vercel project");
    }

    /**
     * Set (or replace) the production environment variables the app reads, as sensitive ones: Vercel never shows them
     * again.
     *
     * @param  array<string, string>  $variables
     *
     * @throws HostingException
     */
    public function setEnvironment(string $project, array $variables): void
    {
        if ($variables === []) {
            return;
        }

        $response = $this->check($this->send(fn (PendingRequest $http) => $http->post($this->path("/v10/projects/{$project}/env", ['upsert' => 'true']), array_map(
            fn (string $key, string $value) => ['key' => $key, 'value' => $value, 'type' => 'sensitive', 'target' => ['production']],
            array_keys($variables),
            $variables,
        ))), "Couldn't give the Vercel project its settings");

        $failed = $response->json('failed') ?? [];

        if ($failed !== []) {
            throw new HostingException("Couldn't give the Vercel project its settings: ".($failed[0]['error']['message'] ?? 'unknown error'));
        }
    }

    /**
     * Delete a project and its deployments. Deleting one that's gone is not an error.
     *
     * @throws HostingException
     */
    public function deleteProject(string $project): void
    {
        $response = $this->send(fn (PendingRequest $http) => $http->delete($this->path("/v9/projects/{$project}")));

        if ($response->status() !== 404) {
            $this->check($response, "Couldn't delete the Vercel project");
        }
    }

    /**
     * Make a production deployment of these files (paths relative to the app, with their local copies), sending only
     * the ones Vercel doesn't have yet. Returns its id.
     *
     * @param  list<array{file: string, path: string, sha: string, size: int}>  $files
     *
     * @throws HostingException
     */
    public function deploy(string $name, string $project, array $files): string
    {
        $body = [
            'name' => $name,
            'project' => $project,
            'target' => 'production',
            'files' => array_map(fn (array $file) => ['file' => $file['file'], 'sha' => $file['sha'], 'size' => $file['size']], $files),
        ];

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $response = $this->send(fn (PendingRequest $http) => $http->post($this->path('/v13/deployments', ['forceNew' => '1', 'skipAutoDetectionConfirmation' => '1']), $body));

            if ($response->json('error.code') !== 'missing_files') {
                return (string) $this->check($response, "Couldn't start the deployment on Vercel")->json('id');
            }

            $missing = array_flip($response->json('error.missing') ?? []);

            foreach ($files as $file) {
                if (isset($missing[$file['sha']])) {
                    $this->upload($file['path'], $file['sha'], $file['size']);
                    unset($missing[$file['sha']]);
                }
            }
        }

        throw new HostingException("Couldn't start the deployment on Vercel: it still wants files that were sent.");
    }

    /**
     * Where a deployment is: its state (QUEUED, INITIALIZING, BUILDING, READY, ERROR, CANCELED, BLOCKED), the error
     * when it failed, and its production address once that's assigned.
     *
     * @return array{state: string, error: string|null, url: string|null}
     *
     * @throws HostingException
     */
    public function deployment(string $id): array
    {
        $response = $this->check($this->send(fn (PendingRequest $http) => $http->get($this->path("/v13/deployments/{$id}"))), "Couldn't check the deployment on Vercel");
        $alias = $response->json('alias.0');

        return [
            'state' => (string) $response->json('readyState'),
            'error' => $response->json('errorMessage'),
            'url' => $response->json('aliasAssigned') && filled($alias) ? "https://{$alias}" : null,
        ];
    }

    /**
     * The end of a deployment's build log.
     *
     * @throws HostingException
     */
    public function buildLog(string $id, int $lines = 40): string
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get($this->path("/v3/deployments/{$id}/events", ['builds' => '1', 'limit' => '-1', 'direction' => 'forward'])));

        if ($response->failed()) {
            return '';
        }

        $events = $response->json();

        $text = collect(is_array($events) ? $events : [])
            ->filter(fn ($event) => is_array($event) && in_array($event['type'] ?? null, ['command', 'stdout', 'stderr', 'fatal'], true))
            ->map(fn (array $event) => (string) ($event['payload']['text'] ?? $event['text'] ?? ''))
            ->filter(fn (string $line) => trim($line) !== '');

        return $text->slice(-$lines)->implode("\n");
    }

    /**
     * Make an earlier production deployment the live one again, without building it (HOST-005).
     *
     * @throws HostingException
     */
    public function promote(string $project, string $deployment): void
    {
        $this->check($this->send(fn (PendingRequest $http) => $http->withBody('{}', 'application/json')->post($this->path("/v10/projects/{$project}/promote/{$deployment}"))), "Couldn't put that deployment back on Vercel");
    }

    /**
     * Add a custom domain to a project.
     *
     * @throws HostingException
     */
    public function addDomain(string $project, string $hostname): void
    {
        $response = $this->send(fn (PendingRequest $http) => $http->post($this->path("/v10/projects/{$project}/domains"), ['name' => $hostname]));

        // Already on this project.
        if ($response->failed() && $this->send(fn (PendingRequest $http) => $http->get($this->path("/v9/projects/{$project}/domains/{$hostname}")))->successful()) {
            return;
        }

        if ($response->status() === 409) {
            throw new HostingException(__(':host is on another Vercel project or account. Remove it there first.', ['host' => $hostname]));
        }

        $this->check($response, "Couldn't add the domain on Vercel");
    }

    /**
     * Whether a domain on the project serves it: null when it's on another project now, otherwise whether Vercel
     * verified it and its DNS points at Vercel, with the records Vercel recommends.
     *
     * @return array{verified: bool, misconfigured: bool, a: list<string>, cname: string|null}|null
     *
     * @throws HostingException
     */
    public function domain(string $project, string $hostname): ?array
    {
        $domain = $this->send(fn (PendingRequest $http) => $http->get($this->path("/v9/projects/{$project}/domains/{$hostname}")));

        if ($domain->status() === 404) {
            return null;
        }

        $this->check($domain, "Couldn't check the domain on Vercel");
        $config = $this->check($this->send(fn (PendingRequest $http) => $http->get($this->path("/v6/domains/{$hostname}/config", ['projectIdOrName' => $project]))), "Couldn't check the domain on Vercel");
        $best = function (string $key) use ($config): mixed {
            $records = $config->json($key);

            return collect(is_array($records) ? $records : [])->sortBy('rank')->first()['value'] ?? null;
        };

        return [
            'verified' => (bool) $domain->json('verified'),
            'misconfigured' => (bool) $config->json('misconfigured'),
            // The legacy addresses work everywhere; Vercel's newer ones don't from some networks.
            'a' => array_values((array) ($best('recommendedIPv4') ?? ['76.76.21.21'])),
            'cname' => $best('recommendedCNAME') ? rtrim((string) $best('recommendedCNAME'), '.') : 'cname.vercel-dns.com',
        ];
    }

    /**
     * Remove a custom domain from a project. Removing one that's gone is not an error.
     *
     * @throws HostingException
     */
    public function removeDomain(string $project, string $hostname): void
    {
        $response = $this->send(fn (PendingRequest $http) => $http->delete($this->path("/v9/projects/{$project}/domains/{$hostname}")));

        if ($response->status() !== 404) {
            $this->check($response, "Couldn't remove the domain on Vercel");
        }
    }

    /**
     * @throws HostingException
     */
    protected function upload(string $path, string $sha, int $size): void
    {
        $this->check($this->send(fn (PendingRequest $http) => $http
            ->withHeaders(['x-vercel-digest' => $sha, 'Content-Length' => (string) $size])
            ->withBody((string) file_get_contents($path), 'application/octet-stream')
            ->post($this->path('/v2/files'))), "Couldn't upload a file to Vercel");
    }

    /**
     * A path with these parameters, in the team's scope when it's a team account (Vercel otherwise uses the token
     * owner's own).
     *
     * @param  array<string, string>  $parameters
     */
    protected function path(string $path, array $parameters = []): string
    {
        $query = http_build_query(array_filter([...$parameters, 'teamId' => $this->account->get('team_id')]));

        return $query === '' ? $path : "{$path}?{$query}";
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     *
     * @throws HostingException
     */
    protected function send(callable $call): Response
    {
        try {
            return $call(Http::baseUrl(self::URL)
                ->withToken((string) $this->account->get('api_token'))
                ->acceptJson()
                ->timeout(120)
                ->retry(2, 1000, fn ($e) => $e instanceof ConnectionException, throw: false));
        } catch (ConnectionException $e) {
            throw new HostingException("Couldn't reach Vercel: {$e->getMessage()}", previous: $e);
        }
    }

    /**
     * @throws HostingException
     */
    protected function check(Response $response, string $what): Response
    {
        if ($response->failed()) {
            throw new HostingException($response->status() === 401
                ? "{$what}: Vercel didn't accept the access token."
                : "{$what}: ".($response->json('error.message') ?? trim(substr($response->body(), 0, 200))));
        }

        return $response;
    }
}
