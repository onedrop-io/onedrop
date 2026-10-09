<?php

namespace App\Sandbox;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Checks a GitHub Actions OIDC token: signed by GitHub, for this app (its audience is APP_URL), from a workflow run on
 * the main branch of the repository that publishes the sandbox image (SANDBOX_IMAGES_REPOSITORY). So a workflow can
 * tell the app something happened without a shared secret to set up or keep in sync.
 */
class GitHubActionsToken
{
    public const ISSUER = 'https://token.actions.githubusercontent.com';

    /** How long GitHub's signing keys are kept before they're fetched again. */
    protected const KEYS_SECONDS = 3600;

    /**
     * The token's claims, or null when it isn't one we trust.
     *
     * @return array<string, mixed>|null
     */
    public function verify(string $token): ?array
    {
        try {
            $claims = (array) JWT::decode($token, JWK::parseKeySet($this->keys()));
        } catch (Throwable) {
            // A key GitHub rotated in since: fetch them again once.
            try {
                Cache::forget('github-actions-jwks');
                $claims = (array) JWT::decode($token, JWK::parseKeySet($this->keys()));
            } catch (Throwable) {
                return null;
            }
        }

        $audience = (array) ($claims['aud'] ?? []);

        return ($claims['iss'] ?? null) === self::ISSUER
            && in_array(rtrim((string) config('app.url'), '/'), $audience, true)
            && ($claims['repository'] ?? null) === config('sandbox.images_repository')
            && ($claims['ref'] ?? null) === 'refs/heads/main'
                ? $claims
                : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function keys(): array
    {
        return Cache::remember('github-actions-jwks', self::KEYS_SECONDS, fn () => Http::timeout(10)->get(self::ISSUER.'/.well-known/jwks')->throw()->json());
    }
}
