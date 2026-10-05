<?php

namespace App\Sandbox;

use App\Models\Sandbox;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * The desktop app's tunnel into a sandbox (DESK-007..009): WebSockets through the preview's gateway address to
 * docker/sandbox/tunnel.mjs, each opened with a ticket signed with a key only the app and that sandbox know.
 * See docs/development/desktop-link.mdx.
 */
class DesktopTunnel
{
    /** How long a ticket can open a WebSocket for. */
    public const TICKET_SECONDS = 60;

    /** The path the sandbox's proxy hands to the tunnel. */
    public const PATH = '/__onedrop/tunnel';

    /** How long the app trusts that a sandbox's tunnel is running before checking again. */
    protected const ENSURED_SECONDS = 600;

    protected const SCRIPT = SandboxTools::PATH.'/tunnel';

    public function __construct(protected SandboxProvider $provider, protected Gateway $gateway) {}

    /**
     * The sandbox's tunnel key: derived from the app key, so it's never stored, and new for each sandbox it moves to.
     */
    public static function key(Sandbox $sandbox): string
    {
        return hash_hmac('sha256', "tunnel:{$sandbox->id}:{$sandbox->external_id}", (string) config('app.key'));
    }

    /**
     * A ticket for what the desktop app wants: "forward" (with the sandbox port) or "network".
     */
    public function ticket(Sandbox $sandbox, User $user, string $purpose, ?int $port = null): string
    {
        return self::sign(self::key($sandbox), array_filter([
            'p' => $purpose,
            'port' => $port,
            'exp' => now()->addSeconds(self::TICKET_SECONDS)->getTimestamp(),
            'u' => $user->id,
        ], fn ($value) => $value !== null));
    }

    /**
     * Sign a payload: base64url(json) "." base64url(HMAC-SHA256(key, base64url(json))).
     *
     * @param  array<string, mixed>  $payload
     */
    public static function sign(string $key, array $payload): string
    {
        $body = self::base64url(json_encode($payload, JSON_THROW_ON_ERROR));

        return $body.'.'.self::base64url(hash_hmac('sha256', $body, $key, true));
    }

    /**
     * The payload of a ticket signed with this key that hasn't expired, or null.
     *
     * @return array<string, mixed>|null
     */
    public static function verify(string $key, string $ticket): ?array
    {
        [$body, $signature] = array_pad(explode('.', $ticket, 2), 2, '');

        if ($body === '' || ! hash_equals(self::base64url(hash_hmac('sha256', $body, $key, true)), $signature)) {
            return null;
        }

        $payload = json_decode((string) base64_decode(strtr($body, '-_', '+/'), true), true);

        return is_array($payload) && is_int($payload['exp'] ?? null) && $payload['exp'] >= now()->getTimestamp() ? $payload : null;
    }

    /**
     * Whether a request to the gateway is a tunnel WebSocket this sandbox's key opens, so it may pass without the
     * address's cookie: a valid ticket, or a dial (whose one-time token the tunnel itself checks against what it handed
     * the control connection, which needed a ticket).
     */
    public function allows(Sandbox $sandbox, string $uri): bool
    {
        if (parse_url($uri, PHP_URL_PATH) !== self::PATH) {
            return false;
        }

        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

        if (is_string($query['dial'] ?? null) && is_string($query['token'] ?? null)) {
            return true;
        }

        return is_string($query['ticket'] ?? null) && self::verify(self::key($sandbox), $query['ticket']) !== null;
    }

    /**
     * Where the desktop app opens the tunnel: the preview's gateway address, or (without a gateway) its own.
     */
    public function url(Sandbox $sandbox, string $ticket): ?string
    {
        $base = $this->gateway->url($sandbox, 'preview') ?? $sandbox->preview_url;
        $parts = $base ? parse_url($base) : null;

        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = $parts['scheme'] === 'https' ? 'wss' : 'ws';
        $port = isset($parts['port']) ? ":{$parts['port']}" : '';

        return "{$scheme}://{$parts['host']}{$port}".self::PATH.'?'.http_build_query(['ticket' => $ticket]);
    }

    /**
     * Make sure the sandbox's tunnel is running with its key. Checked again at most every ten minutes per sandbox.
     *
     * @throws SandboxException
     */
    public function ensure(Sandbox $sandbox): void
    {
        $cacheKey = "desktop-tunnel:{$sandbox->id}:{$sandbox->external_id}";

        if (Cache::has($cacheKey)) {
            return;
        }

        $result = $this->provider->exec($sandbox->external_id, [self::SCRIPT, 'ensure'], ['ONEDROP_TUNNEL_KEY' => self::key($sandbox)]);

        if (! $result->successful()) {
            throw new SandboxException(__("Couldn't start the sandbox's tunnel. Its image may be too old; it updates once the project sits unused."));
        }

        Cache::put($cacheKey, true, self::ENSURED_SECONDS);
    }

    protected static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
