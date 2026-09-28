<?php

namespace App\Sandbox\Providers;

use App\Sandbox\ExecResult;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxProvider;
use App\Sandbox\SandboxSpec;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

/**
 * Blaxel (blaxel.ai) microVM sandboxes. The control plane (api.blaxel.ai) creates them and their previews;
 * each sandbox's own API (its metadata.url) runs commands and moves files. The id is the sandbox's name.
 * The image is docker/sandbox plus docker/sandbox/blaxel, pushed by `php artisan sandbox:build-image`.
 */
class BlaxelSandboxProvider implements SandboxProvider
{
    /** Label holding the image a sandbox was made from (for isOutdated()). */
    public const IMAGE_LABEL = 'zap-image';

    /** How long a private preview token lasts; ProjectController renews the links daily. */
    public const PREVIEW_TOKEN_DAYS = 7;

    /** How long pause() may leave the app frozen if start() is never called: the longest an update may run. */
    public const THAW_AFTER_SECONDS = 900;

    /** Upload part size for copyIn(). */
    protected const PART_BYTES = 50 * 1024 * 1024;

    /** @var array<string, string> sandbox name => its API url */
    protected array $urls = [];

    /**
     * @param  array{api_key: ?string, workspace: ?string, url: string, image: string, memory_mib: int, region: ?string}  $config
     */
    public function __construct(protected array $config) {}

    public function create(SandboxSpec $spec): string
    {
        $image = $this->currentImage();
        $ports = array_filter([
            'app' => $spec->port,
            'proxy' => $spec->proxyPort,
            'shell' => $spec->shellPort,
        ]);

        $sandbox = $this->createOrCleanUp($spec->name, [
            'metadata' => [
                'name' => $spec->name,
                'labels' => [self::IMAGE_LABEL => $image],
            ],
            'spec' => array_filter([
                'region' => $this->config['region'] ?: null,
                'runtime' => [
                    'image' => $image,
                    'memory' => $this->config['memory_mib'],
                    'ports' => collect($ports)->map(fn (int $port, string $name) => ['name' => $name, 'target' => $port, 'protocol' => 'HTTP'])->values()->all(),
                    // Secret values are never shown back by the API or the console.
                    'envs' => collect([
                        // Blaxel sets PORT=80 in every process; the entrypoint gives start.sh this one instead.
                        'ZAP_PORT' => (string) $spec->port,
                        ...($spec->proxyPort ? ['PROXY_PORT' => (string) $spec->proxyPort] : []),
                        ...($spec->shellPort ? ['SHELL_PORT' => (string) $spec->shellPort] : []),
                        ...$spec->env,
                    ])->map(fn (string $value, string $name) => ['name' => $name, 'value' => $value, 'secret' => array_key_exists($name, $spec->env)])->values()->all(),
                ],
            ]),
        ]);

        $name = $sandbox['metadata']['name'] ?? $spec->name;

        try {
            $this->waitUntilDeployed($name);
        } catch (SandboxException $e) {
            $this->destroy($name);

            throw $e;
        }

        return $name;
    }

    /**
     * Let processes frozen by pause() carry on. Blaxel wakes sandboxes from standby by itself.
     */
    public function start(string $id): void
    {
        $this->exec($id, ['pkill', '-CONT', '-u', 'sandbox']);
    }

    /**
     * Ask Blaxel for the sandbox. A refused or lost answer may still have made one, so delete it by name then.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws SandboxException
     */
    protected function createOrCleanUp(string $name, array $body): array
    {
        try {
            return $this->send('post', 'sandboxes', $body);
        } catch (SandboxException $e) {
            try {
                $this->destroy($name);
            } catch (SandboxException) {
                // Nothing more to do; report the original error.
            }

            throw $e;
        }
    }

