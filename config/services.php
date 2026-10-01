<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Log in with...
    |--------------------------------------------------------------------------
    |
    | OAuth apps for logging in with Google, Microsoft, GitHub, GitLab, or any
    | OpenID Connect issuer (Okta, Keycloak, Authentik, ...). A provider's
    | button only shows once its client ID and secret are set. The callback
    | URL to register with each is https://your-domain/login/{provider}/callback.
    | GitHub falls back to the GitHub App's client ID and secret below, so one
    | GitHub App can do both (add the login callback URL to it too).
    |
    */

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => '/login/google/callback',
    ],

    'microsoft' => [
        'client_id' => env('MICROSOFT_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        'redirect' => '/login/microsoft/callback',
        'tenant' => env('MICROSOFT_TENANT', 'common'),
    ],

    'github' => [
        'client_id' => env('GITHUB_CLIENT_ID') ?: env('GITHUB_APP_CLIENT_ID'),
        'client_secret' => env('GITHUB_CLIENT_SECRET') ?: env('GITHUB_APP_CLIENT_SECRET'),
        'redirect' => '/login/github/callback',
    ],

    'gitlab' => [
        'client_id' => env('GITLAB_CLIENT_ID'),
        'client_secret' => env('GITLAB_CLIENT_SECRET'),
        'redirect' => '/login/gitlab/callback',
        'host' => env('GITLAB_HOST', 'https://gitlab.com'),
    ],

    'openidconnect' => [
        'label' => env('OIDC_LABEL', 'Single sign-on'),
        'base_url' => env('OIDC_BASE_URL'),
        'client_id' => env('OIDC_CLIENT_ID'),
        'client_secret' => env('OIDC_CLIENT_SECRET'),
        'redirect' => '/login/oidc/callback',
    ],

    /*
    |--------------------------------------------------------------------------
    | GitHub App (Tools → Git)
    |--------------------------------------------------------------------------
    |
    | Lets people connect repositories by installing your GitHub App instead of
    | pasting tokens. Create it at github.com/settings/apps/new with:
    | Callback URL and Setup URL https://your-domain/github/callback
    | ("Redirect on update" and "Request user authorization during
    | installation" on), no webhook, and repository permissions Contents
    | (read and write) and Metadata (read). The private key is the PEM
    | GitHub generates (newlines may be written as \n).
    |
    */

    // Jev, OpenRouter's decision model, checks that the agent kept the requirements and tests (TEST-007). Used
    // when the project's owner has no OpenRouter connection of their own (or Nimble on their own Ollama server,
    // AI-007); without any, there's no check.
    'openrouter' => [
        'key' => env('OPENROUTER_API_KEY'),
        'jev_model' => env('JEV_MODEL', 'typesafe/jev-1.13'),
    ],

    'github_app' => [
        'id' => env('GITHUB_APP_ID'),
        'slug' => env('GITHUB_APP_SLUG'),
        'client_id' => env('GITHUB_APP_CLIENT_ID'),
        'client_secret' => env('GITHUB_APP_CLIENT_SECRET'),
        'private_key' => env('GITHUB_APP_PRIVATE_KEY'),
    ],

];
