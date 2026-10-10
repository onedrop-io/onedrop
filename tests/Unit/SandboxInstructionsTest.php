<?php

$sandbox = dirname(__DIR__, 2).'/docker/sandbox';

test('new apps default to the Laravel starter kit unless the user names a stack', function () use ($sandbox) {
    $instructions = file_get_contents("{$sandbox}/instructions.md");

    expect($instructions)
        ->toContain("Unless the user names another stack, build every new app with Laravel's React starter kit")
        ->toContain('/opt/onedrop/guides/laravel.md')
        ->toContain('Asking for React, TypeScript, Tailwind, shadcn, "a website" or "a landing page" still means the starter kit')
        ->and("{$sandbox}/guides/laravel.md")->toBeFile();
})->group('AGT-008');

test('the Laravel guide serves the app, its queue and Reverb through the preview port', function () use ($sandbox) {
    expect(file_get_contents("{$sandbox}/guides/laravel.md"))
        ->toContain('composer create-project laravel/react-starter-kit:dev-main')
        ->toContain('npx vite build --watch')
        ->toContain('php artisan queue:listen')
        ->toContain('php artisan reverb:start --host=127.0.0.1 --port=8080')
        ->toContain('{ "/app": 8080 }')
        ->toContain('wsHost: window.location.hostname')
        ->toContain('php artisan serve --host=0.0.0.0 --port="$PORT"');
})->group('AGT-008');

test('apps with a second server route it through the preview address', function () use ($sandbox) {
    expect(file_get_contents("{$sandbox}/instructions.md"))
        ->toContain('/workspace/.onedrop/routes.json');
})->group('RT-001');

test('the agent knows the platform saves its turns, leaves committing alone and keeps history intact', function () use ($sandbox) {
    expect(file_get_contents("{$sandbox}/instructions.md"))
        ->toContain('After each of your turns the platform saves a checkpoint and backs it up')
        ->toContain("don't commit, stage or unstage anything yourself unless the user asks")
        ->toContain("Don't rewrite or delete history")
        ->and("{$sandbox}/checkpoint")->toBeFile();
})->group('SBX-006', 'SCM-003');

test('the agent puts a new favicon where the sidebar picks it up', function () use ($sandbox) {
    expect(file_get_contents("{$sandbox}/instructions.md"))
        ->toContain('A new favicon (app icon) goes in `public/favicon.svg`')
        ->toContain('Tools → App Icon')
        ->toContain('shows the favicon, so changing the favicon changes the logo');
})->group('PRJ-007');

test('the agent knows where the app\'s errors are recorded and to check them', function () use ($sandbox) {
    expect(file_get_contents("{$sandbox}/instructions.md"))
        ->toContain('/workspace/.onedrop/errors.log')
        ->toContain('Check it before you finish and whenever the user says something is broken')
        ->toContain('"pub": true');
})->group('ERR-001');

test('Laravel asset rebuilds keep the old build until the new one is written', function () use ($sandbox) {
    expect(file_get_contents("{$sandbox}/guides/laravel.md"))->toContain('npx vite build --watch --emptyOutDir=false');
})->group('ERR-001');

test('the agent runs a project\'s own Docker Compose as it is', function () use ($sandbox) {
    expect(file_get_contents("{$sandbox}/instructions.md"))
        ->toContain('/opt/onedrop/compose init')
        ->toContain('docker compose exec <service> <command>')
        ->and("{$sandbox}/compose")->toBeFile();
})->group('SBX-008');

test('the agent runs an imported project the way its developers do, and asks for what\'s missing instead of inventing another way', function () use ($sandbox) {
    expect(file_get_contents("{$sandbox}/instructions.md"))
        ->toContain('find how its developers run it before writing anything')
        ->toContain("stop and tell the user exactly what's missing")
        ->toContain("Don't switch to a different way of running it");
})->group('SBX-008');
