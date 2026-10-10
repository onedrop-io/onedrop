<?php

use App\Sandbox\Providers\E2bSandboxProvider;
use App\Sandbox\SandboxSpec;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
 * Talks to real E2B: makes a sandbox from the onedrop-sandbox template, checks how its pause behaves, then deletes it
 * (a couple of minutes of compute). Opt in with:
 * RUN_E2B_TESTS=1 E2B_API_KEY=<key> vendor/bin/pest tests/Integration/E2bIdlePauseTest.php
 */
uses(TestCase::class);

beforeEach(function () {
    if (! env('RUN_E2B_TESTS') || blank(config('sandbox.providers.e2b.api_key'))) {
        $this->markTestSkipped('Set RUN_E2B_TESTS=1 and E2B_API_KEY to run E2B integration tests.');
    }
});

test('a run holds an E2B sandbox awake, connecting never shortens that, releasing it pauses it within a minute, and a request wakes it', function () {
    $config = config('sandbox.providers.e2b');
    $e2b = new E2bSandboxProvider([...$config, 'nested_docker' => 'off', 'private_previews' => false]);
    $api = fn (): PendingRequest => Http::baseUrl($config['url'])->withHeaders(['X-API-Key' => $config['api_key']])->timeout(30);
    $sandbox = fn (string $id): array => $api()->get("sandboxes/{$id}")->throw()->json();
    $pausesIn = fn (string $id): int => (int) now()->diffInSeconds(Carbon::parse($sandbox($id)['endAt']));

    $id = $e2b->create(new SandboxSpec('onedrop-test-'.bin2hex(random_bytes(3)), port: 8000, shellPort: 7681, proxyPort: 8081));

    try {
        $e2b->holdAwake($id);
        expect($pausesIn($id))->toBeGreaterThan(3500);

        // What the app relies on when a command connects during a run with the usual 10 minutes: E2B only extends.
        $api()->post("v2/sandboxes/{$id}/connect", ['timeout' => 60])->throw();
        expect($pausesIn($id))->toBeGreaterThan(3500);

        $e2b->releaseAwake($id, soon: true);
        expect($pausesIn($id))->toBeLessThanOrEqual(60);

        retry(120, fn () => $sandbox($id)['state'] === 'paused' ?: throw new RuntimeException('Not paused yet'), 1000);

        // A preview or Shell reconnecting does this, which is why the workspace unloads them once it's away.
        Http::timeout(60)->get($e2b->previewUrl($id, 8000));
        expect($sandbox($id)['state'])->toBe('running');
    } finally {
        $e2b->destroy($id);
    }
})->group('SBX-014');