    /**
     * Blaxel puts idle sandboxes on standby by itself, so pausing freezes the app's processes instead: files are
     * then at rest while an update copies them (a database is copied as after a power cut, which it recovers from).
     */
    public function pause(string $id): void
    {
        $frozen = $this->exec($id, ['bash', '-c', 'for pid in $(pgrep -u sandbox); do [ "$pid" = $$ ] || [ "$pid" = "$PPID" ] || kill -STOP "$pid" 2>/dev/null; done; exit 0']);

        if (! $frozen->successful()) {
            throw new SandboxException("Couldn't pause the sandbox's processes: ".(strtok(trim($frozen->errorOutput), "\n") ?: 'unknown error'));
        }

        // If whoever paused it dies before calling start() (e.g. a deploy replaced the worker), the app still
        // carries on by itself once an update could no longer be running.
        $this->sandboxSend($id, 'post', 'process', [
            'command' => 'sleep '.self::THAW_AFTER_SECONDS.'; pkill -CONT -u sandbox',
            'workingDir' => '/workspace',
            'waitForCompletion' => false,
        ]);
    }

    public function exec(string $id, array $command, array $env = [], bool $detach = false): ExecResult
    {
        // The sandbox API takes one shell command; env goes in the body, never the command line.
        // Blaxel sets PORT=80 in every process, so put the app's port back (agents and scripts read it).
        $body = array_filter([
            'command' => implode(' ', array_map('escapeshellarg', $command)),
            'env' => ['PORT' => (string) config('sandbox.port'), ...$env],
            'workingDir' => '/workspace',
        ]);

        if ($detach) {
            // Keep the sandbox out of standby while it runs (agent runs have no open connection), with no time limit.
            $this->sandboxSend($id, 'post', 'process', [...$body, 'waitForCompletion' => false, 'keepAlive' => true, 'timeout' => 0]);

            return new ExecResult(0, '');
        }

        $response = $this->sandboxRequest($id, 'post', 'process', [...$body, 'waitForCompletion' => true, 'timeout' => 120], timeout: 150);

        // Past the timeout the call answers 422 and the process keeps running.
        if ($response->status() === 422 && str_contains(strtolower((string) $response->body()), 'timeout')) {
            return new ExecResult(124, '', 'Timed out after 120 seconds.');
        }

        $result = $this->throwUnlessOk($response)->json();

        return new ExecResult((int) ($result['exitCode'] ?? 1), (string) ($result['stdout'] ?? ''), (string) ($result['stderr'] ?? ''));
    }

    public function previewUrl(string $id, int $port): ?string
    {
        // Previews are HTTPS only; SSH has no address here (the Developer → SSH page says so).
        if ($port === (int) config('sandbox.ssh_port')) {
            return null;
        }

        $name = "port-{$port}";
        $created = $this->request('post', "sandboxes/{$id}/previews", [
            'metadata' => ['name' => $name],
            'spec' => ['port' => $port, 'public' => false],
        ]);

        // Already made on an earlier call: use it.
        $preview = $created->status() === 409
            ? $this->send('get', "sandboxes/{$id}/previews/{$name}")
            : $this->throwUnlessOk($created)->json();

        $url = $preview['spec']['url'] ?? null;

        if (! $url) {
            return null;
        }

        // Private: the link carries a token, and the app hands it only to people who may see the project.
        $token = $this->send('post', "sandboxes/{$id}/previews/{$name}/tokens", [
            'spec' => ['expiresAt' => now()->addDays(self::PREVIEW_TOKEN_DAYS)->toIso8601ZuluString()],
        ]);

        return rtrim($url, '/').'/?'.http_build_query(['bl_preview_token' => $token['spec']['token'] ?? '']);
    }

    public function isOutdated(string $id): bool
    {
        $sandbox = $this->request('get', "sandboxes/{$id}");

        try {
            $current = $this->currentImage();
        } catch (SandboxException) {
            return false;
        }

        // No sandbox: nothing to update.
        if ($sandbox->failed()) {
            return false;
        }

        return ($sandbox->json('metadata.labels')[self::IMAGE_LABEL] ?? null) !== $current;
    }

