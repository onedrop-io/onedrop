<?php

namespace App\Sandbox\Providers;

use App\Sandbox\AppProcesses;
use App\Sandbox\ExecResult;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxProvider;
use App\Sandbox\SandboxSpec;
use App\Sandbox\SandboxWaitLimit;
use App\Sandbox\TemporarySandboxException;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

/**
 * E2B (e2b.dev) Firecracker sandboxes (SBX-014): the fallback that runs Docker inside its sandboxes (overlayfs on an
 * ext4 root), which Blaxel can't. Sandboxes start from the `onedrop-sandbox` template, the published sandbox image
 * snapshotted with start.sh running (infra/e2b/build.mjs). The control plane (api.e2b.app) creates, pauses, resumes
 * and deletes them; commands and files go to each sandbox's own envd (port 49983) with its access token.
 *
 * A sandbox pauses with its memory kept once nobody has used it for `idle_seconds`, and wakes by itself on the next
 * request to it (autoResume): using it (Sandbox::markActive() calls wake()) puts the pause off again.
 */
class E2bSandboxProvider implements SandboxProvider
{
    /** Metadata naming the template build a sandbox was made from (for isOutdated()). */
    public const BUILD_METADATA = 'onedrop_build';

    /** Metadata saying whether the sandbox was made with Docker inside it (SBX-008). */
    public const DOCKER_METADATA = 'onedrop_docker';

    /** Each sandbox's own API: commands (envd's process service) and files. */
    public const ENVD_PORT = 49983;

    /** The sandbox user's home; commands run in /workspace. */
    public const HOME = '/home/sandbox';

    /** Settings file start.sh and the shell source, as on Runtime: the template's start.sh is already running. */
    public const ENV_FILE = self::HOME.'/.onedrop-env';

    /** How long pause() may leave the app frozen if start() is never called: the longest an update may run. */
    public const THAW_AFTER_SECONDS = 900;

    /** Longest a command may run before it's stopped. */
    public const EXEC_SECONDS = 120;

    /** How often using a sandbox puts its pause off again (one API call). */
    public const TOUCH_SECONDS = 60;

    /** The preview link's query parameter for its traffic token; the gateway sends it as E2B's header (Gateway). */
    public const TRAFFIC_TOKEN = 'e2b_traffic_token';

    /** @var array<string, array{envd: string, traffic: string}> access tokens by sandbox, for this process */
    protected array $tokens = [];

    /**
     * @param  array{api_key: ?string, url: string, domain: string, image: string, idle_seconds: int, nested_docker?: string}  $config
     */
    public function __construct(protected array $config) {}

    public function create(SandboxSpec $spec): string
    {
        $build = $this->currentBuild();

        $sandbox = $this->send('post', 'v2/sandboxes', [
            'templateID' => $this->config['image'],
            'timeout' => $this->idleSeconds(),
            // Commands and files need the sandbox's own token; its ports answer only with the traffic token.
            'secure' => true,
            'network' => ['allowPublicTraffic' => false],
            // Idle: paused with memory kept, and woken by the next request to it.
            'autoPause' => true,
            'autoResume' => ['enabled' => true],
            'metadata' => [
                'name' => $spec->name,
                self::BUILD_METADATA => $build,
                self::DOCKER_METADATA => $this->runsDocker() ? 'on' : 'off',
            ],
        ]);

        $id = (string) $sandbox['sandboxID'];
        $this->tokens[$id] = ['envd' => (string) $sandbox['envdAccessToken'], 'traffic' => (string) ($sandbox['trafficAccessToken'] ?? '')];

        try {
            $this->writeEnvironment($id, [
                'PORT' => (string) $spec->port,
                ...($spec->proxyPort ? ['PROXY_PORT' => (string) $spec->proxyPort] : []),
                ...($spec->shellPort ? ['SHELL_PORT' => (string) $spec->shellPort] : []),
                ...($spec->sshPort ? ['SSH_PORT' => (string) $spec->sshPort] : []),
                // start.sh starts the sandbox's own Docker when it reads this (SBX-008).
                ...($this->runsDocker() ? ['ONEDROP_DOCKER' => '1'] : []),
                ...$spec->env,
            ]);

            // The template's start.sh is already serving the placeholder; restart it so it reads the settings.
            $this->exec($id, ['/opt/onedrop/restart']);
        } catch (SandboxException $e) {
            $this->destroy($id);

            throw $e;
        }

        return $id;
    }

