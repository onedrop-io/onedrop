<?php

namespace App\Sandbox\Providers;

use App\Sandbox\ExecResult;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxProvider;
use App\Sandbox\SandboxSpec;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Sandboxes on a user's own computer (DESK-010): the desktop app runs them in the computer's Docker, and the app
 * reaches it through the preview gateway Worker's device relay (docs/development/desktop-link.mdx). A sandbox's id
 * names its computer: "<desktop sign-in id>:<container>".
 */
class DeviceSandboxProvider implements SandboxProvider
{
    /** How preview and Shell addresses of these sandboxes start; the Worker hands them to the computer's relay. */
    public const SCHEME = 'device:';

    /**
     * @param  array{image: string, relay_url: ?string, timeout: int, gateway_domain: ?string, gateway_secret: ?string}  $config
     */
    public function __construct(protected array $config) {}

    /**
     * Whether this install can run projects on computers: its gateway runs on Cloudflare, where the relay lives.
     */
    public static function available(): bool
    {
        return filled(config('sandbox.gateway_secret')) && filled(config('sandbox.gateway_domain'));
    }

    /**
     * Where the Worker serves the relay.
     */
    public static function relayUrl(): string
    {
        return rtrim((string) (config('sandbox.providers.device.relay_url') ?: 'https://relay.'.config('sandbox.gateway_domain')), '/');
    }

    /**
     * Split a sandbox id into its computer and container.
     *
     * @return array{device: int, container: string}
     *
     * @throws SandboxException
     */
    public static function parse(string $id): array
    {
        if (! preg_match('/^(\d+):(onedrop-[a-z0-9-]+)$/', $id, $matches)) {
            throw new SandboxException("[{$id}] isn't a sandbox on a computer.");
        }

        return ['device' => (int) $matches[1], 'container' => $matches[2]];
    }

    public function create(SandboxSpec $spec): string
    {
        if ($spec->deviceId === null) {
            throw new SandboxException('A sandbox on a computer needs the computer.');
        }

        $created = $this->call($spec->deviceId, 'create', [
            'name' => $spec->name,
            'image' => $this->config['image'],
            'env' => (object) $spec->env,
            'ports' => array_filter([
                'app' => $spec->port,
                'proxy' => $spec->proxyPort,
                'shell' => $spec->shellPort,
                'ssh' => $spec->sshPort,
            ]),
        ])->json('id');

        if (! is_string($created) || ! str_starts_with($created, 'onedrop-')) {
            throw new SandboxException("The computer didn't say which container it made.");
        }

        return "{$spec->deviceId}:{$created}";
    }

    public function start(string $id): void
    {
        $this->container($id, 'start');
    }

    public function pause(string $id): void
    {
        $this->container($id, 'stop');
    }

    public function suspend(string $id): void
    {
        $this->container($id, 'suspend');
    }

    public function wake(string $id): bool
    {
        return (bool) $this->container($id, 'wake')->json('woke');
    }

    public function holdAwake(string $id): void {}

    public function releaseAwake(string $id, bool $soon): void {}

    public function exec(string $id, array $command, array $env = [], bool $detach = false, bool $root = false): ExecResult
    {
        ['device' => $device, 'container' => $container] = self::parse($id);

        $response = $this->call($device, 'exec', array_filter([
            'id' => $container,
            'command' => $command,
            'env' => (object) $env,
            'detach' => $detach,
            'user' => $root ? 'root' : null,
        ], fn (mixed $value) => $value !== null));

        return new ExecResult((int) $response->json('exit', 1), (string) $response->json('stdout', ''), (string) $response->json('stderr', ''));
    }

    public function previewUrl(string $id, int $port): ?string
    {
        ['device' => $device, 'container' => $container] = self::parse($id);

        return self::SCHEME."{$device}/{$container}:{$port}";
    }

    /**
     * The desktop app pulls the image itself.
     */
    public function checkImage(): void {}

    public function isOutdated(string $id): bool
    {
        ['device' => $device, 'container' => $container] = self::parse($id);

        return (bool) $this->call($device, 'outdated', ['id' => $container, 'image' => $this->config['image']])->json('outdated');
    }

