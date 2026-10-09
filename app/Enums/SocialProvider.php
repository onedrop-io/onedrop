<?php

namespace App\Enums;

use Illuminate\Support\Arr;
use Laravel\Socialite\Contracts\User as SocialiteUser;

/**
 * Identity providers people can log in with. Each is only offered once the
 * server has its OAuth client credentials in config/services.php.
 */
enum SocialProvider: string
{
    case Google = 'google';
    case Microsoft = 'microsoft';
    case GitHub = 'github';
    case GitLab = 'gitlab';
    case Oidc = 'oidc';

    /** What GitHub is asked for on top of sign-in, so the person's projects can download their private packages (GIT-016). */
    public const GITHUB_PACKAGES_SCOPE = 'read:packages';

    /**
     * Human-readable name.
     */
    public function label(): string
    {
        return match ($this) {
            self::Google => 'Google',
            self::Microsoft => 'Microsoft',
            self::GitHub => 'GitHub',
            self::GitLab => 'GitLab',
            self::Oidc => config('services.openidconnect.label') ?: 'Single sign-on',
        };
    }

    /**
     * The Socialite driver (and config/services.php key) for this provider.
     */
    public function driver(): string
    {
        return match ($this) {
            self::Oidc => 'openidconnect',
            default => $this->value,
        };
    }

    /**
     * What to ask for beyond the driver's own sign-in scopes. A GitHub App ignores scopes, so with no separate OAuth
     * app (`GITHUB_CLIENT_ID`) GitHub sign-in still only signs in.
     *
     * @return list<string>
     */
    public function scopes(): array
    {
        return $this === self::GitHub ? [self::GITHUB_PACKAGES_SCOPE] : [];
    }

    /**
     * Whether GitHub sign-in is its own OAuth app, which can be granted package access; the GitHub App it otherwise
     * shares can't be (GIT-016).
     */
    public static function gitHubCanGrantPackages(): bool
    {
        return self::GitHub->isConfigured() && config('services.github.client_id') !== config('services.github_app.client_id');
    }

    /**
     * Whether the server has client credentials for this provider.
     */
    public function isConfigured(): bool
    {
        $config = config('services.'.$this->driver());

        if ($this === self::Oidc && blank($config['base_url'] ?? null)) {
            return false;
        }

        return filled($config['client_id'] ?? null) && filled($config['client_secret'] ?? null);
    }

    /**
     * Whether the provider vouches that the user owns their email address.
     * Microsoft (Entra ID) email claims are editable by tenant admins, so
     * they are never trusted.
     */
    public function emailIsVerified(SocialiteUser $user): bool
    {
        $raw = method_exists($user, 'getRaw') ? $user->getRaw() : [];

        return match ($this) {
            self::Google, self::Oidc => filter_var(Arr::get($raw, 'email_verified'), FILTER_VALIDATE_BOOLEAN),
            // Socialite only returns GitHub's primary, verified address.
            self::GitHub => filled($user->getEmail()),
            self::GitLab => filled(Arr::get($raw, 'confirmed_at')),
            self::Microsoft => false,
        };
    }

    /**
     * Providers the server has credentials for.
     *
     * @return list<self>
     */
    public static function configured(): array
    {
        return array_values(array_filter(self::cases(), fn (self $provider) => $provider->isConfigured()));
    }

    /**
     * The configured providers as props for the log-in and sign-up pages.
     *
     * @return list<array{id: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $provider) => [
            'id' => $provider->value,
            'label' => $provider->label(),
        ], self::configured());
    }
}
