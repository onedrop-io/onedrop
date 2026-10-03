<?php

namespace App\Sandbox\Hosting;

use App\Sandbox\ExecResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Fly.io's Machines API (https://docs.machines.dev): apps, their public addresses, volumes and machines.
 */
class FlyApi
{
    public const URL = 'https://api.machines.dev/v1';

    public const REGISTRY = 'registry.fly.io';

    public function __construct(protected HostingAccount $account) {}

    /**
     * Make an app with public IPv4 (shared) and IPv6 addresses, so https://<name>.fly.dev reaches it. An app that's
     * already there (made by an earlier, cut-off deploy) is kept.
     *
     * @throws HostingException
     */
    public function createApp(string $name): void
    {
        $response = $this->send(fn (PendingRequest $http) => $http->post('/apps', ['name' => $name, 'org_slug' => $this->account->get('org_slug', 'personal')]));

        if ($response->failed() && ! str_contains($response->body(), 'name_taken')) {
            $this->fail($response, "Couldn't make the Fly app");
        }

        $existing = $this->send(fn (PendingRequest $http) => $http->get("/apps/{$name}/ip_assignments"))->collect('ips')->pluck('shared', 'ip');

        foreach (['shared_v4', 'v6'] as $type) {
            $has = $type === 'shared_v4' ? $existing->contains(true) : $existing->keys()->contains(fn (string $ip) => str_contains($ip, ':'));

            if (! $has) {
                $this->check($this->send(fn (PendingRequest $http) => $http->post("/apps/{$name}/ip_assignments", ['type' => $type])), "Couldn't give the Fly app an address");
            }
        }
    }

    /**
     * Delete an app and everything in it (machines, volumes). Deleting one that's gone is not an error.
     *
     * @throws HostingException
     */
    public function deleteApp(string $name): void
    {
        $response = $this->send(fn (PendingRequest $http) => $http->delete("/apps/{$name}?force=true"));

        if ($response->status() !== 404) {
            $this->check($response, "Couldn't delete the Fly app");
        }
    }

    /**
     * Make a volume, snapshotted daily by Fly, and return its id.
     *
     * @throws HostingException
     */
    public function createVolume(string $app, string $region, int $sizeGb): string
    {
        $response = $this->check($this->send(fn (PendingRequest $http) => $http->post("/apps/{$app}/volumes", [
            'name' => 'data',
            'region' => $region,
            'size_gb' => max(1, $sizeGb),
            'encrypted' => true,
            'auto_backup_enabled' => true,
            'snapshot_retention' => 14,
        ])), "Couldn't make the volume");

        return (string) $response->json('id');
    }

    /**
     * @throws HostingException
     */
    public function deleteVolume(string $app, string $volume): void
    {
        $response = $this->send(fn (PendingRequest $http) => $http->delete("/apps/{$app}/volumes/{$volume}"));

        if ($response->status() !== 404) {
            $this->check($response, "Couldn't delete the volume");
        }
    }

    /**
     * Start a machine and return its id.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws HostingException
     */
    public function createMachine(string $app, string $region, array $config, ?string $name = null): string
    {
        $response = $this->check($this->send(fn (PendingRequest $http) => $http->post("/apps/{$app}/machines", array_filter([
            'name' => $name,
            'region' => $region,
            'config' => $config,
        ]))), "Couldn't start a machine");

        return (string) $response->json('id');
    }

    /**
     * Replace a machine's whole config; it restarts with it.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws HostingException
     */
    public function updateMachine(string $app, string $machine, array $config): void
    {
        $this->check($this->send(fn (PendingRequest $http) => $http->post("/apps/{$app}/machines/{$machine}", ['config' => $config])), "Couldn't update the machine");
    }

    /**
     * A machine's state and its exit code once it has exited, or null when it's gone.
     *
     * @return array{state: string, exit_code: int|null, image: string|null}|null
     *
     * @throws HostingException
     */
    public function machine(string $app, string $machine): ?array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get("/apps/{$app}/machines/{$machine}"));

        if ($response->status() === 404) {
            return null;
        }

        $this->check($response, "Couldn't read the machine");
        $exit = $response->collect('events')->first(fn (array $event) => isset($event['request']['exit_event']));

        return [
            'state' => (string) $response->json('state'),
            'exit_code' => isset($exit['request']['exit_event']['exit_code']) ? (int) $exit['request']['exit_event']['exit_code'] : null,
            'image' => $response->json('config.image'),
        ];
    }

    /**
     * Wake a machine that went to sleep (suspended or stopped when idle), and wait until it's started.
     *
     * @throws HostingException
     */
    public function wake(string $app, string $machine): void
    {
        $state = $this->machine($app, $machine)['state'] ?? null;

        if ($state === null) {
            throw new HostingException("The app's machine is gone. Publish it again.");
        }

        if ($state === 'started') {
            return;
        }

        $this->check($this->send(fn (PendingRequest $http) => $http->post("/apps/{$app}/machines/{$machine}/start")), "Couldn't wake the app");
        $this->check($this->send(fn (PendingRequest $http) => $http->timeout(70)->get("/apps/{$app}/machines/{$machine}/wait", ['state' => 'started', 'timeout' => 60])), "The app didn't wake up");
    }

    /**
     * Run a short command on a machine, with $stdin as its input.
     *
     * @param  list<string>  $command
     *
     * @throws HostingException
     */
    public function exec(string $app, string $machine, array $command, string $stdin = '', int $timeout = 30): ExecResult
    {
        $response = $this->check($this->send(fn (PendingRequest $http) => $http->timeout($timeout + 15)->post("/apps/{$app}/machines/{$machine}/exec", array_filter([
            'command' => $command,
            'stdin' => $stdin,
            'timeout' => $timeout,
        ], fn ($value) => $value !== ''))), "Couldn't run a command on the app");

        return new ExecResult((int) $response->json('exit_code'), (string) $response->json('stdout'), (string) $response->json('stderr'));
    }

    /**
     * Stop and delete a machine. Deleting one that's gone is not an error.
     *
     * @throws HostingException
     */
    public function deleteMachine(string $app, string $machine): void
    {
        $response = $this->send(fn (PendingRequest $http) => $http->delete("/apps/{$app}/machines/{$machine}?force=true"));

        if ($response->status() !== 404) {
            $this->check($response, "Couldn't delete the machine");
        }
    }

    /**
     * The token as Fly's registry wants it (user "x").
     */
    public function token(): string
    {
        return (string) $this->account->get('api_token');
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     *
     * @throws HostingException
     */
    protected function send(callable $call): Response
    {
        $token = $this->token();

        try {
            return $call(Http::baseUrl(self::URL)
                ->withHeaders(['Authorization' => str_starts_with($token, 'FlyV1 ') ? $token : "Bearer {$token}"])
                ->acceptJson()
                ->timeout(60));
        } catch (ConnectionException $e) {
            throw new HostingException("Couldn't reach Fly.io: {$e->getMessage()}", previous: $e);
        }
    }

    /**
     * @throws HostingException
     */
    protected function check(Response $response, string $what): Response
    {
        if ($response->failed()) {
            $this->fail($response, $what);
        }

        return $response;
    }

    /**
     * @throws HostingException
     */
    protected function fail(Response $response, string $what): never
    {
        $reason = $response->json('error') ?? $response->json('message') ?? trim(substr($response->body(), 0, 200));

        throw new HostingException(match ($response->status()) {
            401, 403 => "{$what}: Fly.io didn't accept the API token.",
            default => "{$what}: {$reason}",
        });
    }
}
