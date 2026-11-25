<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class);

function cookiesFreePort(): int
{
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
    fclose($server);

    return $port;
}

function waitForPort(int $port): void
{
    for ($i = 0; $i < 100 && @fsockopen('127.0.0.1', $port) === false; $i++) {
        usleep(50_000);
    }
}

/** The cookies after one of preview-frame.mjs's functions, run by Node. */
function crossSiteCookies(array $cookies, string $call = 'crossSiteCookie(cookie)'): array
{
    $module = base_path('docker/sandbox/preview-frame.mjs');
    $result = Process::input(json_encode($cookies))->run([
        'node', '--input-type=module', '-e',
        "import { crossSiteCookie, expiredPartitionedCookie } from '{$module}';"
        ."let input = ''; process.stdin.on('data', (chunk) => input += chunk);"
        ."process.stdin.on('end', () => console.log(JSON.stringify(JSON.parse(input).map((cookie) => {$call}))));",
    ]);

    expect($result->successful())->toBeTrue($result->errorOutput());

    return json_decode($result->output(), true);
}

test("the preview makes the app's cookies cross-site and partitioned", function () {
    expect(crossSiteCookies([
        '__session=abc; Path=/; SameSite=Lax',
        'sid=1; path=/; HttpOnly; secure; samesite=strict; Max-Age=60',
        '__clerk_db_jwt=x; Partitioned; SameSite=None; Secure',
        'theme=dark',
    ]))->toBe([
        '__session=abc; Path=/; Secure; SameSite=None; Partitioned',
        'sid=1; path=/; HttpOnly; Max-Age=60; Secure; SameSite=None; Partitioned',
        '__clerk_db_jwt=x; Secure; SameSite=None; Partitioned',
        'theme=dark; Secure; SameSite=None; Partitioned',
    ]);
})->group('GW-002');

test("where the browser keeps unpartitioned cookies, the app's are cross-site but not partitioned, and a partitioned copy from before is deleted", function () {
    expect(crossSiteCookies(['__session=abc; Path=/; SameSite=Lax', 'theme=dark; Partitioned'], 'crossSiteCookie(cookie, false)'))->toBe([
        '__session=abc; Path=/; Secure; SameSite=None',
        'theme=dark; Secure; SameSite=None',
    ])
        ->and(crossSiteCookies(['sid=1; Path=/app; Domain=preview-65.onedrop.dev; HttpOnly; Max-Age=60', 'theme=dark'], 'expiredPartitionedCookie(cookie)'))->toBe([
            'sid=; Path=/app; Domain=preview-65.onedrop.dev; Expires=Thu, 01 Jan 1970 00:00:00 GMT; Secure; SameSite=None; Partitioned',
            'theme=; Expires=Thu, 01 Jan 1970 00:00:00 GMT; Secure; SameSite=None; Partitioned',
        ]);
})->group('GW-002');

describe('through the proxy', function () {
    beforeEach(function () {
        $this->appPort = cookiesFreePort();
        $this->port = cookiesFreePort();
        $this->server = Process::start(['node', '-e', "require('http').createServer((req, res) => {"
            ."res.setHeader('Set-Cookie', ['__session=abc; Path=/; SameSite=Lax', 'theme=dark']);"
            ."res.end('ok'); }).listen({$this->appPort}, '127.0.0.1');"]);
        $this->proxy = Process::env([
            'PORT' => $this->appPort,
            'PROXY_PORT' => $this->port,
            'ONEDROP_ROUTES_FILE' => sys_get_temp_dir().'/onedrop-no-routes.json',
        ])->start(['node', base_path('docker/sandbox/host-proxy.mjs')]);

        waitForPort($this->appPort);
        waitForPort($this->port);
    });

    afterEach(function () {
        $this->proxy->stop(1);
        $this->server->stop(1);
    });

    test("a preview's cookies are cross-site and partitioned; a published app's and a local one's are left alone", function () {
        $cookies = fn (string $host) => Http::withHeaders(['Host' => $host])
            ->get("http://127.0.0.1:{$this->port}/")
            ->toPsrResponse()->getHeader('Set-Cookie');

        expect($cookies('preview-65.onedrop.dev'))->toBe([
            '__session=abc; Path=/; Secure; SameSite=None; Partitioned',
            'theme=dark; Secure; SameSite=None; Partitioned',
        ])
            ->and($cookies('sandbox-1.runtimehost.com'))->toContain('theme=dark; Secure; SameSite=None; Partitioned')
            ->and($cookies('my-app-65.onedrop.dev'))->toBe(['__session=abc; Path=/; SameSite=Lax', 'theme=dark'])
            ->and($cookies('localhost:8081'))->toBe(['__session=abc; Path=/; SameSite=Lax', 'theme=dark']);
    })->group('GW-002');

    test("a preview whose browser keeps unpartitioned cookies gets the app's cookies unpartitioned, with any partitioned copy deleted", function () {
        $cookies = Http::withHeaders(['Host' => 'preview-65.onedrop.dev', 'Cookie' => 'a=1; __onedrop_unpartitioned=1'])
            ->get("http://127.0.0.1:{$this->port}/")
            ->toPsrResponse()->getHeader('Set-Cookie');

        expect($cookies)->toBe([
            '__session=; Path=/; Expires=Thu, 01 Jan 1970 00:00:00 GMT; Secure; SameSite=None; Partitioned',
            '__session=abc; Path=/; Secure; SameSite=None',
            'theme=; Expires=Thu, 01 Jan 1970 00:00:00 GMT; Secure; SameSite=None; Partitioned',
            'theme=dark; Secure; SameSite=None',
        ]);
    })->group('GW-002');

    test("the preview's first script makes cookies the app sets from its scripts cross-site too", function () {
        $script = Http::withHeaders(['Host' => 'preview-65.onedrop.dev'])
            ->get("http://127.0.0.1:{$this->port}/__onedrop/errors.js")->body();

        expect($script)->toStartWith('(() => {')
            ->and(strpos($script, '__onedropCookies'))->toBeLessThan(strpos($script, '__onedropErrors'))
            ->and($script)->toContain('function crossSiteCookie(cookie, partitioned = true)')
            ->toContain('function expiredPartitionedCookie(cookie)')
            ->toContain("const mark = '__onedrop_unpartitioned'");

        // It's valid JavaScript with the function put in as it is.
        $check = Process::input($script)->run(['node', '--check', '-']);
        expect($check->successful())->toBeTrue($check->errorOutput());
    })->group('GW-002');
});
