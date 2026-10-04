<?php

use App\Sandbox\SandboxTools;
use Tests\TestCase;

uses(TestCase::class);

$sandbox = dirname(__DIR__, 2).'/docker/sandbox';

test('the agent follows the serverless guide for functions or a serverless project', function () use ($sandbox) {
    expect(file_get_contents("{$sandbox}/instructions.md"))
        ->toContain('/opt/onedrop/guides/serverless.md')
        ->toContain('`wrangler.toml`, `template.yaml`, `vercel.json`, `netlify.toml` or `serverless.yml`')
        ->and("{$sandbox}/guides/serverless.md")->toBeFile();
})->group('SBX-010');

test('serverless apps run in the preview from a local emulator on the preview port', function () use ($sandbox) {
    expect(file_get_contents("{$sandbox}/guides/serverless.md"))
        ->toContain('npx wrangler dev --ip 0.0.0.0 --port "$PORT" --show-interactive-dev-session=false')
        ->toContain('sam local start-api --host 0.0.0.0 --port "$PORT"')
        ->toContain('npx next dev -H 0.0.0.0 -p "$PORT"');
})->group('SBX-010');

test('serverless apps never sign in to the user\'s cloud or deploy to it', function () use ($sandbox) {
    expect(file_get_contents("{$sandbox}/guides/serverless.md"))
        ->toContain("Never sign in to the user's cloud account")
        ->toContain("Don't deploy")
        ->toContain('not `vercel dev`, which needs a Vercel sign-in')
        ->toContain('ask them to add `VERCEL_TOKEN` in')
        ->toContain('only use it if the user')
        ->toContain('adds `LOCALSTACK_AUTH_TOKEN` in Tools → Secrets');
})->group('SBX-010');

test('Lambda functions reach local AWS stand-ins through the SDK\'s endpoint settings', function () use ($sandbox) {
    expect(file_get_contents("{$sandbox}/guides/serverless.md"))
        ->toContain('Docker inside sandboxes in Settings → Sandboxes')
        ->toContain('--docker-network onedrop-aws')
        ->toContain('"AWS_ENDPOINT_URL_DYNAMODB": "http://dynamodb:8000"')
        ->toContain('export AWS_ACCESS_KEY_ID=local AWS_SECRET_ACCESS_KEY=local');
})->group('SBX-010');

test('existing sandboxes get the serverless guide', function () use ($sandbox) {
    expect((new SandboxTools($sandbox))->files())->toHaveKey('guides/serverless.md');
})->group('SBX-010');
