<?php

namespace App\Sandbox\Agents;

use App\Enums\CredentialType;
use App\Models\AgentConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * The user's own Ollama server (AI-006): checks it's reachable, lists its models, builds the OpenCode
 * config that points the agent at it, and asks Nimble there in Jev's place (AI-007). The platform calls a URL the user typed, so it refuses private and
 * internal addresses (unless sandbox.ollama_private_servers allows them, as on a local install) and pins
 * the request to the address it checked.
 */
class OllamaServer
{
    /**
     * The server's base URL as typed, without a trailing "/", "/v1" or "/api".
     */
    public static function normalize(string $url): string
    {
        return (string) preg_replace('#/(v1|api)$#', '', rtrim(trim($url), '/'));
    }

    /**
     * Check the server answers (with the key, if it needs one) and return its model names.
     *
     * @return list<string>
     *
     * @throws ValidationException when it can't be reached, refuses the key, or isn't an Ollama server
     */
    public function check(string $url, ?string $key): array
    {
        try {
            $response = $this->tags($url, $key);
        } catch (ConnectionException) {
            throw ValidationException::withMessages([
                'url' => __("Couldn't reach :host. Make sure your Ollama server can be reached from the internet.", ['host' => parse_url($url, PHP_URL_HOST)]),
            ]);
        }

        if ($response->unauthorized() || $response->forbidden()) {
            throw ValidationException::withMessages([
                'credential' => $key ? __('Your Ollama server rejected that key.') : __('Your Ollama server needs a key.'),
            ]);
        }

        $models = $response->successful() ? $response->json('models') : null;

        if (! is_array($models)) {
            throw ValidationException::withMessages([
                'url' => __("That doesn't look like an Ollama server: :url/api/tags didn't list any models.", ['url' => $url]),
            ]);
        }

        return $this->names($models);
    }

    /**
     * The models on the user's server, cached for an hour (five minutes after a failed fetch).
     *
     * @return list<string>
     */
    public function models(AgentConnection $connection): array
    {
        $key = "ollama-models:{$connection->id}:".md5($connection->credential);
        $cached = Cache::get($key);

        if (is_array($cached) && array_is_list($cached)) {
            return $cached;
        }

        ['url' => $url, 'key' => $apiKey] = $connection->ollamaServer();

        try {
            $response = $this->tags($url, $apiKey);
            $models = $response->successful() && is_array($response->json('models')) ? $this->names($response->json('models')) : null;
        } catch (ConnectionException|ValidationException) {
            $models = null;
        }

        Cache::put($key, $models ?? [], $models === null ? now()->addMinutes(5) : now()->addHour());

        return $models ?? [];
    }

    /**
     * Environment for a sandbox: the key (Ollama ignores a placeholder when the server has none) and an
     * OpenCode config that points its ollama-cloud provider at the user's server, with that server's models
     * at no cost. In Docker sandboxes, "localhost" is the user's machine, reached as host.docker.internal.
     *
     * @return array{OLLAMA_API_KEY: string, OPENCODE_CONFIG_CONTENT: string}
     */
    public function sandboxEnvironment(AgentConnection $connection): array
    {
        ['url' => $url, 'key' => $key] = $connection->ollamaServer();

        if (config('sandbox.provider') === 'docker') {
            $url = (string) preg_replace('#^(https?://)(localhost|127\.0\.0\.1|\[::1\])(?=[:/]|$)#i', '$1host.docker.internal', $url);
        }

        $models = collect($this->models($connection))
            ->mapWithKeys(fn (string $model) => [$model => ['name' => $model, 'tool_call' => true, 'cost' => ['input' => 0, 'output' => 0]]])
            ->all();

        return [
            'OLLAMA_API_KEY' => $key ?: 'ollama',
            'OPENCODE_CONFIG_CONTENT' => json_encode([
                'provider' => [
                    'ollama-cloud' => [
                        'name' => 'Ollama',
                        'options' => ['baseURL' => "{$url}/v1"],
                        'models' => (object) $models,
                    ],
                ],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * The name Nimble has on the user's server (e.g. "nimble:latest"), or null when it isn't pulled there (AI-007).
     */
    public function nimbleModel(AgentConnection $connection): ?string
    {
        return collect($this->models($connection))->first(fn (string $model) => $model === 'nimble' || str_starts_with($model, 'nimble:'));
    }

    /**
     * Ask Nimble on the user's server (its /v1/systemone takes Jev's request as-is), through the same address guard.
     *
     * @param  array<string, mixed>  $body
     *
     * @throws ConnectionException|ValidationException
     */
    public function decide(AgentConnection $connection, array $body, int $timeout): Response
    {
        ['url' => $url, 'key' => $key] = $connection->ollamaServer();

        return $this->request($url, $key, $timeout)->post("{$url}/v1/systemone", $body);
    }

    /**
     * Whether this connection is the user's own server rather than an Ollama Cloud key.
     */
    public static function isServer(?AgentConnection $connection): bool
    {
        return $connection?->credential_type === CredentialType::OllamaServer;
    }

    /**
     * GET the server's model list.
     *
     * @throws ConnectionException|ValidationException
     */
    protected function tags(string $url, ?string $key): Response
    {
        return $this->request($url, $key)->get("{$url}/api/tags");
    }

    /**
     * A request to the server, pinned to an address that passed the guard, without following redirects.
     *
     * @throws ValidationException
     */
    protected function request(string $url, ?string $key, int $timeout = 10): PendingRequest
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT) ?? (parse_url($url, PHP_URL_SCHEME) === 'https' ? 443 : 80);
        $address = $this->allowedAddress($host);

        $request = Http::timeout($timeout)->acceptJson()->withoutRedirecting()
            ->withOptions(['curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$address}"]]]);

        return $key ? $request->withToken($key) : $request;
    }

    /**
     * The address to connect to for this host, refusing private, loopback and reserved ones.
     *
     * @throws ValidationException
     */
    protected function allowedAddress(string $host): string
    {
        $bare = trim($host, '[]');
        $addresses = filter_var($bare, FILTER_VALIDATE_IP) ? [$bare] : $this->resolve($bare);

        if ($addresses === []) {
            throw ValidationException::withMessages(['url' => __("Couldn't find :host.", ['host' => $host])]);
        }

        if (! config('sandbox.ollama_private_servers')) {
            foreach ($addresses as $address) {
                if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                    throw ValidationException::withMessages([
                        'url' => __('Use a public address for your Ollama server: your sandboxes run on a server and can\'t reach private networks.'),
                    ]);
                }
            }
        }

        return str_contains($addresses[0], ':') ? "[{$addresses[0]}]" : $addresses[0];
    }

    /**
     * @return list<string>
     */
    protected function resolve(string $host): array
    {
        return gethostbynamel($host) ?: [];
    }

    /**
     * @param  array<mixed>  $models
     * @return list<string>
     */
    protected function names(array $models): array
    {
        return array_values(array_filter(array_map(fn ($model) => is_array($model) ? ($model['name'] ?? $model['model'] ?? null) : null, $models), 'is_string'));
    }
}
