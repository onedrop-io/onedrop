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
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runtime Cloud (withruntime.com) Firecracker sandboxes, over its HTTPS API.
 * The sandbox image is docker/sandbox built there (php artisan sandbox:build-image).
 */
class RuntimeSandboxProvider implements SandboxProvider
{
    /** Label holding the id of the image version a sandbox was made from (for isOutdated()). */
    public const IMAGE_LABEL = 'zap.image';

    /** The sandbox user's home (Runtime itself starts commands in /workspace with HOME set to it). */
    public const HOME = '/home/sandbox';

    /** Settings file start.sh and the shell source: Runtime can't set a sandbox's env at create. */
    public const ENV_FILE = self::HOME.'/.zap-env';

    /** How long pause() may leave the app frozen if start() is never called: the longest an update may run. */
    public const THAW_AFTER_SECONDS = 900;

    /** How long create() waits for a free slot while the trial's running limit is reached. */
    public const CAPACITY_WAIT_SECONDS = 120;

    /** Longest a preview token lasts (7 days); ProjectController renews the links daily. */
    public const PREVIEW_TTL_SECONDS = 604800;

    /**
     * @param  array{api_key: ?string, url: string, image: string, funding: string, vcpu: int, memory_mib: int, disk_mib: int, timeout_seconds: int, persistent: bool, preview_visibility?: string}  $config
     */
    public function __construct(protected array $config) {}

    public function create(SandboxSpec $spec): string
    {
        $image = $this->imageId();

        $sandbox = $this->createWhenThereIsRoom(array_filter([
            'name' => $spec->name,
            'labels' => [self::IMAGE_LABEL => $image],
            'image' => $image,
            'funding' => $this->config['funding'],
            'vcpu' => $this->config['vcpu'],
            'memoryMiB' => $this->config['memory_mib'],
            'diskMiB' => $this->config['disk_mib'],
            'timeoutSeconds' => $this->config['timeout_seconds'],
            'persistent' => $this->config['persistent'] ?: null,
        ], fn (mixed $value) => $value !== null));

        $id = $sandbox['id'];

        try {
            $this->writeEnvironment($id, [
                'PORT' => (string) $spec->port,
                ...($spec->proxyPort ? ['PROXY_PORT' => (string) $spec->proxyPort] : []),
                ...($spec->shellPort ? ['SHELL_PORT' => (string) $spec->shellPort] : []),
                ...($spec->sshPort ? ['SSH_PORT' => (string) $spec->sshPort] : []),
                ...$spec->env,
            ]);

            // The image's start.sh is already serving the placeholder; restart it so it reads the settings.
            $this->exec($id, ['/opt/zap/restart']);
        } catch (SandboxException $e) {
            $this->destroy($id);

            throw $e;
        }

        return $id;
    }

    /**
     * Create the sandbox, waiting (with backoff, up to CAPACITY_WAIT_SECONDS) while the trial's running limit is
     * reached or Runtime has no room, as Runtime's own SDKs do: both clear by themselves.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws SandboxException
     */
    protected function createWhenThereIsRoom(array $body): array
    {
        $deadline = now()->addSeconds(self::CAPACITY_WAIT_SECONDS);

        for ($delay = 2; ; $delay = min($delay * 2, 20)) {
            $response = $this->request('post', 'sandboxes', $body, wait: 120);

            if (! in_array($response->json('error.code'), ['trial_busy', 'no_capacity'], true) || now()->addSeconds($delay)->gt($deadline)) {
                return $this->throwUnlessOk($response)->json();
            }

            Sleep::for($delay)->seconds();
        }
    }

    /**
     * Let processes frozen by pause() carry on, and call off its watchdog (a running command keeps the sandbox awake).
     * Runtime wakes a suspended sandbox by itself on the next request.
     */
    public function start(string $id): void
    {
        $this->exec($id, ['bash', '-c', 'pkill -CONT -u "$(id -u)"; pkill -f "[z]ap-thaw-watchdog"; exit 0']);
    }

    /**
     * Freeze the app's processes so files are at rest while an update copies them (a database is copied as after a
     * power cut, which it recovers from). Pausing the sandbox itself wouldn't do: copying files out wakes it.
     */
    public function pause(string $id): void
    {
        $frozen = $this->exec($id, ['bash', '-c', 'for pid in $(pgrep -u "$(id -u)"); do [ "$pid" = $$ ] || [ "$pid" = "$PPID" ] || kill -STOP "$pid" 2>/dev/null; done; exit 0']);

        if (! $frozen->successful()) {
            throw new SandboxException("Couldn't pause the sandbox's processes: ".(strtok(trim($frozen->errorOutput), "\n") ?: 'unknown error'));
        }

        // If whoever paused it dies before calling start() (e.g. a deploy replaced the worker), the app still
        // carries on by itself once an update could no longer be running.
        $this->exec($id, ['bash', '-c', 'sleep '.self::THAW_AFTER_SECONDS.'; pkill -CONT -u "$(id -u)"', 'zap-thaw-watchdog'], detach: true);
    }

