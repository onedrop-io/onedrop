<?php

namespace App\Sandbox\Agents;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * OpenRouter's key management API, with the platform's management key: one key per organization for runs on
 * AI credits (CREDIT-001). https://openrouter.ai/docs/features/provisioning-api-keys
 */
class OpenRouterKeys
{
    public const URL = 'https://openrouter.ai/api/v1/keys';

    /**
     * Make a key that can spend at most $limit (USD, over its lifetime).
     *
     * @return array{key: string, hash: string}
     *
     * @throws ConnectionException|RequestException
     */
    public function create(string $name, float $limit): array
    {
        $response = $this->request()->post(self::URL, ['name' => $name, 'limit' => $limit])->throw();
        $key = $response->json('key') ?? $response->json('data.key');
        $hash = $response->json('data.hash') ?? $response->json('hash');

        if (! is_string($key) || $key === '' || ! is_string($hash) || $hash === '') {
            throw new RuntimeException('OpenRouter made a key but its response had no key or hash.');
        }

        return ['key' => $key, 'hash' => $hash];
    }

    /**
     * Change how much the key can spend in all.
     *
     * @throws ConnectionException|RequestException
     */
    public function setLimit(string $hash, float $limit): void
    {
        $this->request()->patch(self::URL.'/'.$hash, ['limit' => $limit])->throw();
    }

    /**
     * What the key has spent so far (USD).
     *
     * @throws ConnectionException|RequestException
     */
    public function usage(string $hash): float
    {
        return (float) $this->request()->get(self::URL.'/'.$hash)->throw()->json('data.usage');
    }

    protected function request(): PendingRequest
    {
        return Http::withToken((string) config('services.openrouter.provisioning_key'))->acceptJson()->timeout(15);
    }
}
