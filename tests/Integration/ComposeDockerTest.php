<?php

use App\Sandbox\Providers\DockerSandboxProvider;
use App\Sandbox\SandboxSpec;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
 * Talks to real Docker, and pulls nginx inside the sandbox. Opt in with: RUN_DOCKER_TESTS=1 vendor/bin/pest tests/Integration
 * Needs `php artisan sandbox:build-image` first.
 */
uses(TestCase::class);

beforeEach(function () {
    if (! env('RUN_DOCKER_TESTS')) {
        $this->markTestSkipped('Set RUN_DOCKER_TESTS=1 to run Docker integration tests.');
    }
});

test('a project\'s own compose stack runs on the sandbox\'s own Docker and shows in the preview', function () {
    $docker = new DockerSandboxProvider([...config('sandbox.providers.docker'), 'nested_docker' => 'privileged']);
    $id = $docker->create(new SandboxSpec('onedrop-test-'.bin2hex(random_bytes(3))));

    try {
        $compose = "services:\n  web:\n    image: nginx:alpine\n    ports: [\"8080:80\"]\n    volumes: [\"./public:/usr/share/nginx/html:ro\"]\n";
        $docker->exec($id, ['bash', '-c', 'mkdir -p /workspace/public && echo "hello from compose" > /workspace/public/index.html && printenv COMPOSE > /workspace/compose.yaml'], ['COMPOSE' => $compose]);

        // Docker is started by start.sh as the sandbox comes up; the sandbox user uses it without sudo.
        retry(30, fn () => $docker->exec($id, ['docker', 'info'])->exitCode === 0 ?: throw new RuntimeException('Docker not up yet'), 1000);

        $init = $docker->exec($id, ['/opt/onedrop/compose', 'init']);
        expect($init->exitCode)->toBe(0, $init->errorOutput)
            ->and($init->output)->toContain('Preview: port 8080 (service web)');

        $url = $docker->previewUrl($id, 8000);
        $body = retry(90, fn () => Http::timeout(2)->get($url)->throw()->body(), 1000);
        expect($body)->toContain('hello from compose');

        expect($docker->exec($id, ['docker', 'compose', '-f', '/workspace/compose.yaml', 'ps', '--format', '{{.Service}} {{.State}}'])->output)
            ->toContain('web running');
    } finally {
        $docker->destroy($id);
    }
})->group('SBX-008');