    /**
     * Runtime's own pause: compute stops, memory and processes are kept, and the next request wakes it.
     */
    public function suspend(string $id): void
    {
        $response = $this->request('post', "sandboxes/{$id}:pause", [], wait: 60);

        // Already paused or stopped: nothing to do.
        if ($response->status() === 409) {
            return;
        }

        $this->throwUnlessOk($response);
    }

    public function exec(string $id, array $command, array $env = [], bool $detach = false): ExecResult
    {
        // Env goes in the body, never the command line: Runtime never echoes it back. Runtime runs commands
        // with HOME=/workspace; give them the sandbox user's home so the agent's data stays out of the project.
        $body = ['argv' => $command, 'env' => ['HOME' => self::HOME, ...$env]];

        if ($detach) {
            $this->send('post', "sandboxes/{$id}/processes", $body);

            return new ExecResult(0, '');
        }

        $result = $this->send('post', "sandboxes/{$id}:exec", [...$body, 'timeoutMs' => 120_000], timeout: 150);

        return $result['timedOut'] ?? false
            ? new ExecResult(124, (string) $result['stdout'], trim($result['stderr']."\nTimed out after 120 seconds."))
            : new ExecResult((int) $result['exitCode'], (string) $result['stdout'], (string) $result['stderr']);
    }

    public function previewUrl(string $id, int $port): ?string
    {
        // Previews are HTTPS only; SSH has no address here (the Developer → SSH page says so).
        if ($port === (int) config('sandbox.ssh_port')) {
            return null;
        }

        // Either way the app hands the link only to people who may see the project. Private links carry a token
        // (asking again answers the same preview with a current one); public ones (paid only) embed in the workspace.
        if (($this->config['preview_visibility'] ?? 'private') === 'public') {
            return $this->send('post', "sandboxes/{$id}/previews", ['port' => $port, 'visibility' => 'public'])['url'] ?? null;
        }

        $preview = $this->send('post', "sandboxes/{$id}/previews", ['port' => $port, 'ttlSeconds' => self::PREVIEW_TTL_SECONDS]);

        return $preview['urlWithToken'] ?? null;
    }

    public function isOutdated(string $id): bool
    {
        $sandbox = $this->request('get', "sandboxes/{$id}");
        $current = $this->request('get', 'images/resolve', ['ref' => $this->config['image']]);

        // No image or no sandbox: nothing to update to (or from).
        if ($sandbox->failed() || $current->failed()) {
            return false;
        }

        return ($sandbox->json('labels')[self::IMAGE_LABEL] ?? null) !== $current->json('id');
    }

    public function copyOut(string $id, string $path, string $directory): void
    {
        $archive = '/tmp/zap-copy-'.Str::random(8).'.tgz';

        // As root, like docker cp, so files the app's processes own come along. A missing path has nothing to copy.
        $packed = $this->exec($id, ['sudo', 'bash', '-c', 'test -d "$1" || exit 3; tar -czf "$2" -C "$1" .', 'pack', $path, $archive]);

        if ($packed->exitCode === 3) {
            return;
        }

        if (! $packed->successful()) {
            throw new SandboxException("Couldn't copy {$path} out of the sandbox: ".(strtok(trim($packed->errorOutput), "\n") ?: 'tar failed'));
        }

        $local = tempnam(sys_get_temp_dir(), 'zap-copy-');

        try {
            $this->throwUnlessOk($this->client()->timeout(600)->sink($local)
                ->get("sandboxes/{$id}/files/content", ['path' => $archive]));

            $result = Process::forever()->run(['tar', '-xzf', $local, '-C', $directory]);

            if ($result->failed()) {
                throw new SandboxException("Couldn't unpack {$path} from the sandbox: ".strtok(trim($result->errorOutput()), "\n"));
            }
        } finally {
            @unlink($local);
            $this->exec($id, ['sudo', 'rm', '-f', $archive]);
        }
    }

    public function copyIn(string $id, string $directory, string $path): void
    {
        $local = tempnam(sys_get_temp_dir(), 'zap-copy-');
        // Large uploads may only go under /workspace; the unpack below removes it again.
        $archive = '/workspace/.zap-copy-'.Str::random(8).'.tgz';

        try {
            $result = Process::forever()->run(['tar', '-czf', $local, '-C', $directory, '.']);

            if ($result->failed()) {
                throw new SandboxException("Couldn't pack {$directory}: ".strtok(trim($result->errorOutput()), "\n"));
            }

            $this->upload($id, $local, $archive);
        } finally {
            @unlink($local);
        }

        // Unpack as root, then hand the path (and any parents mkdir made under the sandbox user's folders) back to it.
        $owned = array_values(array_filter(['/workspace', '/data/storage', '/home/sandbox'], fn (string $root) => str_starts_with($path, $root)));
        $unpacked = $this->exec($id, ['sudo', 'bash', '-c', 'mkdir -p "$1" && tar -xzf "$2" -C "$1" && rm -f "$2" && shift 2 && chown -R 1000:1000 "$@"', 'unpack', $path, $archive, ...($owned ?: [$path])]);

        if (! $unpacked->successful()) {
            throw new SandboxException("Couldn't copy files into {$path} in the sandbox: ".(strtok(trim($unpacked->errorOutput), "\n") ?: 'tar failed'));
        }
    }

