<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

beforeEach(fn () => Http::preventStrayRequests());

test('the prune command skips when there is no Runtime key', function () {
    config(['sandbox.providers.runtime.api_key' => null]);

    $this->artisan('sandbox:prune-images')->expectsOutputToContain('no Runtime images to prune')->assertSuccessful();

    Http::assertNothingSent();
})->group('SBX-003');

test('the prune command deletes old image versions on Runtime', function () {
    config(['sandbox.providers.runtime.api_key' => 'rt-test-key', 'sandbox.providers.runtime.image' => 'onedrop-sandbox:latest']);
    Http::fake([
        '*/v1/images/resolve*' => Http::response(['id' => 'img-2', 'name' => 'onedrop-sandbox', 'version' => 2]),
        '*/v1/images/*:delete' => Http::response(['status' => 'deleted']),
        '*/v1/images?*' => fn (Request $request) => Http::response(['data' => $request['name'] === 'onedrop-sandbox' ? [
            ['id' => 'img-2', 'version' => 2, 'state' => 'ready'],
            ['id' => 'img-1', 'version' => 1, 'state' => 'ready'],
        ] : [], 'nextCursor' => null]),
        '*/v1/sandboxes?*' => Http::response(['data' => [], 'nextCursor' => null]),
    ]);

    $this->artisan('sandbox:prune-images')->expectsOutputToContain('Deleted 1 old image version(s).')->assertSuccessful();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/images/img-1:delete'));
})->group('SBX-003');

test('the prune command reports Runtime errors', function () {
    config(['sandbox.providers.runtime.api_key' => 'rt-test-key']);
    Http::fake(['*/v1/images/resolve*' => Http::response(['error' => ['message' => 'Bad key.']], 401)]);

    $this->artisan('sandbox:prune-images')->expectsOutputToContain('Bad key.')->assertFailed();
})->group('SBX-003');

test('building the image on Runtime deletes old versions first', function () {
    config(['sandbox.provider' => 'runtime', 'sandbox.providers.runtime.api_key' => 'rt-test-key', 'sandbox.providers.runtime.image' => 'onedrop-sandbox:latest']);
    Process::fake(['*' => Process::result('ready')]);
    Http::fake([
        '*/v1/images/resolve*' => Http::response(['id' => 'img-2', 'name' => 'onedrop-sandbox', 'version' => 2]),
        '*/v1/images/*:delete' => Http::response(['status' => 'deleted']),
        '*/v1/images?*' => fn (Request $request) => Http::response(['data' => $request['name'] === 'onedrop-sandbox' ? [
            ['id' => 'img-2', 'version' => 2, 'state' => 'ready'],
            ['id' => 'img-1', 'version' => 1, 'state' => 'ready'],
        ] : [], 'nextCursor' => null]),
        '*/v1/sandboxes?*' => Http::response(['data' => [], 'nextCursor' => null]),
    ]);

    $this->artisan('sandbox:build-image')->expectsOutputToContain('Deleted 1 old image version(s) first.')->assertSuccessful();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/images/img-1:delete'));
    Process::assertRan(fn ($process) => in_array('withruntime@0.8', $process->command, true));
})->group('SBX-003');

test('building the image on Runtime goes ahead when old versions can\'t be deleted first', function () {
    config(['sandbox.provider' => 'runtime', 'sandbox.providers.runtime.api_key' => 'rt-test-key']);
    Process::fake(['*' => Process::result('ready')]);
    Http::fake(['*/v1/images/resolve*' => Http::response(['error' => ['message' => 'Bad key.']], 401)]);

    $this->artisan('sandbox:build-image')->expectsOutputToContain("Couldn't delete old image versions first")->assertSuccessful();

    Process::assertRan(fn ($process) => in_array('withruntime@0.8', $process->command, true));
})->group('SBX-003');
