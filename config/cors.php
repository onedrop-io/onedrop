<?php

/*
 * The desktop app (DESK-001) runs the web app's pages from its own origin and calls this server with its token, so
 * those origins may call any route. Browsers keep using the app on its own address and need nothing here.
 */
return [

    'paths' => ['*'],

    'allowed_methods' => ['*'],

    // macOS and Linux, Windows, and the app's development server (`npm run desktop:dev`), which signs in to any
    // server, onedrop.io included. No cookies cross origins (supports_credentials is off), so every request from
    // these origins needs the app's token anyway.
    'allowed_origins' => [
        'tauri://localhost',
        'http://tauri.localhost',
        'https://tauri.localhost',
        'http://localhost:1420',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    // What Inertia reads from its responses, a download's file name, and when the server's release was made.
    'exposed_headers' => ['X-Inertia', 'X-Inertia-Location', 'X-Inertia-Redirect', 'X-Inertia-Version', 'Content-Disposition', 'X-Onedrop-Released'],

    'max_age' => 600,

    'supports_credentials' => false,

];