    /**
     * Let processes frozen by pause() carry on, and call off its watchdog.
     */
    public function start(string $id): void
    {
        $this->exec($id, ['bash', '-c', 'pkill -CONT -u "$(id -u)"; pkill -f "[o]nedrop-thaw-watchdog"; exit 0']);
    }

    /**
     * Freeze the app's processes so files are at rest while an update copies them (a database is copied as after a
     * power cut, which it recovers from). Pausing the sandbox itself wouldn't do: copying files out wakes it.
     */
    public function pause(string $id): void
    {
        $frozen = $this->exec($id, ['bash', '-c', AppProcesses::FREEZE]);

        if (! $frozen->successful()) {
            throw new SandboxException("Couldn't pause the sandbox's processes: ".(strtok(trim($frozen->errorOutput), "\n") ?: 'unknown error'));
        }

        // If whoever paused it dies before calling start(), the app still carries on by itself.
        $this->exec($id, ['bash', '-c', 'sleep '.self::THAW_AFTER_SECONDS.'; '.AppProcesses::THAW, 'onedrop-thaw-watchdog'], detach: true);
    }

    /**
     * E2B's own pause: compute stops, memory and processes are kept, and the next request wakes it.
     */
    public function suspend(string $id): void
    {
        $response = $this->request('post', "sandboxes/{$id}/pause");

        // Already paused: nothing to do.
        if ($response->status() !== 409) {
            $this->throwUnlessOk($response);
        }
    }

    /**
     * Someone is using it: put its idle pause off again (at most every TOUCH_SECONDS). A paused sandbox wakes by
     * itself on the next request, so there's nothing to wait for.
     */
    public function wake(string $id): bool
    {
        if (Cache::add("e2b-touch:{$id}", true, self::TOUCH_SECONDS)) {
            $response = $this->request('post', "sandboxes/{$id}/timeout", ['timeout' => $this->idleSeconds()]);

            if ($response->status() !== 404) {
                $this->throwUnlessOk($response);
            }
        }

        return false;
    }

    public function exec(string $id, array $command, array $env = [], bool $detach = false, bool $root = false): ExecResult
    {
        if ($command === []) {
            throw new SandboxException('No command to run.');
        }

        // Env goes in the body, never the command line. Commands run in the project with the sandbox user's home.
        $process = [
            'cmd' => $detach ? '/bin/bash' : 'timeout',
            'args' => $detach
                ? ['-c', 'setsid nohup "$@" >/dev/null 2>&1 < /dev/null &', 'onedrop-detach', ...$command]
                : ['--kill-after=5', (string) self::EXEC_SECONDS, ...$command],
            'envs' => (object) ['HOME' => $root ? '/root' : self::HOME, ...$env],
            'cwd' => '/workspace',
        ];

        $events = $this->envd($id, $root ? 'root' : 'sandbox', $process, self::EXEC_SECONDS + 30);
        $stdout = $stderr = '';
        $exitCode = null;

        foreach ($events as $event) {
            $data = $event['event']['data'] ?? null;
            $stdout .= isset($data['stdout']) ? base64_decode((string) $data['stdout']) : '';
            $stderr .= isset($data['stderr']) ? base64_decode((string) $data['stderr']) : '';

            if (isset($event['event']['end'])) {
                // Protobuf's JSON leaves a zero exit code out.
                $exitCode = (int) ($event['event']['end']['exitCode'] ?? 0);
            }
        }

        if ($exitCode === null) {
            throw new SandboxException("The command in the sandbox ended without saying how. {$stderr}");
        }

        // `timeout` says 124 when the command ran too long.
        return $exitCode === 124 && ! $detach
            ? new ExecResult(124, $stdout, trim($stderr."\nTimed out after ".self::EXEC_SECONDS.' seconds.'))
            : new ExecResult($exitCode, $stdout, $stderr);
    }

    public function previewUrl(string $id, int $port): ?string
    {
        // Previews are HTTPS only; SSH has no address here (the Developer → SSH page says so).
        if ($port === (int) config('sandbox.ssh_port')) {
            return null;
        }

        // Private: the app hands the link (and its token) only to people who may see the project.
        return "https://{$port}-{$id}.{$this->config['domain']}/?".http_build_query([self::TRAFFIC_TOKEN => $this->tokens($id)['traffic']]);
    }

