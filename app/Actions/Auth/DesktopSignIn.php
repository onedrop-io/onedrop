<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Sanctum\NewAccessToken;

/**
 * Signing in to the desktop app (DESK-001): the app opens this site in the browser, where the signed-in user approves
 * it, and the browser goes back to the app's loopback address (RFC 8252) with a one-time code. The app trades the code
 * and its PKCE verifier for an API token, so only the app that started the sign-in can finish it.
 */
class DesktopSignIn
{
    /** How long a code can wait to be traded for a token. */
    public const CODE_SECONDS = 300;

    /** The token's ability: everything the desktop app does. */
    public const ABILITY = 'desktop';

    /**
     * Whether the address is the app's own loopback callback: `http://127.0.0.1:<port>/callback` (or `localhost`, `[::1]`).
     * Nothing else is ever redirected to, so a code can't leave the user's computer.
     */
    public function isLoopback(string $redirectUri): bool
    {
        return (bool) preg_match('#^http://(127\.0\.0\.1|localhost|\[::1\]):([1-9][0-9]{0,4})/callback$#', $redirectUri, $match)
            && (int) $match[2] <= 65535;
    }

    /**
     * Whether the challenge is an S256 PKCE challenge: a base64url SHA-256 hash.
     */
    public function isChallenge(?string $challenge, ?string $method): bool
    {
        return $method === 'S256' && is_string($challenge) && (bool) preg_match('/^[A-Za-z0-9_-]{43}$/', $challenge);
    }

    /**
     * A one-time code for the user, valid for CODE_SECONDS, bound to the address it's sent to and the PKCE challenge.
     */
    public function issueCode(User $user, string $redirectUri, string $challenge, string $device): string
    {
        $code = Str::random(48);

        Cache::put($this->cacheKey($code), [
            'user_id' => $user->id,
            'redirect_uri' => $redirectUri,
            'challenge' => $challenge,
            'device' => $device,
        ], self::CODE_SECONDS);

        return $code;
    }

    /**
     * Trade a code (once) for an API token named after the device, or null when it's unknown, expired, sent to another
     * address, or the verifier doesn't match its challenge.
     */
    public function exchange(string $code, string $verifier, string $redirectUri): ?NewAccessToken
    {
        $grant = Cache::pull($this->cacheKey($code));

        if (! is_array($grant) || ! hash_equals($grant['redirect_uri'], $redirectUri) || ! hash_equals($grant['challenge'], self::challengeFor($verifier))) {
            return null;
        }

        $user = User::find((int) $grant['user_id']);

        return $user?->createToken($grant['device'], [self::ABILITY]);
    }

    /**
     * The S256 challenge for a PKCE verifier.
     */
    public static function challengeFor(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    protected function cacheKey(string $code): string
    {
        return 'desktop-sign-in:'.hash('sha256', $code);
    }
}
