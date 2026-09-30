<?php

namespace App\Sandbox;

use App\Models\Sandbox;

/**
 * The app's secrets: the variables in its .env, reached through docker/sandbox/secrets.php
 * inside the sandbox. Values are only read on request and never stored by the platform.
 */
class WorkspaceSecrets
{
    public const SCRIPT = '/opt/onedrop/secrets.php';

    public function __construct(protected SandboxProvider $provider) {}

    /**
     * The secrets' names, in the order they appear in .env.
     *
     * @return list<string>
     *
     * @throws SandboxException|SecretsException
     */
    public function names(Sandbox $sandbox): array
    {
        return $this->namesFrom($this->call($sandbox, ['op' => 'list']));
    }

    /**
     * @throws SandboxException|SecretsException
     */
    public function reveal(Sandbox $sandbox, string $name): string
    {
        return (string) $this->call($sandbox, ['op' => 'reveal', 'name' => $name])['value'];
    }

    /**
     * Add secrets in one write, then restart the app to use them. Names that already
     * exist are refused unless they're in $replace.
     *
     * @param  array<string, string>  $secrets  name => value
     * @param  array<array-key, string>  $replace  existing names whose values may be overwritten
     * @return list<string>
     *
     * @throws SandboxException|SecretsException
     */
    public function set(Sandbox $sandbox, array $secrets, array $replace = []): array
    {
        $request = [
            'op' => 'set-many',
            'secrets' => array_map(fn (string $name, string $value) => ['name' => $name, 'value' => $value], array_keys($secrets), $secrets),
            'replace' => array_values($replace),
        ];

        if (strlen(json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) > WorkspaceDatabase::MAX_REQUEST_BYTES) {
            throw new SecretsException(__("That's too much to save at once. Add the secrets in smaller batches."));
        }

        $names = $this->namesFrom($this->call($sandbox, $request));

        $this->provider->exec($sandbox->external_id, ['/opt/onedrop/restart']);

        return $names;
    }

    /**
     * @return list<string>
     *
     * @throws SandboxException|SecretsException
     */
    public function delete(Sandbox $sandbox, string $name): array
    {
        $names = $this->namesFrom($this->call($sandbox, ['op' => 'delete', 'name' => $name]));

        $this->provider->exec($sandbox->external_id, ['/opt/onedrop/restart']);

        return $names;
    }

    /**
     * @param  array{secrets: list<array{name: string}>}  $data
     * @return list<string>
     */
    protected function namesFrom(array $data): array
    {
        return array_column($data['secrets'], 'name');
    }

    /**
     * @param  array<string, mixed>  $request
     *
     * @throws SandboxException|SecretsException
     */
    protected function call(Sandbox $sandbox, array $request): mixed
    {
        $result = $this->provider->exec($sandbox->external_id, ['php', self::SCRIPT], [
            'APP_SECRETS_REQUEST' => json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
        $response = json_decode(trim($result->output), true);

        // Sandboxes built before an operation existed answer "Unknown operation."
        if (! is_array($response) || ! isset($response['ok']) || ($response['error'] ?? null) === 'Unknown operation.') {
            throw new SandboxException(__("This sandbox's Secrets tool is missing or out of date. Rebuild the sandbox image and recreate the sandbox."));
        }

        if ($response['ok'] !== true) {
            throw new SecretsException((string) ($response['error'] ?? __('The Secrets request failed.')));
        }

        return $response['data'];
    }
}
