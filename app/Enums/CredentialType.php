<?php

namespace App\Enums;

enum CredentialType: string
{
    case ApiKey = 'api_key';

    /** A subscription token, e.g. from `claude setup-token`. */
    case OAuthToken = 'oauth_token';

    /** Tokens from signing in with ChatGPT (a JSON bundle), refreshed by the platform. */
    case ChatGpt = 'chatgpt';
}