    public function copyOut(string $id, string $path, string $directory): void
    {
        $archive = '/tmp/zap-copy-'.Str::random(8).'.tgz';
        $packed = $this->exec($id, ['bash', '-c', 'test -d "$1" || exit 3; tar -czf "$2" -C "$1" .', 'pack', $path, $archive]);

        // A path the sandbox never created (e.g. no App Storage yet) has nothing to copy.
        if ($packed->exitCode === 3) {
            return;
        }

        if (! $packed->successful()) {
            throw new SandboxException("Couldn't copy {$path} out of the sandbox: ".(strtok(trim($packed->errorOutput), "\n") ?: 'tar failed'));
        }

        $local = tempnam(sys_get_temp_dir(), 'zap-copy-');

        try {
            $this->throwUnlessOk($this->sandboxClient($id)->timeout(600)->sink($local)
                ->withHeaders(['Accept' => 'application/octet-stream'])
                ->get('filesystem/'.$archive, ['download' => 'true']));

            $result = Process::forever()->run(['tar', '-xzf', $local, '-C', $directory]);

            if ($result->failed()) {
                throw new SandboxException("Couldn't unpack {$path} from the sandbox: ".strtok(trim($result->errorOutput()), "\n"));
            }
        } finally {
            @unlink($local);
            $this->exec($id, ['rm', '-f', $archive]);
        }
    }

    public function copyIn(string $id, string $directory, string $path): void
    {
        $local = tempnam(sys_get_temp_dir(), 'zap-copy-');
        $archive = '/tmp/zap-copy-'.Str::random(8).'.tgz';

        try {
            $result = Process::forever()->run(['tar', '-czf', $local, '-C', $directory, '.']);

            if ($result->failed()) {
                throw new SandboxException("Couldn't pack {$directory}: ".strtok(trim($result->errorOutput()), "\n"));
            }

            $this->upload($id, $local, $archive);
        } finally {
            @unlink($local);
        }

        // The sandbox API runs everything as the sandbox user, so what it unpacks is already the sandbox user's.
        $unpacked = $this->exec($id, ['bash', '-c', 'mkdir -p "$1" && tar -xzf "$2" -C "$1"; status=$?; rm -f "$2"; exit $status', 'unpack', $path, $archive]);

        if (! $unpacked->successful()) {
            throw new SandboxException("Couldn't copy files into {$path} in the sandbox: ".(strtok(trim($unpacked->errorOutput), "\n") ?: 'tar failed'));
        }
    }

    public function destroy(string $id): void
    {
        $response = $this->request('delete', "sandboxes/{$id}");

        if ($response->status() !== 404) {
            $this->throwUnlessOk($response);
        }

        unset($this->urls[$id]);
    }

    /**
     * The image new sandboxes start from: the configured image at its newest build (each push adds a tag).
     *
     * @throws SandboxException
     */
    protected function currentImage(): string
    {
        $name = $this->config['image'];
        $response = $this->request('get', "images/sandbox/{$name}");

        if ($response->status() === 404) {
            throw new SandboxException("The sandbox image [{$name}] isn't on Blaxel yet. Run `php artisan sandbox:build-image`.");
        }

        $image = $this->throwUnlessOk($response)->json();
        $tag = collect($image['spec']['tags'] ?? [])->sortByDesc('createdAt')->first()['name'] ?? null;

        if (! $tag) {
            throw new SandboxException("The sandbox image [{$name}] has no build yet. Run `php artisan sandbox:build-image`.");
        }

        return "sandbox/{$name}:{$tag}";
    }

    /**
     * @throws SandboxException when the sandbox doesn't come up within two minutes
     */
    protected function waitUntilDeployed(string $name): void
    {
        $deadline = now()->addMinutes(2);

        do {
            $sandbox = $this->send('get', "sandboxes/{$name}");
            $status = $sandbox['status'] ?? null;

            // Deployed is not yet reachable: also wait for the sandbox's own API to answer.
            if ($status === 'DEPLOYED' && filled($sandbox['metadata']['url'] ?? null)) {
                $this->urls[$name] = $sandbox['metadata']['url'];

                try {
                    if ($this->sandboxClient($name)->timeout(10)->get('health')->successful()) {
                        return;
                    }
                } catch (ConnectionException) {
                    // Still booting.
                }
            }

            if (in_array($status, ['FAILED', 'TERMINATED', 'DELETING'], true)) {
                $reason = collect($sandbox['events'] ?? [])->last()['message'] ?? $status;

                throw new SandboxException("Blaxel couldn't start the sandbox: {$reason}");
            }

            usleep(1_000_000);
        } while (now()->lt($deadline));

        throw new SandboxException('The Blaxel sandbox took too long to start. Try again.');
    }