    public function checkImage(): void
    {
        $this->currentBuild();
    }

    public function isOutdated(string $id): bool
    {
        $sandbox = $this->request('get', "sandboxes/{$id}");
        $template = $this->template();

        // No sandbox, or no finished build: nothing to update to (or from).
        if ($sandbox->failed() || ($template['buildStatus'] ?? null) !== 'ready') {
            return false;
        }

        $metadata = $sandbox->json('metadata') ?? [];

        // Docker inside sandboxes turned on or off (SBX-008): recreate it with (or without) its own Docker.
        return ($metadata[self::BUILD_METADATA] ?? null) !== ($template['buildID'] ?? null)
            || ($metadata[self::DOCKER_METADATA] ?? 'off') !== ($this->runsDocker() ? 'on' : 'off');
    }

    public function copyOut(string $id, string $path, string $directory): void
    {
        $archive = '/tmp/onedrop-copy-'.Str::random(8).'.tgz';

        // As root, like docker cp, so files the app's processes own come along. A missing path has nothing to copy.
        $packed = $this->exec($id, ['bash', '-c', 'test -d "$1" || exit 3; tar -czf "$2" -C "$1" .', 'pack', $path, $archive], root: true);

        if ($packed->exitCode === 3) {
            return;
        }

        if (! $packed->successful()) {
            throw new SandboxException("Couldn't copy {$path} out of the sandbox: ".(strtok(trim($packed->errorOutput), "\n") ?: 'tar failed'));
        }

        $local = tempnam(sys_get_temp_dir(), 'onedrop-copy-');

        try {
            $this->throwUnlessOk($this->files($id, 'root', 600)->sink($local)->get('files', ['path' => $archive, 'username' => 'root']));

            $result = Process::forever()->run(['tar', '-xzf', $local, '-C', $directory]);

            if ($result->failed()) {
                throw new SandboxException("Couldn't unpack {$path} from the sandbox: ".strtok(trim($result->errorOutput()), "\n"));
            }
        } finally {
            @unlink($local);
            $this->exec($id, ['rm', '-f', $archive], root: true);
        }
    }

    public function copyIn(string $id, string $directory, string $path): void
    {
        $archive = $this->uploadArchive($id, $directory);

        // Unpack as root, then hand the path (and any parents mkdir made under the sandbox user's folders) back to it.
        $owned = array_values(array_filter(['/workspace', '/data/storage', self::HOME], fn (string $root) => str_starts_with($path, $root)));
        $unpacked = $this->exec($id, ['bash', '-c', 'mkdir -p "$1" && tar -xzf "$2" -C "$1" && rm -f "$2" && shift 2 && chown -R 1000:1000 "$@"', 'unpack', $path, $archive, ...($owned ?: [$path])], root: true);

        if (! $unpacked->successful()) {
            throw new SandboxException("Couldn't copy files into {$path} in the sandbox: ".(strtok(trim($unpacked->errorOutput), "\n") ?: 'tar failed'));
        }
    }

    public function installFiles(string $id, string $directory, string $path): bool
    {
        $archive = $this->uploadArchive($id, $directory);
        $unpacked = $this->exec($id, ['bash', '-c', 'tar -xzf "$2" --no-same-owner -C "$1"; status=$?; rm -f "$2"; exit $status', 'install', $path, $archive], root: true);

        if (! $unpacked->successful()) {
            throw new SandboxException("Couldn't copy files into {$path} in the sandbox: ".(strtok(trim($unpacked->errorOutput), "\n") ?: 'tar failed'));
        }

        return true;
    }

    public function destroy(string $id): void
    {
        $response = $this->request('delete', "sandboxes/{$id}");

        if ($response->status() !== 404) {
            $this->throwUnlessOk($response);
        }

        unset($this->tokens[$id]);
    }

    /**
     * Whether sandboxes get Docker inside them, for projects that run their own Docker Compose (SBX-008).
     */
    protected function runsDocker(): bool
    {
        return ($this->config['nested_docker'] ?? 'on') === 'on';
    }

    protected function idleSeconds(): int
    {
        return max(60, (int) $this->config['idle_seconds']);
    }

