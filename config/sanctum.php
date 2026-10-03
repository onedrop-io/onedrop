<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;

/*
 * The API (`/api/v1`, DESK-001) takes bearer tokens only: the desktop app's. The web app keeps its session and never
 * calls the API, so no domain gets cookie authentication and no guard is checked before the token.
 */
return [

    'stateful' => [],

    'guard' => [],

    /*
     * Tokens last until the user signs out of the desktop app or revokes it in Settings.
     */
    'expiration' => null,

    /*
     * Lets secret scanners (GitHub's, for one) recognize a token committed by mistake.
     */
    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', 'onedrop_'),

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
