<?php

namespace App\Sandbox;

use App\Enums\PublishStatus;
use App\Enums\PublishTarget;
use App\Models\Project;
use App\Models\ProjectDomain;
use App\Models\Sandbox;
use App\Models\Task;
use App\Models\User;
use App\Sandbox\Providers\DeviceSandboxProvider;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

/**
 * Public addresses for sandbox previews and shells on a server:
 * preview-<sandbox id>.<gateway domain> and shell-<sandbox id>.<gateway domain>,
 * plus projects published to the domain (DomainPublisher): <name>-<project id>.<gateway domain>, and each pull request's
 * preview (GIT-027): pr-<number>-<name>-<project id>.<gateway domain>, its task's copy, for anyone with its link.
 * Caddy routes them to the sandbox after SandboxGatewayController authorizes the request.
 *
 * The app's login cookie never reaches these addresses: code in a sandbox could read it, and a
 * sandboxed app's own cookies could clash with it. Instead the app hands the browser a short-lived
 * token (see enterUrl()), which each address trades for its own cookie (see pass()).
 */
class Gateway
{
    public const KINDS = ['preview', 'shell'];

    /** The kind for a project published to the domain: its app, served like the preview. */
    public const APP = 'app';

    /** The kind for a pull request's preview (GIT-027): its task's copy, for anyone with its link. */
    public const PULL_REQUEST = 'pr';

    /** The per-address cookie. Caddy strips it before traffic reaches the sandbox. */
    public const COOKIE = 'onedrop_gateway';

    /** How long the hand-off token in enterUrl() is valid. */
    public const TOKEN_SECONDS = 60;

    /** How long a preview or shell stays open without going through the app again. */
    public const PASS_MINUTES = 720;

    /** Header the Cloudflare Worker proves itself with, and the one naming the preview/shell address it serves. */
    public const SECRET_HEADER = 'X-OneDrop-Gateway-Secret';

    public const HOST_HEADER = 'X-OneDrop-Gateway-Host';

    /** Private preview links' token parameter => the header the provider also accepts it in. */
    public const PROVIDER_TOKENS = [
        'bl_preview_token' => 'X-Blaxel-Preview-Token',
        'runtime_preview_token' => 'X-Runtime-Preview-Token',
        'e2b_traffic_token' => 'e2b-traffic-access-token',
    ];

    public function __construct(protected ?string $domain, protected ?string $secret = null) {}

    public function enabled(): bool
    {
        return filled($this->domain);
    }

    public function domain(): ?string
    {
        return $this->domain;
    }

    /**
     * Whether the gateway addresses are on another site than the app (onedrop.dev beside onedrop.io, GW-002), so the
     * workspace's preview and shell frames are cross-site: their cookie must be partitioned and SameSite=None, since
     * browsers send a Lax one only to a frame on its own site, and many keep only partitioned ones in a frame there.
     * Sites are compared by their last two labels.
     */
    public function onAnotherSite(): bool
    {
        $site = fn (string $host) => implode('.', array_slice(explode('.', strtolower($host)), -2));

        return $this->enabled() && $site((string) $this->domain) !== $site((string) parse_url((string) config('app.url'), PHP_URL_HOST));
    }

    /**
     * Whether a Cloudflare Worker, not Caddy on this server, serves the gateway addresses.
     */
    public function viaWorker(): bool
    {
        return filled($this->secret);
    }

    /**
     * Whether a request comes from the Worker (it carries the shared secret).
     */
    public function isFromWorker(Request $request): bool
    {
        return $this->viaWorker() && hash_equals($this->secret, (string) $request->header(self::SECRET_HEADER));
    }

    /**
     * The preview/shell address a request is for: named by the Worker, or (behind Caddy) the given host.
     * Null when the Worker is expected but the request doesn't prove it came from there.
     */
    public function requestedHost(Request $request, ?string $caddyHost): ?string
    {
        if (! $this->viaWorker()) {
            return $caddyHost;
        }

        return $this->isFromWorker($request) ? (string) $request->header(self::HOST_HEADER) : null;
    }

    /**
     * Where the Worker should forward a sandbox's preview or shell: the provider's address, and the header
     * carrying its private preview token, so the token never reaches the browser.
     *
     * @return array{url: string, header: string|null, token: string|null}|null
     */
    public function target(Sandbox $sandbox, string $kind): ?array
    {
        $url = $kind === 'shell' ? $sandbox->shell_url : $sandbox->preview_url;

        // A sandbox on someone's computer (DESK-010): the Worker hands the request to that computer's relay.
        if ($url !== null && str_starts_with($url, DeviceSandboxProvider::SCHEME)) {
            return ['url' => $url, 'header' => null, 'token' => null];
        }

        $parts = $url ? parse_url($url) : null;

        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        parse_str($parts['query'] ?? '', $query);
        $param = collect(array_keys(self::PROVIDER_TOKENS))->first(fn (string $name) => isset($query[$name]));

        return [
            'url' => "{$parts['scheme']}://{$parts['host']}".(isset($parts['port']) ? ":{$parts['port']}" : ''),
            'header' => $param ? self::PROVIDER_TOKENS[$param] : null,
            'token' => $param && is_string($query[$param]) ? $query[$param] : null,
        ];
    }

