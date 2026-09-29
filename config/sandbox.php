<?php

use App\Sandbox\Agents\HarnessRunner;

return [

    /*
    |--------------------------------------------------------------------------
    | Sandbox Provider
    |--------------------------------------------------------------------------
    |
    | Where each project's sandbox runs: "docker" (local development), or
    | microVMs in production: "blaxel" (Blaxel) or "runtime" (Runtime Cloud).
    | "fake" is used by the test suite.
    |
    */

    'provider' => env('SANDBOX_PROVIDER', 'docker'),

    'providers' => [
        'docker' => [
            'image' => env('SANDBOX_DOCKER_IMAGE', 'zap-sandbox:latest'),
            'memory' => env('SANDBOX_DOCKER_MEMORY', '2g'),
            'cpus' => env('SANDBOX_DOCKER_CPUS', '2'),
            // Host the browser uses to reach published container ports.
            'host' => env('SANDBOX_DOCKER_HOST', '127.0.0.1'),
            // Optional container runtime, e.g. "runsc" for gVisor isolation on Linux servers.
            'runtime' => env('SANDBOX_DOCKER_RUNTIME'),
            // Host folder holding each project's App Storage buckets (<path>/project-<id>/storage), mounted
            // into its sandbox at /data/storage so they survive the container. Empty keeps them in the container.
            'storage_path' => env('SANDBOX_DOCKER_STORAGE_PATH', storage_path('app/sandboxes')),
        ],

        // Runtime Cloud (withruntime.com). The image is docker/sandbox, built there by `php artisan sandbox:build-image`.
        'runtime' => [
            'api_key' => env('RUNTIME_API_KEY'),
            'url' => env('RUNTIME_API_URL', 'https://api.withruntime.com'),
            'image' => env('RUNTIME_IMAGE', 'zap-sandbox:latest'),
            // "trial" (the free hours) until the account has credit and you choose "paid".
            'funding' => env('RUNTIME_FUNDING', 'trial'),
            'vcpu' => (int) env('RUNTIME_VCPU', 2),
            'memory_mib' => (int) env('RUNTIME_MEMORY_MIB', 4096),
            'disk_mib' => (int) env('RUNTIME_DISK_MIB', 8192),
            // Each lease runs up to an hour, then the sandbox pauses (memory kept) until the next request wakes it.
            'timeout_seconds' => (int) env('RUNTIME_TIMEOUT_SECONDS', 3600),
            // Paid only: renew the lease while credit lasts, so long agent runs are never paused mid-way.
            'persistent' => (bool) env('RUNTIME_PERSISTENT', false),
            // "private" previews carry a token in a cookie browsers won't send inside another site's frame, so they only
            // open in their own tab; "public" (paid only) shows them in the workspace's Preview tab.
            'preview_visibility' => env('RUNTIME_PREVIEW_VISIBILITY', 'private'),
        ],

        // Blaxel (blaxel.ai). The image is docker/sandbox plus docker/sandbox/blaxel, pushed by `php artisan sandbox:build-image`.
        'blaxel' => [
            'api_key' => env('BL_API_KEY'),
            'workspace' => env('BL_WORKSPACE'),
            'url' => env('BL_API_URL', 'https://api.blaxel.ai'),
            'image' => env('BLAXEL_IMAGE', 'zap-sandbox'),
            // Also sets CPUs (one per 2048 MB). About half backs the sandbox's in-memory filesystem. New accounts allow 4096 at most.
            'memory_mib' => (int) env('BLAXEL_MEMORY_MIB', 4096),
            // Empty picks the region closest to this app.
            'region' => env('BLAXEL_REGION'),
            // The Blaxel CLI, used by `php artisan sandbox:build-image` to push the image.
            'cli' => env('BLAXEL_CLI', 'bl'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Gateway Domain
    |--------------------------------------------------------------------------
    |
    | On a server, previews and shells are served at preview-<id>.<domain> and
    | shell-<id>.<domain> through Caddy, which asks this app to authorize each
    | request (SandboxGatewayController). Leave empty on a laptop, where the
    | browser reaches sandboxes on 127.0.0.1 directly.
    |
    */

    'gateway_domain' => env('SANDBOX_GATEWAY_DOMAIN'),

    // Set when a Cloudflare Worker (infra/cloudflare/preview-gateway) stands in for Caddy, e.g. on Laravel Cloud:
    // the Worker proves itself with this secret, and gets each sandbox's provider address and token to forward to.
    'gateway_secret' => env('SANDBOX_GATEWAY_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | App Port
    |--------------------------------------------------------------------------
    |
    | The port the app's dev server listens on inside every sandbox.
    |
    */

    'port' => (int) env('SANDBOX_PORT', 8000),

    // Host-rewriting proxy in front of the app (docker/sandbox/host-proxy.mjs), used by published URLs.
    'proxy_port' => (int) env('SANDBOX_PROXY_PORT', 8081),

    // The web terminal (ttyd) behind the workspace Shell tab.
    'shell_port' => (int) env('SANDBOX_SHELL_PORT', 7681),

    // SSH server for Tools → Developer → SSH (key-only logins as the sandbox user).
    'ssh_port' => (int) env('SANDBOX_SSH_PORT', 2222),

    /*
    |--------------------------------------------------------------------------
    | Agent
    |--------------------------------------------------------------------------
    |
    | The class that starts coding-agent tasks: HarnessRunner hands each run to
    | the project's agent (OpenCode or Claude Code). The test suite uses
    | FakeAgentRunner. Models are OpenCode "provider/model" ids,
    | one per AI provider a user can connect.
    |
    */

    'agent' => env('SANDBOX_AGENT', HarnessRunner::class),

    'models' => [
        'claude' => env('SANDBOX_MODEL_CLAUDE', 'anthropic/claude-sonnet-5'),
        'codex' => env('SANDBOX_MODEL_CODEX', 'openai/gpt-5.6'),
        'openrouter' => env('SANDBOX_MODEL_OPENROUTER', 'openrouter/anthropic/claude-sonnet-5'),
        'gemini' => env('SANDBOX_MODEL_GEMINI', 'google/gemini-3.8-flash'),
    ],

    // Shown first in the model picker (catalog ids; ones missing from the catalog are skipped).
    'featured_models' => [
        'claude' => ['claude-opus-5-5', 'claude-fable-5-1', 'claude-opus-5', 'claude-sonnet-5'],
        'codex' => ['gpt-6-astra', 'gpt-6-sol', 'gpt-6-luna', 'gpt-5.6'],
        'openrouter' => [
            'anthropic/claude-opus-5.5', 'anthropic/claude-sonnet-5', 'openai/gpt-6-astra',
            'google/gemini-3.8-flash', 'moonshotai/kimi-k3', 'z-ai/glm-5', 'deepseek/deepseek-v4-pro',
        ],
        'gemini' => ['gemini-3.8-flash', 'gemini-3.1-pro-preview', 'gemini-3.7-flash'],
    ],

    // Model catalog used by OpenCode (names, prices, context sizes, reasoning levels). Cached for a day.
    'catalog_url' => env('SANDBOX_MODEL_CATALOG_URL', 'https://models.dev/api.json'),

    /*
    |--------------------------------------------------------------------------
    | Task Copies
    |--------------------------------------------------------------------------
    |
    | Each task gets its own copy of the project's sandbox (TASK-003): files,
    | dependencies and databases forked from Main's, with its own preview.
    | Its work comes back to Main through git. Each copy is a sandbox you pay
    | for, so a project runs at most `max_task_copies` at once. Turned off,
    | tasks share Main's sandbox (TASK-001).
    |
    */

    'task_copies' => (bool) env('SANDBOX_TASK_COPIES', true),

    'max_task_copies' => (int) env('SANDBOX_MAX_TASK_COPIES', 3),

    /*
    |--------------------------------------------------------------------------
    | Callback URL
    |--------------------------------------------------------------------------
    |
    | How code inside a sandbox reaches this app to report agent events.
    | Locally that's the Docker host; in production, the public APP_URL.
    |
    */

    'callback_url' => env('SANDBOX_CALLBACK_URL', 'http://host.docker.internal:8000'),

    /*
    |--------------------------------------------------------------------------
    | Code Backups
    |--------------------------------------------------------------------------
    |
    | After every agent turn the project's git history is copied out of its
    | sandbox, as a git bundle, to this filesystem disk (e.g. "s3"), so the
    | code outlives any sandbox or provider. New sandboxes without the old
    | one's files get the code back from here.
    |
    */

    'backup_disk' => env('SANDBOX_BACKUP_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Git Remotes
    |--------------------------------------------------------------------------
    |
    | Tools → Git pushes and pulls from this app (never from the sandbox, so
    | the remote's token stays out of it). Remotes must be HTTPS hosts on the
    | public internet unless private ones are allowed, e.g. a self-hosted
    | Forgejo on your own network.
    |
    */

    'git' => [
        'allow_private_remotes' => (bool) env('SANDBOX_GIT_ALLOW_PRIVATE_REMOTES', false),
        'protocols' => ['https'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Publishing
    |--------------------------------------------------------------------------
    |
    | How projects get their own URL. "tailscale" gives each Docker sandbox its
    | own tailnet node (Serve for private, Funnel for public). With an auth key
    | (reusable, ephemeral, tagged) nodes join unattended; without one, the
    | Publish panel shows a Tailscale sign-in link to approve each project once.
    | "fake" is for tests.
    |
    */

    'publisher' => env('SANDBOX_PUBLISHER', 'tailscale'),

    'tailscale' => [
        'authkey' => env('TAILSCALE_AUTHKEY'),
        'image' => env('TAILSCALE_IMAGE', 'tailscale/tailscale:stable'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Dev Seed Credential
    |--------------------------------------------------------------------------
    |
    | Optional Anthropic key or Claude Code token. When set, the local seeder
    | connects it to the dev user so you can skip AI onboarding.
    |
    */

    'dev_claude_credential' => env('DEV_CLAUDE_CREDENTIAL'),

];
