<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Hosting Providers
    |--------------------------------------------------------------------------
    |
    | Where published apps run off their sandbox (HOST-001..003): Fly.io for
    | apps with a server, Cloudflare for front ends and R2 buckets, Neon for
    | Postgres and Upstash for Redis. These are the install's own accounts;
    | admins can set them in Settings → Hosting (ADMIN-007), which wins over
    | `.env`, and organizations can connect their own instead.
    |
    */

    'providers' => [
        'fly' => [
            'enabled' => (bool) env('HOSTING_FLY_ENABLED', false),
            'api_token' => env('FLY_API_TOKEN'),
            'org_slug' => env('FLY_ORG', 'personal'),
            'region' => env('FLY_REGION', 'iad'),
            // The image hosted apps run on: the sandbox's own, so an app runs the way it does in its preview. Fly pulls
            // it, so it must be public (a local install's onedrop-sandbox image isn't).
            'base_image' => env('HOSTING_BASE_IMAGE', 'ghcr.io/onedrop-io/onedrop-sandbox:latest'),
            'memory_mb' => (int) env('FLY_MEMORY_MB', 1024),
            'volume_gb' => (int) env('FLY_VOLUME_GB', 1),
        ],
        'cloudflare' => [
            'enabled' => (bool) env('HOSTING_CLOUDFLARE_ENABLED', false),
            'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
            'api_token' => env('CLOUDFLARE_API_TOKEN'),
        ],
        'neon' => [
            'enabled' => (bool) env('HOSTING_NEON_ENABLED', false),
            'api_key' => env('NEON_API_KEY'),
            'org_id' => env('NEON_ORG_ID'),
            'region' => env('NEON_REGION', 'aws-us-east-1'),
        ],
        'upstash' => [
            'enabled' => (bool) env('HOSTING_UPSTASH_ENABLED', false),
            'email' => env('UPSTASH_EMAIL'),
            'api_key' => env('UPSTASH_API_KEY'),
            'region' => env('UPSTASH_REGION', 'us-east-1'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Crane
    |--------------------------------------------------------------------------
    |
    | The builder machine adds a release's files to the base image with
    | crane (go-containerregistry), downloaded at this version when it runs.
    |
    */

    'crane_version' => env('HOSTING_CRANE_VERSION', 'v0.20.6'),

    /*
    |--------------------------------------------------------------------------
    | Litestream
    |--------------------------------------------------------------------------
    |
    | Hosted apps back their SQLite databases up continuously to their own R2
    | bucket with Litestream, downloaded at this version on first boot.
    |
    */

    'litestream_version' => env('HOSTING_LITESTREAM_VERSION', 'v0.3.13'),

];
