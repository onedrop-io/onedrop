<?php

use Tests\TestCase;

uses(TestCase::class);

test('the computer\'s Terminal gets the Shell tab\'s setup, since its Home is /workspace', function () {
    $desktop = file_get_contents(base_path('docker/sandbox/desktop'));

    expect($desktop)->toContain('export HOME=/workspace')
        ->toContain('cp -n /opt/onedrop/home-bashrc "$HOME/.bashrc"')
        ->and(file_get_contents(base_path('docker/sandbox/home-bashrc')))->toContain('. /opt/onedrop/bashrc');
})->group('CMP-001');