    /**
     * The sandbox template, as E2B lists it (null when it isn't there).
     *
     * @return array<string, mixed>|null
     *
     * @throws SandboxException
     */
    protected function template(): ?array
    {
        $templates = $this->send('get', 'templates');

        return collect(array_is_list($templates) ? $templates : [])
            ->first(fn (mixed $template) => is_array($template) && in_array($this->config['image'], $template['aliases'] ?? [], true));
    }

    /**
     * The id of the template build new sandboxes start from.
     *
     * @throws SandboxException when there's no finished build
     */
    protected function currentBuild(): string
    {
        $template = $this->template();

        if ($template === null) {
            throw new SandboxException("The sandbox image [{$this->config['image']}] isn't on E2B yet. Run `php artisan sandbox:build-image`.");
        }

        if (($template['buildStatus'] ?? null) !== 'ready' || trim((string) ($template['buildID'] ?? ''), '0-') === '') {
            throw new SandboxException("The sandbox image [{$this->config['image']}] has no finished build on E2B yet. Run `php artisan sandbox:build-image`.");
        }

        return (string) $template['buildID'];
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

        $this->throwUnlessOk($this->files($id, 'sandbox')->withBody($lines."\n", 'application/octet-stream')
            ->post('files?'.http_build_query(['path' => self::ENV_FILE, 'username' => 'sandbox'])));

        $this->exec($id, ['chmod', '600', self::ENV_FILE]);
    }

