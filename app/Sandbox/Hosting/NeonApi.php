<?php

namespace App\Sandbox\Hosting;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Neon's API (https://api-docs.neon.tech): one Neon project, with its own Postgres, per hosted app.
 */
class NeonApi
{
    public const URL = 'https://console.neon.tech/api/v2';

    public function __construct(protected HostingAccount $account) {}

    /**
     * Make a project and return its id and connection URL. Its compute sleeps when idle (Neon's default), like the
     * app's machine, so an app nobody visits costs only its storage.
     *
     * @return array{id: string, url: string}
     *
     * @throws HostingException
     */
    public function createProject(string $name): array
    {
        $project = array_filter([
            'name' => $name,
            'region_id' => $this->account->get('region', 'aws-us-east-1'),
            'org_id' => $this->account->get('org_id'),
        ]);

        $response = $this->check($this->send(fn (PendingRequest $http) => $http->post('/projects', ['project' => $project])), "Couldn't make the Postgres database");

        return [
            'id' => (string) $response->json('project.id'),
            'url' => (string) $response->json('connection_uris.0.connection_uri'),
        ];
    }

    /**
     * Delete a project and its data (Neon keeps it recoverable for a week). Deleting one that's gone is not an error.
     *
     * @throws HostingException
     */
    public function deleteProject(string $id): void
    {
        $response = $this->send(fn (PendingRequest $http) => $http->delete("/projects/{$id}"));

        if ($response->status() !== 404) {
            $this->check($response, "Couldn't delete the Postgres database");
        }
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     *
     * @throws HostingException
     */
    protected function send(callable $call): Response
    {
        try {
            return $call(Http::baseUrl(self::URL)->withToken((string) $this->account->get('api_key'))->acceptJson()->timeout(60));
        } catch (ConnectionException $e) {
            throw new HostingException("Couldn't reach Neon: {$e->getMessage()}", previous: $e);
        }
    }

    /**
     * @throws HostingException
     */
    protected function check(Response $response, string $what): Response
    {
        if ($response->failed()) {
            throw new HostingException(in_array($response->status(), [401, 403], true)
                ? "{$what}: Neon didn't accept the API key."
                : "{$what}: ".($response->json('message') ?? trim(substr($response->body(), 0, 200))));
        }

        return $response;
    }
}