    public function copyOut(string $id, string $path, string $directory): void
    {
        ['device' => $device, 'container' => $container] = self::parse($id);
        $archive = tempnam(sys_get_temp_dir(), 'onedrop-device-');

        try {
            $this->send($device, fn (PendingRequest $request) => $request->sink($archive)
                ->post($this->path($device, 'copy-out').'?'.http_build_query(['id' => $container, 'path' => $path])));

            // An empty answer: the path doesn't exist there, so there's nothing to copy.
            if (filesize($archive) === 0) {
                return;
            }

            $result = Process::forever()->run(['tar', '-xf', $archive, '-C', $directory]);

            if ($result->failed()) {
                throw new SandboxException("Couldn't unpack {$path} from the computer: ".strtok(trim($result->errorOutput()), "\n"));
            }
        } finally {
            @unlink($archive);
        }
    }

    public function copyIn(string $id, string $directory, string $path): void
    {
        $this->copy($id, $directory, $path, root: false);
    }

    public function installFiles(string $id, string $directory, string $path): bool
    {
        $this->copy($id, $directory, $path, root: true);

        return true;
    }

    public function destroy(string $id): void
    {
        $this->container($id, 'destroy');
    }

    /**
     * @throws SandboxException
     */
    protected function copy(string $id, string $directory, string $path, bool $root): void
    {
        ['device' => $device, 'container' => $container] = self::parse($id);
        $archive = tempnam(sys_get_temp_dir(), 'onedrop-device-');

        try {
            $packed = Process::forever()->run(['tar', '-cf', $archive, '-C', $directory, '.']);

            if ($packed->failed()) {
                throw new SandboxException("Couldn't pack {$directory}: ".strtok(trim($packed->errorOutput()), "\n"));
            }

            $body = Utils::streamFor(fopen($archive, 'r'));

            try {
                $this->send($device, fn (PendingRequest $request) => $request
                    ->withBody($body, 'application/x-tar')
                    ->post($this->path($device, 'copy-in').'?'.http_build_query(['id' => $container, 'path' => $path, 'root' => $root ? 1 : 0])));
            } finally {
                $body->close();
            }
        } finally {
            @unlink($archive);
        }
    }

    /**
     * Start, stop, suspend, wake or remove the container.
     *
     * @throws SandboxException
     */
    protected function container(string $id, string $action): Response
    {
        ['device' => $device, 'container' => $container] = self::parse($id);

        return $this->call($device, $action, ['id' => $container]);
    }

    /**
     * One JSON call to the computer.
     *
     * @param  array<string, mixed>  $body
     *
     * @throws SandboxException
     */
    protected function call(int $device, string $operation, array $body): Response
    {
        return $this->send($device, fn (PendingRequest $request) => $request->post($this->path($device, $operation), $body));
    }

    /**
     * @param  callable(PendingRequest): Response  $send
     *
     * @throws SandboxException
     */
    protected function send(int $device, callable $send): Response
    {
        try {
            $response = $send(Http::withHeaders(['X-OneDrop-Gateway-Secret' => (string) $this->config['gateway_secret']])
                ->acceptJson()
                ->timeout($this->config['timeout']));
        } catch (ConnectionException $e) {
            throw new SandboxException("Couldn't reach OneDrop's relay: {$e->getMessage()}");
        }

        if ($response->status() === 503 && $response->json('error') === 'offline') {
            throw new SandboxException(__('Waiting for :computer: open the OneDrop app there.', ['computer' => self::name($device)]));
        }

        if ($response->failed()) {
            throw new SandboxException(__('The computer said: :message', ['message' => $response->json('message') ?? $response->json('error') ?? "HTTP {$response->status()}"]));
        }

        return $response;
    }

    protected function path(int $device, string $operation): string
    {
        return self::relayUrl()."/__onedrop/devices/{$device}/rpc/{$operation}";
    }

    /**
     * What the computer is called: its desktop app sign-in's name.
     */
    public static function name(int $device): string
    {
        return PersonalAccessToken::query()->whereKey($device)->value('name') ?? __('your computer');
    }
}