    /**
     * Upload a local file in the chunks Runtime asks for (a single request refuses large files with 413),
     * checked against its SHA-256 when committed.
     *
     * @throws SandboxException
     */
    protected function upload(string $id, string $local, string $path): void
    {
        $size = filesize($local);
        $upload = $this->send('post', "sandboxes/{$id}/uploads", ['path' => $path, 'size' => $size, 'sha256' => hash_file('sha256', $local), 'mode' => '600']);
        $chunk = max(1, (int) $upload['chunkBytes']);
        $handle = fopen($local, 'r');

        if ($handle === false) {
            throw new SandboxException("Couldn't read [{$local}] to upload it to the sandbox.");
        }

        try {
            for ($offset = 0; $offset < $size; $offset += $chunk) {
                $bytes = stream_get_contents($handle, $chunk, $offset);

                $this->throwUnlessOk($this->client()->timeout(600)
                    ->withBody($bytes, 'application/octet-stream')
                    ->put("sandboxes/{$id}/uploads/{$upload['uploadId']}?offset={$offset}"));
            }
        } finally {
            fclose($handle);
        }

        $this->send('post', "sandboxes/{$id}/uploads/{$upload['uploadId']}:commit", [], timeout: 120);
    }

    public function destroy(string $id): void
    {
        $response = $this->request('post', "sandboxes/{$id}:stop", [], wait: 60);

        if ($response->status() !== 404) {
            $this->throwUnlessOk($response);
        }
    }

    /**
     * The id of the image version new sandboxes start from.
     *
     * @throws SandboxException
     */
    protected function imageId(): string
    {
        $response = $this->request('get', 'images/resolve', ['ref' => $this->config['image']]);

        if ($response->status() === 404) {
            throw new SandboxException("The sandbox image [{$this->config['image']}] isn't on Runtime yet. Run `php artisan sandbox:build-image`.");
        }

        return $this->throwUnlessOk($response)->json('id');
    }

    /**
     * Write the sandbox's settings (secrets included) to a file only the sandbox user can read.
     *
     * @param  array<string, string>  $env
     *
     * @throws SandboxException
     */
    protected function writeEnvironment(string $id, array $env): void
    {
        $lines = collect($env)->map(fn (string $value, string $name) => $name.'='.escapeshellarg($value))->implode("\n");

        $this->throwUnlessOk($this->client()->withBody($lines."\n", 'text/plain')
            ->put("sandboxes/{$id}/files/content?".http_build_query(['path' => self::ENV_FILE, 'mode' => '600'])));
    }

    /**
     * Call the API and return its JSON, throwing on an error.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws SandboxException
     */
    protected function send(string $method, string $path, array $body = [], ?int $wait = null, int $timeout = 30): array
    {
        return $this->throwUnlessOk($this->request($method, $path, $body, $wait, $timeout))->json() ?? [];
    }

    /**
     * Call the API. Changes carry an idempotency key, so retries of temporary refusals never happen twice.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws SandboxException
     */
    protected function request(string $method, string $path, array $data = [], ?int $wait = null, int $timeout = 30): Response
    {
        $client = $this->client()->timeout($timeout + ($wait ?? 0))
            ->retry(3, fn (int $attempt) => min(8000, 500 * 2 ** $attempt), fn (Throwable $e) => $e instanceof ConnectionException
                || ($e instanceof RequestException && in_array($e->response->status(), [429, 502, 503, 504], true)), throw: false);

        if ($method !== 'get') {
            $client->withHeaders(['Idempotency-Key' => (string) Str::uuid()]);
        }

        if ($wait !== null) {
            $client->withHeaders(['Prefer' => "wait={$wait}"]);
        }

        try {
            return $method === 'get' ? $client->get($path, $data) : $client->asJson()->{$method}($path, (object) $data);
        } catch (ConnectionException) {
            throw new SandboxException("Couldn't reach Runtime Cloud. Check https://withruntime.com/status and try again.");
        }
    }

    protected function client(): PendingRequest
    {
        if (blank($this->config['api_key'])) {
            throw new SandboxException('RUNTIME_API_KEY is not set. Create a key at https://withruntime.com/account/keys.');
        }

        return Http::baseUrl(rtrim($this->config['url'], '/').'/v1/')->withToken($this->config['api_key'])->acceptJson();
    }

    /**
     * @throws SandboxException with Runtime's message and hint
     */
    protected function throwUnlessOk(Response $response): Response
    {
        if ($response->successful()) {
            return $response;
        }

        $error = $response->json('error') ?? [];
        $message = $error['message'] ?? "Runtime Cloud answered HTTP {$response->status()}.";

        throw new SandboxException(trim("Runtime: {$message} ".($error['hint'] ?? '').(isset($error['requestId']) ? " ({$error['requestId']})" : '')));
    }
}
