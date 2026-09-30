<?php

namespace App\Sandbox;

use App\Models\SystemSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

/**
 * The server's address and certificates (ADMIN-004). On a server install the app can't change them itself (Caddy
 * and systemd run as root), so it writes the request to a file that infra/server/apply-server-settings.sh, started
 * by a systemd path unit, applies, writing back how it went.
 */
class ServerSettings
{
    public const SETTING = 'server';

    public const CERTIFICATES = ['letsencrypt', 'local'];

    /**
     * How this copy was installed: "server", "container", or null.
     */
    public function install(): ?string
    {
        $install = config('app.install');

        return in_array($install, ['server', 'container'], true) ? $install : null;
    }

    /**
     * Whether admins can change the address here.
     */
    public function editable(): bool
    {
        return $this->install() === 'server';
    }

    /**
     * The address the app is served at now.
     *
     * @return array{url: string, domain: string, https: bool, gateway: bool}
     */
    public function current(): array
    {
        $url = (string) config('app.url');

        return [
            'url' => $url,
            'domain' => (string) (config('sandbox.gateway_domain') ?: parse_url($url, PHP_URL_HOST)),
            'https' => str_starts_with($url, 'https://'),
            'gateway' => filled(config('sandbox.gateway_domain')),
        ];
    }

    /**
     * The last change an admin asked for, and how applying it went.
     *
     * @return array{domain: string|null, email: string|null, certificates: string, requested_at: string|null, status: 'pending'|'applied'|'failed'|null, message: string|null}
     */
    public function requested(): array
    {
        $saved = SystemSetting::group(self::SETTING);
        $status = $this->status();
        $requestedAt = $saved['requested_at'] ?? null;
        $answered = $requestedAt !== null && ($status['requested_at'] ?? null) === $requestedAt;

        return [
            'domain' => $saved['domain'] ?? null,
            'email' => $saved['email'] ?? null,
            'certificates' => $saved['certificates'] ?? 'letsencrypt',
            'requested_at' => $requestedAt,
            'status' => $requestedAt === null ? null : ($answered ? (($status['ok'] ?? false) ? 'applied' : 'failed') : 'pending'),
            'message' => $answered ? ($status['message'] ?? null) : null,
        ];
    }

    /**
     * Ask the server to serve the app at a domain, with certificates from Let's Encrypt (notices to $email) or its own CA.
     */
    public function request(string $domain, ?string $email, string $certificates): void
    {
        $request = [
            'domain' => strtolower($domain),
            'email' => filled($email) ? $email : null,
            'certificates' => $certificates,
            'requested_at' => Carbon::now()->toIso8601String(),
        ];

        SystemSetting::put(self::SETTING, $request);

        File::ensureDirectoryExists(dirname(self::requestPath()));
        // Written whole then moved into place, so the applier never reads half a file.
        File::put(self::requestPath().'.tmp', json_encode($request, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        File::move(self::requestPath().'.tmp', self::requestPath());
    }

    /**
     * Where the request is written (watched by onedrop-server-settings.path on a server install).
     */
    public static function requestPath(): string
    {
        return storage_path('app/server-settings.json');
    }

    /**
     * Where the applier writes how it went.
     */
    public static function statusPath(): string
    {
        return storage_path('app/server-settings.status.json');
    }

    /**
     * @return array<string, mixed>
     */
    protected function status(): array
    {
        $status = File::exists(self::statusPath()) ? json_decode((string) File::get(self::statusPath()), true) : null;

        return is_array($status) ? $status : [];
    }
}
