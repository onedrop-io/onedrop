<?php

use Tests\TestCase;

uses(TestCase::class);

test('macOS builds sign the whole app, so an unsigned download is not "damaged"', function () {
    $config = json_decode(file_get_contents(base_path('desktop/src-tauri/tauri.conf.json')), true);

    // "-" is an ad-hoc signature; APPLE_SIGNING_IDENTITY replaces it once the Apple secrets exist.
    expect($config['bundle']['macOS']['signingIdentity'])->toBe('-');
})->group('DESK-004');