    /**
     * Pack a local directory and upload it to the sandbox; returns where it went.
     *
     * @throws SandboxException
     */
    protected function uploadArchive(string $id, string $directory): string
    {
        $local = tempnam(sys_get_temp_dir(), 'onedrop-copy-');
        $archive = '/tmp/onedrop-copy-'.Str::random(8).'.tgz';

        try {
            $result = Process::env(['COPYFILE_DISABLE' => '1'])->forever()->run(['tar', '-czf', $local, '-C', $directory, '.']);

            if ($result->failed()) {
                throw new SandboxException("Couldn't pack {$directory}: ".strtok(trim($result->errorOutput()), "\n"));
            }

            $stream = fopen($local, 'r') ?: throw new SandboxException("Couldn't read {$local} to upload it to the sandbox.");

            try {
                $this->throwUnlessOk($this->files($id, 'root', 600)->withBody(Utils::streamFor($stream), 'application/octet-stream')
                    ->post('files?'.http_build_query(['path' => $archive, 'username' => 'root'])));
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        } finally {
            @unlink($local);
        }

        return $archive;
    }

    /**
     * Start a process with envd's process service (a Connect server stream of JSON messages) and read its events
     * until it ends.
     *
     * @param  array<string, mixed>  $process
     * @return list<array<string, mixed>>
     *
     * @throws SandboxException
     */
    protected function envd(string $id, string $user, array $process, int $timeout): array
    {
        $message = json_encode(['process' => $process, 'stdin' => false], JSON_THROW_ON_ERROR);

        // envd pings the stream while a quiet command runs, so nothing in between drops it. A start whose answer was
        // lost isn't sent again: the command may already be running.
        $response = $this->call(
            $this->sandboxClient($id, $user)->withHeaders(['Connect-Protocol-Version' => '1', 'Keepalive-Ping-Interval' => '50']),
            fn (PendingRequest $client) => $client->withBody(pack('CN', 0, strlen($message)).$message, 'application/connect+json')->post('process.Process/Start'),
            $timeout,
            retryLostAnswers: false,
        );

        $this->throwUnlessOk($response);

        $body = $response->body();
        $events = [];

        for ($offset = 0; $offset + 5 <= strlen($body);) {
            ['flags' => $flags, 'length' => $length] = unpack('Cflags/Nlength', substr($body, $offset, 5)) ?: ['flags' => 2, 'length' => 0];
            $frame = json_decode(substr($body, $offset + 5, $length), true) ?? [];
            $offset += 5 + $length;

            // The last frame ends the stream, with an error if the call failed.
            if ($flags & 0x02) {
                if (isset($frame['error'])) {
                    throw new SandboxException('E2B: '.($frame['error']['message'] ?? $frame['error']['code'] ?? 'the command failed'));
                }

                continue;
            }

            $events[] = $frame;
        }

        return $events;
    }

    /**
     * A client for a sandbox's envd file API, as a user.
     *
     * @throws SandboxException
     */
    protected function files(string $id, string $user, int $timeout = 30): PendingRequest
    {
        return $this->sandboxClient($id, $user)->timeout($timeout);
    }

    /**
     * A client for a sandbox's own API (envd), as a user.
     *
     * @throws SandboxException
     */
    protected function sandboxClient(string $id, string $user): PendingRequest
    {
        return Http::baseUrl('https://'.self::ENVD_PORT."-{$id}.{$this->config['domain']}/")
            ->withHeaders(['X-Access-Token' => $this->tokens($id)['envd'], 'Authorization' => 'Basic '.base64_encode("{$user}:")]);
    }

    /**
     * The sandbox's access tokens. Connecting to it hands them out again (and wakes it if it's paused).
     *
     * @return array{envd: string, traffic: string}
     *
     * @throws SandboxException
     */
    protected function tokens(string $id): array
    {
        if (! isset($this->tokens[$id])) {
            $sandbox = $this->send('post', "v2/sandboxes/{$id}/connect", ['timeout' => $this->idleSeconds()]);
            $this->tokens[$id] = ['envd' => (string) ($sandbox['envdAccessToken'] ?? ''), 'traffic' => (string) ($sandbox['trafficAccessToken'] ?? '')];
        }

        return $this->tokens[$id];
    }

    /**
     * Call the control plane and return its JSON, throwing on an error.
     *
     * @param  array<string, mixed>  $body
     * @return array<int|string, mixed>
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
        return $this->call(
            $this->client(),
            fn (PendingRequest $client) => in_array($method, ['get', 'delete'], true) ? $client->{$method}($path, $data) : $client->asJson()->{$method}($path, (object) $data),
            30,
        );
    }

    /**
     * Send a request, retrying temporary failures (rate limits, a sandbox waking up, and with $retryLostAnswers a
     * connection that dropped). In a web request or a move's
     * job every try fits in the time it has left (SandboxWaitLimit), as on Runtime.
     *
     * @param  callable(PendingRequest): Response  $send
     *
     * @throws SandboxException
     */
    protected function call(PendingRequest $client, callable $send, int $timeout, bool $retryLostAnswers = true): Response
    {
        $limit = app(SandboxWaitLimit::class);
        $left = fn (): float => $limit->secondsLeft() ?? PHP_INT_MAX;

        if ($left() < 1) {
            throw new TemporarySandboxException(RuntimeSandboxProvider::NO_ANSWER);
        }

        $client->timeout($timeout)
            ->withMiddleware(fn (callable $handler) => fn ($request, array $options) => $handler($request, [
                ...$options,
                'timeout' => max(0.5, min($options['timeout'], $left())),
            ]))
            ->retry(3, fn (int $attempt) => (int) min(8000, 500 * 2 ** $attempt, ($left() - 1) * 1000), fn (Throwable $e) => $left() >= 2 && (($retryLostAnswers && $e instanceof ConnectionException)
                || ($e instanceof RequestException && in_array($e->response->status(), [429, 502, 503], true))), throw: false);

        try {
            return $send($client);
        } catch (ConnectionException) {
            if ($left() < 1) {
                throw new TemporarySandboxException(RuntimeSandboxProvider::NO_ANSWER);
            }

            throw new SandboxException("Couldn't reach E2B. Check https://status.e2b.dev and try again.");
        }
    }

    protected function client(): PendingRequest
    {
        if (blank($this->config['api_key'])) {
            throw new SandboxException('E2B_API_KEY is not set. Create a key at https://e2b.dev/dashboard.');
        }

        return Http::baseUrl(rtrim($this->config['url'], '/').'/')->withHeaders(['X-API-Key' => $this->config['api_key']])->acceptJson();
    }

    /**
     * @throws SandboxException with E2B's message
     */
    protected function throwUnlessOk(Response $response): Response
    {
        if ($response->successful()) {
            return $response;
        }

        $message = $response->json('message') ?? $response->json('error.message') ?? "E2B answered HTTP {$response->status()}.";

        if ($response->status() === 429) {
            throw new TemporarySandboxException("E2B is busy right now ({$message}). Try again in a moment.");
        }

        throw new SandboxException(trim("E2B: {$message}"));
    }
}