    /**
     * Upload a local file into the sandbox in parts.
     *
     * @throws SandboxException
     */
    protected function upload(string $id, string $local, string $path): void
    {
        // A path after one slash is relative to /workspace; absolute paths need the second.
        $upload = $this->sandboxSend($id, 'post', 'filesystem-multipart/initiate/'.$path, ['permissions' => '0600']);
        $handle = fopen($local, 'r');
        $parts = [];

        try {
            for ($number = 1; ! feof($handle); $number++) {
                $bytes = fread($handle, self::PART_BYTES);

                if ($bytes === '' || $bytes === false) {
                    break;
                }

                $part = $this->throwUnlessOk($this->sandboxClient($id)->timeout(600)->attach('file', $bytes, 'part')
                    ->put("filesystem-multipart/{$upload['uploadId']}/part?partNumber={$number}"))->json();

                $parts[] = ['partNumber' => $number, 'etag' => $part['etag']];
            }
        } finally {
            fclose($handle);
        }

        $this->sandboxSend($id, 'post', "filesystem-multipart/{$upload['uploadId']}/complete", ['parts' => $parts]);
    }

    /**
     * Call the control plane and return its JSON, throwing on an error.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws SandboxException
     */
    protected function send(string $method, string $path, array $body = []): array
    {
        return $this->throwUnlessOk($this->request($method, $path, $body))->json() ?? [];
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws SandboxException
     */
    protected function request(string $method, string $path, array $data = []): Response
    {
        return $this->call($this->client()->baseUrl(rtrim($this->config['url'], '/').'/v0/'), $method, $path, $data, 30);
    }

    /**
     * Call a sandbox's own API and return its JSON, throwing on an error.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws SandboxException
     */
    protected function sandboxSend(string $id, string $method, string $path, array $body = []): array
    {
        return $this->throwUnlessOk($this->sandboxRequest($id, $method, $path, $body))->json() ?? [];
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws SandboxException
     */
    protected function sandboxRequest(string $id, string $method, string $path, array $data = [], int $timeout = 30): Response
    {
        return $this->call($this->sandboxClient($id), $method, $path, $data, $timeout);
    }

    /**
     * A client for a sandbox's own API (the url comes from the control plane once per sandbox).
     *
     * @throws SandboxException
     */
    protected function sandboxClient(string $id): PendingRequest
    {
        $this->urls[$id] ??= $this->send('get', "sandboxes/{$id}")['metadata']['url']
            ?? throw new SandboxException("Blaxel has no address for sandbox {$id}.");

        return $this->client()->baseUrl(rtrim($this->urls[$id], '/').'/');
    }

    /**
     * Retries temporary failures (rate limits, a sandbox waking from standby).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws SandboxException
     */
    protected function call(PendingRequest $client, string $method, string $path, array $data, int $timeout): Response
    {
        $client->timeout($timeout)->retry(3, fn (int $attempt) => min(8000, 500 * 2 ** $attempt), fn (Throwable $e) => $e instanceof ConnectionException
            || ($e instanceof RequestException && in_array($e->response->status(), [429, 502, 503, 504], true)), throw: false);

        try {
            return in_array($method, ['get', 'delete'], true) ? $client->{$method}($path, $data) : $client->asJson()->{$method}($path, $data);
        } catch (ConnectionException) {
            throw new SandboxException("Couldn't reach Blaxel. Check https://status.blaxel.ai and try again.");
        }
    }

    protected function client(): PendingRequest
    {
        if (blank($this->config['api_key']) || blank($this->config['workspace'])) {
            throw new SandboxException('BL_API_KEY and BL_WORKSPACE must be set. Create a key in the Blaxel console under API keys.');
        }

        return Http::withToken($this->config['api_key'])
            ->withHeaders(['X-Blaxel-Workspace' => $this->config['workspace'], 'Blaxel-Version' => '2026-04-16'])
            ->acceptJson();
    }

    /**
     * @throws SandboxException with Blaxel's message
     */
    protected function throwUnlessOk(Response $response): Response
    {
        if ($response->successful()) {
            return $response;
        }

        $message = $response->json('error.message') ?? $response->json('message') ?? $response->json('error');

        throw new SandboxException('Blaxel: '.(is_string($message) && $message !== '' ? $message : "HTTP {$response->status()}."));
    }
}