    /**
     * The public URL for a sandbox's preview or shell (or its project's published app), or null when the gateway is off.
     */
    public function url(Sandbox $sandbox, string $kind): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        return match ($kind) {
            self::APP => $sandbox->project?->published_url,
            self::PULL_REQUEST => $sandbox->task ? 'https://'.$this->pullRequestHost($sandbox->task) : null,
            default => "https://{$kind}-{$sandbox->id}.{$this->domain}",
        };
    }

    /**
     * A pull request's preview address (GIT-027): the same while the pull request is open, whichever copy serves it.
     */
    public function pullRequestHost(Task $task): string
    {
        return "pr-{$task->pull_request_number}-{$task->project->publishHostname()}.".strtolower((string) $this->domain);
    }

    /**
     * The link reviewers open a pull request's preview with (GIT-027), or null without a gateway or a key.
     */
    public function pullRequestLink(Task $task, string $path = '/'): ?string
    {
        if (! $this->enabled() || $task->preview_key === null || $task->pull_request_number === null) {
            return null;
        }

        return 'https://'.$this->pullRequestHost($task).'/__onedrop/enter?'.http_build_query(['key' => $task->preview_key, 'path' => $path]);
    }

    /**
     * The cookie value that lets anyone with the pull request's link through (GIT-027). It names the link's key, so a
     * new link stops it at once.
     */
    public function pullRequestPass(Task $task): string
    {
        return json_encode([
            'kind' => self::PULL_REQUEST,
            'task' => $task->id,
            'key' => hash('sha256', (string) $task->preview_key),
            'expires' => now()->addMinutes(self::PASS_MINUTES)->getTimestamp(),
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Whether a (decrypted) pass cookie lets its browser into the task's pull request preview.
     */
    public function allowsPullRequest(?string $pass, Task $task): bool
    {
        $data = $pass ? json_decode($pass, true) : null;

        return is_array($data)
            && ($data['kind'] ?? null) === self::PULL_REQUEST
            && ($data['task'] ?? null) === $task->id
            && $task->preview_key !== null
            && is_string($data['key'] ?? null)
            && hash_equals(hash('sha256', $task->preview_key), $data['key'])
            && is_int($data['expires'] ?? null)
            && $data['expires'] >= now()->getTimestamp();
    }

    /**
     * The address a project gets when published to the domain: <name>-<id>.<domain>. A name that would read as a
     * preview or shell address (a project called "Preview") gets an "app-" prefix.
     */
    public function publishedHost(Project $project): string
    {
        $label = $project->publishHostname();

        // Nor as a pull request's preview (GIT-027).
        if (preg_match('/^('.implode('|', self::KINDS).')-\d+$/', $label) || preg_match('/^pr-\d+-/', $label)) {
            $label = "app-{$label}";
        }

        return "{$label}.".strtolower((string) $this->domain);
    }

    /**
     * Parse a gateway hostname into its kind and sandbox id: a preview or shell, or a project published to the domain
     * (kind "app", its main sandbox) while it's live there, at its own address or a custom domain (DOM-001).
     *
     * @return array{kind: string, sandbox_id: int, task_id?: int}|null
     */
    public function parse(string $host): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $host = strtolower(explode(':', $host)[0]);
        $domain = preg_quote(strtolower((string) $this->domain), '/');

        if (preg_match('/^('.implode('|', self::KINDS).')-(\d+)\.'.$domain.'$/', $host, $matches)) {
            return ['kind' => $matches[1], 'sandbox_id' => (int) $matches[2]];
        }

        // A pull request's preview: its task's copy, whichever it is now (GIT-027).
        if ($this->isPullRequestHost($host)) {
            $task = $this->pullRequestTask($host);
            $copy = $task?->sandbox()->first();

            return $task && $copy
                ? ['kind' => self::PULL_REQUEST, 'sandbox_id' => $copy->id, 'task_id' => $task->id]
                : null;
        }

        $project = $this->publishedProject($host);

        return $project ? ['kind' => self::APP, 'sandbox_id' => $project->sandbox->id] : null;
    }

    /**
     * Whether a host is a pull request's preview address (GIT-027), whether or not it has a preview now.
     */
    public function isPullRequestHost(string $host): bool
    {
        $host = strtolower(explode(':', $host)[0]);

        return $this->enabled() && (bool) preg_match('/^pr-\d+-[a-z0-9-]+-\d+\.'.preg_quote(strtolower((string) $this->domain), '/').'$/', $host);
    }

    /**
     * The pull request's task a preview address is for, with or without a copy of the app. The name in the address is
     * only for reading: links keep working when the project is renamed.
     */
    public function pullRequestTask(string $host): ?Task
    {
        $host = strtolower(explode(':', $host)[0]);

        if (! $this->isPullRequestHost($host) || ! preg_match('/^pr-(\d+)-[a-z0-9-]+-(\d+)\./', $host, $matches)) {
            return null;
        }

        return Task::query()->where('project_id', (int) $matches[2])->where('pull_request_number', (int) $matches[1])->with('project')->first();
    }

    /**
     * The project live on the domain at this host: its own address (<name>-<id>.<domain>) or a custom domain
     * connected through the gateway.
     */
    public function publishedProject(string $host): ?Project
    {
        $host = strtolower(explode(':', $host)[0]);
        $domain = preg_quote(strtolower((string) $this->domain), '/');

        if (preg_match('/^[a-z0-9-]+-(\d+)\.'.$domain.'$/', $host, $matches)) {
            $project = Project::with('sandbox')->find((int) $matches[1]);
            $ours = $project && $project->published_default_url === "https://{$host}";
        } else {
            $project = ProjectDomain::with('project.sandbox')
                ->where('hostname', $host)
                ->whereIn('via', ['caddy', 'cloudflare-saas'])
                ->first()?->project;
            $ours = $project !== null;
        }

        $live = $ours
            && $project->publish_target === PublishTarget::Domain
            && $project->publish_status === PublishStatus::Live
            && $project->sandbox;

        return $live ? $project : null;
    }

    /**
     * Where a request to a published app on this host should go instead: its primary custom domain, once that's what
     * the project is published at (DOM-002). Null when the host is the one to use.
     */
    public function canonicalRedirect(Project $project, string $host, string $path): ?string
    {
        $host = strtolower(explode(':', $host)[0]);
        $primary = $project->published_url;

        if ($primary === null || $primary === $project->published_default_url || parse_url($primary, PHP_URL_HOST) === $host) {
            return null;
        }

        return $primary.$path;
    }

    /**
     * Where Caddy should send gateway traffic for a sandbox: the host:port its provider published.
     */
    public function upstream(Sandbox $sandbox, string $kind): ?string
    {
        $url = $kind === 'shell' ? $sandbox->shell_url : $sandbox->preview_url;
        $parts = $url ? parse_url($url) : null;

        return isset($parts['host'], $parts['port']) ? "{$parts['host']}:{$parts['port']}" : null;
    }

    /**
     * Where the browser trades a hand-off token for the address's own cookie, then lands on $path. A partitioned
     * cookie is for a frame on another site (the desktop app, DESK-002), where WebKit stores no other.
     */
    public function enterUrl(Sandbox $sandbox, string $kind, User $user, string $path = '/', bool $partitioned = false): string
    {
        $token = Crypt::encryptString(json_encode(array_filter([
            'user' => $user->id,
            'sandbox' => $sandbox->id,
            'kind' => $kind,
            'expires' => now()->addSeconds(self::TOKEN_SECONDS)->getTimestamp(),
            'partitioned' => $partitioned,
        ]), JSON_THROW_ON_ERROR));

        return $this->url($sandbox, $kind).'/__onedrop/enter?'.http_build_query(['token' => $token, 'path' => $path]);
    }

    /**
     * The user id in a valid hand-off token for this address, or null.
     *
     * @param  array{kind: string, sandbox_id: int}  $target
     */
    public function userFromToken(string $token, array $target): ?int
    {
        $data = $this->handOff($token);

        return $this->matches($data, $target) ? (int) $data['user'] : null;
    }

    /**
     * Whether a hand-off token asks for a partitioned cookie (see enterUrl()).
     */
    public function wantsPartitionedPass(string $token): bool
    {
        $data = $this->handOff($token);

        return is_array($data) && ($data['partitioned'] ?? false) === true;
    }

    protected function handOff(string $token): mixed
    {
        try {
            return json_decode(Crypt::decryptString($token), true);
        } catch (DecryptException) {
            return null;
        }
    }

    /**
     * The cookie value that lets a user through to this address.
     *
     * @param  array{kind: string, sandbox_id: int}  $target
     */
    public function pass(int $userId, array $target): string
    {
        return json_encode([
            'user' => $userId,
            'sandbox' => $target['sandbox_id'],
            'kind' => $target['kind'],
            'expires' => now()->addMinutes(self::PASS_MINUTES)->getTimestamp(),
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * The user id in a valid (already decrypted) pass cookie for this address, or null.
     *
     * @param  array{kind: string, sandbox_id: int}  $target
     */
    public function userFromPass(?string $pass, array $target): ?int
    {
        $data = $pass ? json_decode($pass, true) : null;

        return $this->matches($data, $target) ? (int) $data['user'] : null;
    }

    /**
     * Whether a token or pass is unexpired and belongs to this address.
     *
     * @param  array{kind: string, sandbox_id: int}  $target
     */
    protected function matches(mixed $data, array $target): bool
    {
        return is_array($data)
            && isset($data['user'], $data['sandbox'], $data['kind'], $data['expires'])
            && $data['sandbox'] === $target['sandbox_id']
            && $data['kind'] === $target['kind']
            && $data['expires'] >= now()->getTimestamp();
    }
}
