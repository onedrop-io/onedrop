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
            'image' => env('SANDBOX_DOCKER_IMAGE', 'onedrop-sandbox:latest'),
            'memory' => env('SANDBOX_DOCKER_MEMORY', '2g'),
            'cpus' => env('SANDBOX_DOCKER_CPUS', '2'),
            // Seconds a sandbox may sit unused before it's suspended (frozen, memory kept) until its next use, as
            // Runtime pauses its sandboxes after a minute; 0 never.
            'idle_seconds' => (int) env('SANDBOX_DOCKER_IDLE_SECONDS', 60),
            // Minutes a suspended sandbox stays frozen before it's stopped, freeing its memory (it starts again in a
            // second or two on its next use, files kept); 0 never.
            'stop_after_minutes' => (int) env('SANDBOX_DOCKER_STOP_AFTER_MINUTES', 5),
            // Host the browser uses to reach published container ports. Unset: APP_URL's host when it's localhost
            // or 127.0.0.1 (so previews are same-site with the app and keep their cookies), else 127.0.0.1.
            'host' => env('SANDBOX_DOCKER_HOST'),
            // Optional container runtime, e.g. "runsc" for gVisor isolation on Linux servers.
            'runtime' => env('SANDBOX_DOCKER_RUNTIME'),
            // Docker inside each sandbox, for projects that run their own Docker Compose (SBX-008): "off", "privileged"
            // (containers run with --privileged: local installs only), or "runtime" (the runtime above makes Docker in
            // a container safe, e.g. sysbox-runc on a server).
            'nested_docker' => env('SANDBOX_DOCKER_NESTED', 'off'),
            // Docker network to join, e.g. "drop" when the app itself runs in a container, so sandboxes reach it by name.
            'network' => env('SANDBOX_DOCKER_NETWORK'),
            // How the app reaches sandbox ports: "published" (their ports on `host`), or "network" (container name
            // and port on that network, for a containerized app behind the gateway; nothing is reachable from outside).
            'reach' => env('SANDBOX_DOCKER_REACH', 'published'),
            // Host folder holding each project's App Storage buckets (<path>/project-<id>/storage), mounted
            // into its sandbox at /data/storage so they survive the container. Empty keeps them in the container.
            'storage_path' => env('SANDBOX_DOCKER_STORAGE_PATH', storage_path('app/sandboxes')),
        ],

        // Runtime Cloud (withruntime.com). The image is docker/sandbox, built there by `php artisan sandbox:build-image`.
        'runtime' => [
            'api_key' => env('RUNTIME_API_KEY'),
            'url' => env('RUNTIME_API_URL', 'https://api.withruntime.com'),
            'image' => env('RUNTIME_IMAGE', 'onedrop-sandbox:latest'),
            // "trial" (the free hours) until the account has credit and you choose "paid".
            'funding' => env('RUNTIME_FUNDING', 'trial'),
            // Docker inside each sandbox, for projects that run their own Docker Compose (SBX-008): "off" or "on".
            // Runtime sandboxes are VMs with their own disk, so it needs no privileged mode or extra volume.
            'nested_docker' => env('RUNTIME_NESTED_DOCKER', 'off'),
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

        // A user's own computer (DESK-010): the desktop app runs the sandbox in its Docker, reached through the preview
        // gateway Worker's device relay. Only offered when the gateway runs on Cloudflare (SANDBOX_GATEWAY_SECRET).
        'device' => [
            'image' => env('SANDBOX_DEVICE_IMAGE', 'ghcr.io/onedrop-io/onedrop-sandbox:latest'),
            // Where the Worker serves the relay; relay.<gateway domain> unless set.
            'relay_url' => env('SANDBOX_DEVICE_RELAY_URL'),
            // How long the app waits on the computer for one call (exec runs up to the same limit as elsewhere).
            'timeout' => (int) env('SANDBOX_DEVICE_TIMEOUT', 130),
        ],

        // Blaxel (blaxel.ai). The image is docker/sandbox plus docker/sandbox/blaxel, pushed by `php artisan sandbox:build-image`.
        'blaxel' => [
            'api_key' => env('BL_API_KEY'),
            'workspace' => env('BL_WORKSPACE'),
            'url' => env('BL_API_URL', 'https://api.blaxel.ai'),
            'image' => env('BLAXEL_IMAGE', 'onedrop-sandbox'),
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

    // Custom domains through the Worker (DOM-001): Cloudflare for SaaS on the gateway domain's zone. Users CNAME their
    // domain to the target (the zone's fallback origin, e.g. domains.onedrop.io); the token needs SSL and
    // Certificates edit on the zone.
    'gateway_domains' => [
        'zone_id' => env('SANDBOX_GATEWAY_CLOUDFLARE_ZONE_ID'),
        'api_token' => env('SANDBOX_GATEWAY_CLOUDFLARE_TOKEN'),
        'target' => env('SANDBOX_GATEWAY_DOMAINS_TARGET'),
    ],

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

    // The desktop app's tunnel into the sandbox (docker/sandbox/tunnel.mjs, DESK-007..009), reached through the proxy.
    'tunnel_port' => (int) env('SANDBOX_TUNNEL_PORT', 7682),

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
        'ollama' => env('SANDBOX_MODEL_OLLAMA', 'ollama-cloud/glm-5.3'),
        // AI credits (CREDIT-001) run any OpenRouter model, on this cheap one unless the user picks another.
        'credits' => env('SANDBOX_MODEL_CREDITS', 'openrouter/deepseek/deepseek-v4.1-flash'),
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
        'ollama' => ['glm-5.3', 'kimi-k2.7-code', 'deepseek-v4-pro', 'qwen3.5:397b'],
        // AI credits can run any OpenRouter model; these come first, the cheap default leading.
        'credits' => [
            'deepseek/deepseek-v4.1-flash', 'google/gemini-3.8-flash', 'moonshotai/kimi-k3', 'z-ai/glm-5',
            'deepseek/deepseek-v4-pro', 'anthropic/claude-sonnet-5', 'anthropic/claude-opus-5.5', 'openai/gpt-6-astra',
        ],
    ],

    // What Auto (AGT-011) runs for each size of request, per provider: [catalog id, reasoning level], smallest first
    // (a typo or tiny tweak, a small change, a new feature, a big redesign or hard bug). A model the user's
    // connection can't run falls back to the provider's default model, and a level the model lacks to its default.
    'auto_models' => [
        'claude' => [['claude-sonnet-5', 'low'], ['claude-sonnet-5', 'medium'], ['claude-opus-5-5', 'high'], ['claude-opus-5-5', 'xhigh']],
        'codex' => [['gpt-6-luna', 'low'], ['gpt-6-sol', 'medium'], ['gpt-6-sol', 'high'], ['gpt-6-astra', 'xhigh']],
        'openrouter' => [['google/gemini-3.8-flash', 'low'], ['anthropic/claude-sonnet-5', 'medium'], ['anthropic/claude-opus-5.5', 'high'], ['anthropic/claude-opus-5.5', 'xhigh']],
        'gemini' => [['gemini-3.8-flash', 'low'], ['gemini-3.8-flash', 'medium'], ['gemini-3.1-pro-preview', 'high'], ['gemini-3.1-pro-preview', 'high']],
        'ollama' => [['glm-5.3', 'low'], ['glm-5.3', 'high'], ['glm-5.3', 'high'], ['glm-5.3', 'max']],
        'credits' => [['deepseek/deepseek-v4.1-flash', 'low'], ['deepseek/deepseek-v4.1-flash', 'medium'], ['deepseek/deepseek-v4.1-flash', 'high'], ['deepseek/deepseek-v4.1-flash', 'high']],
    ],

    // Whether a user's own Ollama server may be on a private or loopback address (AI-006). The platform
    // calls that URL, so it's off on servers; a local install runs Docker sandboxes that can reach the machine.
    'ollama_private_servers' => (bool) env('SANDBOX_OLLAMA_PRIVATE_SERVERS', env('APP_ENV') === 'local'),

    // Model catalog used by OpenCode (names, prices, context sizes, reasoning levels). Cached for a day.
    'catalog_url' => env('SANDBOX_MODEL_CATALOG_URL', 'https://models.dev/api.json'),

    // Template registries listed beside the built-in templates on the new-project page (PRJ-012), by name. "format" is
    // how the registry is laid out ("dokploy": a meta.json index and blueprints/<id>/ files), so a fork or mirror is
    // one more entry. An empty url turns a registry off. Each index is cached for a day. "popular" are the ids shown
    // under the prompt as popular apps, in order (registries don't say which are popular).
    'template_registries' => [
        'dokploy' => [
            'name' => 'Dokploy',
            'format' => 'dokploy',
            'url' => env('SANDBOX_TEMPLATES_DOKPLOY_URL', 'https://templates.dokploy.com'),
            'popular' => ['twenty', 'chatwoot', 'n8n', 'calcom', 'plausible', 'nocodb', 'documenso', 'plane', 'metabase'],
        ],
    ],

    /*
    | Pictures of the free apps in their details (PRJ-012): the preview image from each app's website (its og:image,
    | cached a week), then screenshots from app stores whose galleries are GitHub repositories. Each store's file list
    | is read once a day through GitHub's API; the images load from jsDelivr. `pattern` picks a store's screenshots and
    | captures the app's folder, matched to an app by its id or name (letters and digits only).
    */
    'app_screenshots' => [
        'website_previews' => (bool) env('SANDBOX_APP_SCREENSHOTS_WEBSITE_PREVIEWS', true),
        'github_url' => env('SANDBOX_APP_SCREENSHOTS_GITHUB_URL', 'https://api.github.com'),
        'cdn_url' => env('SANDBOX_APP_SCREENSHOTS_CDN_URL', 'https://cdn.jsdelivr.net/gh'),
        'galleries' => [
            'umbrel' => [
                'name' => 'Umbrel App Store',
                'repository' => 'getumbrel/umbrel-apps-gallery',
                'branch' => 'master',
                'pattern' => '#^([^/]+)/\d+\.(?:jpe?g|png|webp)$#i',
                'enabled' => (bool) env('SANDBOX_APP_SCREENSHOTS_UMBREL', true),
            ],
            'casaos' => [
                'name' => 'CasaOS App Store',
                'repository' => 'IceWhaleTech/CasaOS-AppStore',
                'branch' => 'main',
                'pattern' => '#^Apps/([^/]+)/screenshot-\d+\.(?:jpe?g|png|webp)$#i',
                'enabled' => (bool) env('SANDBOX_APP_SCREENSHOTS_CASAOS', true),
            ],
        ],
    ],

    // Where ChatGPT lists the models a signed-in account can use with Codex (its plan decides). Cached for an hour.
    'chatgpt_models_url' => env('SANDBOX_CHATGPT_MODELS_URL', 'https://chatgpt.com/backend-api/codex/models'),

    /*
    |--------------------------------------------------------------------------
    | Task Copies
    |--------------------------------------------------------------------------
    |
    | Each task gets its own copy of the project's sandbox (TASK-003): files,
    | dependencies and databases forked from Main's, with its own preview.
    | Its work comes back to Main through git. Each copy is a sandbox you pay
    | for, so an admin can cap how many one project runs at once (Settings →
    | Sandboxes, which wins over this), and so can each organization for its
    | own projects; the lower one applies. Unset means no limit. Turned off,
    | tasks share Main's sandbox (TASK-001).
    |
    */

    'task_copies' => (bool) env('SANDBOX_TASK_COPIES', true),

    'max_task_copies' => filled(env('SANDBOX_MAX_TASK_COPIES')) ? (int) env('SANDBOX_MAX_TASK_COPIES') : null,

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
    | one's files get the code back from here. Unset, it's the app's default
    | disk (FILESYSTEM_DISK, or the bucket Laravel Cloud attaches as default).
    |
    */

    'backup_disk' => env('SANDBOX_BACKUP_DISK'),

    /*
    |--------------------------------------------------------------------------
    | Project Snapshots
    |--------------------------------------------------------------------------
    |
    | Each project's whole state (its files, dependencies, the sandbox user's
    | home folder with any database there, and App Storage), kept on this disk
    | after every turn, before updates and daily (SBX-009). Unset, it's the
    | backup disk. On an S3-compatible disk sandboxes upload and download
    | straight to it through signed links; a local disk only works for Docker
    | sandboxes, which the platform copies in and out of on the same machine.
    |
    */

    'snapshot_disk' => env('SANDBOX_SNAPSHOT_DISK'),

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
    | Computers
    |--------------------------------------------------------------------------
    |
    | Each person's own cloud computer (CMP-001): a desktop with Chromium in a
    | sandbox of its own. Organizations can turn them off in their settings;
    | COMPUTERS_ENABLED=false turns them off for the whole install.
    |
    */

    'computers' => [
        'enabled' => (bool) env('COMPUTERS_ENABLED', true),
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
