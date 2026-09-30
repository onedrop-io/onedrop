<?php

namespace App\Sandbox;

use App\Enums\PublishStatus;
use App\Enums\PublishTarget;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

/**
 * Public addresses for sandbox previews and shells on a server:
 * preview-<sandbox id>.<gateway domain> and shell-<sandbox id>.<gateway domain>,
 * plus projects published to the domain (DomainPublisher): <name>-<project id>.<gateway domain>.
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
    ];

    public function __construct(protected ?string $domain, protected ?string $secret = null) {}

    public function enabled(): bool
    {
        return filled($this->domain);
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

        return $kind === self::APP
            ? $sandbox->project?->published_url
            : "https://{$kind}-{$sandbox->id}.{$this->domain}";
    }

    /**
     * The address a project gets when published to the domain: <name>-<id>.<domain>. A name that would read as a
     * preview or shell address (a project called "Preview") gets an "app-" prefix.
     */
    public function publishedHost(Project $project): string
    {
        $label = $project->publishHostname();

        if (preg_match('/^('.implode('|', self::KINDS).')-\d+$/', $label)) {
            $label = "app-{$label}";
        }

        return "{$label}.".strtolower((string) $this->domain);
    }

    /**
     * Parse a gateway hostname into its kind and sandbox id: a preview or shell, or a project published to the domain
     * (kind "app", its main sandbox), while it's live there.
     *
     * @return array{kind: string, sandbox_id: int}|null
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

        if (! preg_match('/^[a-z0-9-]+-(\d+)\.'.$domain.'$/', $host, $matches)) {
            return null;
        }

        $project = Project::with('sandbox')->find((int) $matches[1]);

        $live = $project
            && $project->publish_target === PublishTarget::Domain
            && $project->publish_status === PublishStatus::Live
            && $project->published_url === "https://{$host}"
            && $project->sandbox;

        return $live ? ['kind' => self::APP, 'sandbox_id' => $project->sandbox->id] : null;
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
     * Where the browser trades a hand-off token for the address's own cookie, then lands on $path.
     */
    public function enterUrl(Sandbox $sandbox, string $kind, User $user, string $path = '/'): string
    {
        $token = Crypt::encryptString(json_encode([
            'user' => $user->id,
            'sandbox' => $sandbox->id,
            'kind' => $kind,
            'expires' => now()->addSeconds(self::TOKEN_SECONDS)->getTimestamp(),
        ], JSON_THROW_ON_ERROR));

        return $this->url($sandbox, $kind).'/__onedrop/enter?'.http_build_query(['token' => $token, 'path' => $path]);
    }

    /**
     * The user id in a valid hand-off token for this address, or null.
     *
     * @param  array{kind: string, sandbox_id: int}  $target
     */
    public function userFromToken(string $token, array $target): ?int
    {
        try {
            $data = json_decode(Crypt::decryptString($token), true);
        } catch (DecryptException) {
            return null;
        }

        return $this->matches($data, $target) ? (int) $data['user'] : null;
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
