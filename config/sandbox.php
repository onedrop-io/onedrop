<?php

use App\Sandbox\Agents\OpenCodeRunner;

return [

    /*
    |--------------------------------------------------------------------------
    | Sandbox Provider
    |--------------------------------------------------------------------------
    |
    | Where each project's sandbox runs: "docker" (local development) or a
    | managed provider in production ("e2b", "daytona" — not built yet; pick
    | one with the M0 measurements). "fake" is used by the test suite.
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
        ],
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

    /*
    |--------------------------------------------------------------------------
    | Agent
    |--------------------------------------------------------------------------
    |
    | The class that starts coding-agent tasks: OpenCode by default. The test
    | suite uses FakeAgentRunner. Models are OpenCode "provider/model" ids,
    | one per AI provider a user can connect.
    |
    */

    'agent' => env('SANDBOX_AGENT', OpenCodeRunner::class),

    'models' => [
        'claude' => env('SANDBOX_MODEL_CLAUDE', 'anthropic/claude-sonnet-5'),
        'codex' => env('SANDBOX_MODEL_CODEX', 'openai/gpt-5.6'),
        'openrouter' => env('SANDBOX_MODEL_OPENROUTER', 'openrouter/anthropic/claude-sonnet-5'),
    ],

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
