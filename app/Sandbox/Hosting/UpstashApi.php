<?php

namespace App\Sandbox\Hosting;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Upstash's Developer API (https://upstash.com/docs/devops/developer-api): one Redis database per hosted app.
 */
class UpstashApi
{
    public const URL = 'https://api.upstash.com/v2';

    public function __construct(protected HostingAccount $account) {}

    /**
     * Make a database and return its id and rediss:// URL.
     *
     * @return array{id: string, url: string}
     *
     * @throws HostingException
     */
    public function createDatabase(string $name): array
    {
        $response = $this->check($this->send(fn (PendingRequest $http) => $http->post('/redis/database', [
            'database_name' => $name,
            'platform' => 'aws',
            'primary_region' => $this->account->get('region', 'us-east-1'),
            'tls' => true,
        ])), "Couldn't make the Redis database");

        $host = (string) $response->json('endpoint');
        $host = str_contains($host, '.') ? $host : "{$host}.upstash.io";

        return [
            'id' => (string) $response->json('database_id'),
            'url' => sprintf('rediss://default:%s@%s:%d', $response->json('password'), $host, (int) ($response->json('port') ?: 6379)),
        ];
    }

    /**
     * Deleting one that's gone is not an error.
     *
     * @throws HostingException
     */
    public function deleteDatabase(string $id): void
    {
        $response = $this->send(fn (PendingRequest $http) => $http->delete("/redis/database/{$id}"));

        if ($response->status() !== 404) {
            $this->check($response, "Couldn't delete the Redis database");
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
            return $call(Http::baseUrl(self::URL)
                ->withBasicAuth((string) $this->account->get('email'), (string) $this->account->get('api_key'))
                ->acceptJson()
                ->timeout(60));
        } catch (ConnectionException $e) {
            throw new HostingException("Couldn't reach Upstash: {$e->getMessage()}", previous: $e);
        }
    }

    /**
     * @throws HostingException
     */
    protected function check(Response $response, string $what): Response
    {
        if ($response->failed()) {
            throw new HostingException(in_array($response->status(), [401, 403], true)
                ? "{$what}: Upstash didn't accept the email and API key."
                : "{$what}: ".trim(substr($response->body(), 0, 200)));
        }

        return $response;
    }
}
