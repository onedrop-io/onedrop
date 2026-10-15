<?php

namespace App\Actions\Auth;

use Firebase\JWT\JWT;
use SocialiteProviders\Apple\Provider;

/**
 * Sign in with Apple, with a client secret Apple accepts. The package's own has fractional `iat`/`exp` (lcobucci/jwt
 * keeps microseconds), which Apple rejects as `invalid_client` when the code is exchanged.
 */
class AppleProvider extends Provider
{
    /**
     * A client secret signed with the Sign in with Apple key, good for an hour.
     */
    protected function getClientSecret(): string
    {
        $key = (string) $this->getConfig('private_key', '');

        if ($key !== '' && is_file($key)) {
            $key = (string) file_get_contents($key);
        }

        $now = time();

        return $this->clientSecret = JWT::encode([
            'iss' => config('services.apple.team_id'),
            'iat' => $now,
            'exp' => $now + 3600,
            'aud' => self::URL,
            'sub' => $this->clientId,
        ], $key, 'ES256', config('services.apple.key_id'));
    }
}
