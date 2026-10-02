<?php

use App\Sandbox\Providers\RuntimeSandboxProvider;
use App\Sandbox\SandboxSpec;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
 * Talks to real Runtime Cloud (the trial) and pulls nginx inside the sandbox. Opt in with:
 * RUN_RUNTIME_TESTS=1 RUNTIME_TEST_IMAGE=<image built from docker/sandbox> vendor/bin/pest tests/Integration/RuntimeComposeTest.php
 */
uses(TestCase::class);

beforeEach(function () {
    if (! env('RUN_RUNTIME_TESTS') || blank(config('sandbox.providers.runtime.api_key'))) {
        $this->markTestSkipped('Set RUN_RUNTIME_TESTS=1 and RUNTIME_API_KEY to run Runtime Cloud integration tests.');
    }
});

test('a Runtime sandbox with Docker inside runs a project\'s compose stack and shows it in the preview', function () {
    $runtime = new RuntimeSandboxProvider([
        ...config('sandbox.providers.runtime'),
        'image' => env('RUNTIME_TEST_IMAGE', config('sandbox.providers.runtime.image')),
        'nested_docker' => 'on',
    ]);
    $id = $runtime->create(new SandboxSpec('onedrop-test-'.bin2hex(random_bytes(3)), port: 8000, shellPort: 7681, proxyPort: 8081));

    try {
        $compose = "services:\n  web:\n    image: nginx:alpine\n    ports: [\"8080:80\"]\n    volumes: [\"./public:/usr/share/nginx/html:ro\"]\n";
        $runtime->exec($id, ['bash', '-c', 'mkdir -p /workspace/public && echo "hello from compose on runtime" > /workspace/public/index.html && printenv COMPOSE > /workspace/compose.yaml'], ['COMPOSE' => $compose]);

        // start.sh reads ONEDROP_DOCKER from the settings Runtime gets after start, then starts Docker.
        retry(60, fn () => $runtime->exec($id, ['docker', 'info'])->exitCode === 0 ?: throw new RuntimeException('Docker not up yet'), 1000);

        expect(trim($runtime->exec($id, ['cat', '/proc/sys/vm/max_map_count'])->output))->toBe('262144');

        $init = $runtime->exec($id, ['/opt/onedrop/compose', 'init']);
        expect($init->exitCode)->toBe(0, $init->errorOutput)
            ->and($init->output)->toContain('Preview: port 8080 (service web)');

        $url = $runtime->previewUrl($id, 8000);
        $body = retry(90, fn () => Http::timeout(5)->get($url)->throw()->body(), 1000);
        expect($body)->toContain('hello from compose on runtime');
    } finally {
        $runtime->destroy($id);
    }
})->group('SBX-008');
