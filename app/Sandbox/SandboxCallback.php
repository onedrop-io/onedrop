<?php

namespace App\Sandbox;

use App\Enums\SandboxStatus;
use App\Models\Sandbox;
use Illuminate\Support\Facades\Cache;

/**
 * The address code in a sandbox reaches the app at (SBX-015): agent events, the file watcher, `ask`, Drive, and
 * "Sign in with OneDrop". A sandbox on this machine (Docker, a computer) uses SANDBOX_CALLBACK_URL as it is; one
 * in the cloud can't reach a private address like http://host.docker.internal:8000, so it gets the public tunnel
 * `composer run dev` opens (`sandbox:tunnel`) instead, or nothing.
 */
class SandboxCallback
{
    /** Where `sandbox:tunnel` keeps the public address while it runs. */
    public const TUNNEL_CACHE_KEY = 'sandbox.tunnel_url';

    /** Where a cloud sandbox finds the tunnel's current address; its tools put it in place of the one they were given. */
    public const SANDBOX_FILE = '/etc/onedrop-callback';

    /** Providers whose sandboxes run on this machine, so they reach it at private addresses. */
    protected const LOCAL_PROVIDERS = ['docker', 'device', 'fake'];

    /**
     * The address a sandbox on this provider can reach the app at, or null when it has none.
     */
    public function reachableUrl(string $provider): ?string
    {
        $configured = $this->configuredUrl();

        if (in_array($provider, self::LOCAL_PROVIDERS, true) || self::isPublic($configured)) {
            return $configured;
        }

        $tunnel = Cache::get(self::TUNNEL_CACHE_KEY);

        return is_string($tunnel) && $tunnel !== '' ? rtrim($tunnel, '/') : null;
    }

    /**
     * The best address for a sandbox on this provider: the reachable one, else SANDBOX_CALLBACK_URL as it is.
     */
    public function url(string $provider): string
    {
        return $this->reachableUrl($provider) ?? $this->configuredUrl();
    }

    /**
     * Why a sandbox on this provider can't report back, if it can't.
     */
    public function problem(string $provider): ?string
    {
        if ($this->reachableUrl($provider) !== null) {
            return null;
        }

        return "This sandbox runs in the cloud ({$provider}) and can't reach OneDrop at {$this->configuredUrl()}, so I'd never hear back from the agent. "
            .'Run `composer run dev` with cloudflared installed (it opens a tunnel for cloud sandboxes), or set SANDBOX_CALLBACK_URL to a public address, then send your message again.';
    }

    /**
     * Give a cloud sandbox the tunnel's current address, so its agent, file watcher, Drive and `ask` keep reaching the
     * app after the tunnel restarts with a new one. Nothing to do for a public SANDBOX_CALLBACK_URL, which never changes.
     *
     * @throws SandboxException
     */
    public function announce(Sandbox $sandbox): void
    {
        if (in_array($sandbox->provider, self::LOCAL_PROVIDERS, true) || $sandbox->external_id === null || self::isPublic($this->configuredUrl())) {
            return;
        }

        app(SandboxProvider::class)->exec($sandbox->external_id, ['sh', '-c', 'printf %s "$1" > '.self::SANDBOX_FILE, 'sh', $this->reachableUrl($sandbox->provider) ?? ''], root: true);
    }

    /**
     * Give every awake cloud sandbox the tunnel's current address (a new tunnel just started). Asleep ones get it on
     * their next agent run, rather than being woken for it.
     */
    public function announceToAll(): void
    {
        Sandbox::query()
            ->whereNotIn('provider', self::LOCAL_PROVIDERS)
            ->where('status', SandboxStatus::Running)
            ->whereNotNull('external_id')
            ->whereNull('suspended_at')
            ->whereNull('stopped_at')
            ->each(function (Sandbox $sandbox): void {
                try {
                    $this->announce($sandbox);
                } catch (SandboxException) {
                    // It may have just gone to sleep; its next agent run tells it.
                }
            });
    }

    public function configuredUrl(): string
    {
        return rtrim((string) config('sandbox.callback_url'), '/');
    }

    /**
     * Whether an address is reachable from the internet: not this machine, Docker's host, or a private network.
     */
    public static function isPublic(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '' || $host === 'localhost' || ! str_contains($host, '.')) {
            return false;
        }

        foreach (['.localhost', '.test', '.local', '.internal', '.lan', '.home.arpa'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return false;
            }
        }

        $ip = trim($host, '[]');

        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        return true;
    }
}
