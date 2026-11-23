<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class);

function routedFreePort(): int
{
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
    fclose($server);

    return $port;
}

function routedWaitFor(int $port): void
{
    for ($i = 0; $i < 100 && @fsockopen('127.0.0.1', $port) === false; $i++) {
        usleep(50_000);
    }
}

beforeEach(function () {
    $this->workspace = sys_get_temp_dir().'/onedrop-routed-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->workspace);

    $this->appPort = routedFreePort();
    $this->apiPort = routedFreePort();
    $this->otherPort = routedFreePort();
    $this->port = routedFreePort();
    File::put("{$this->workspace}/routes.json", json_encode(['/api' => $this->apiPort]));

    // An API that names its files by its own localhost address, as Pixwel's does.
    $body = '{"file":"http://127.0.0.1:'.$this->apiPort.'/api/Files/1",'
        .'"escaped":"http:\\/\\/localhost:'.$this->apiPort.'\\/api\\/Files\\/2",'
        .'"other":"http://127.0.0.1:'.$this->otherPort.'/x"}';
    $this->server = Process::start(['node', '-e', "require('http').createServer((req, res) => {"
        ."res.setHeader('Content-Type', 'application/json'); res.end(".json_encode($body).'); })'
        .".listen({$this->apiPort}, '127.0.0.1');"]);
    $this->proxy = Process::env([
        'PORT' => $this->appPort,
        'PROXY_PORT' => $this->port,
        'ONEDROP_ROUTES_FILE' => "{$this->workspace}/routes.json",
    ])->start(['node', base_path('docker/sandbox/host-proxy.mjs')]);

    routedWaitFor($this->apiPort);
    routedWaitFor($this->port);
});

afterEach(function () {
    $this->proxy->stop(1);
    $this->server->stop(1);
    File::deleteDirectory($this->workspace);
});

test("a routed server's links to its localhost address point at the visitor's address", function () {
    $body = Http::withHeaders(['Host' => 'preview-65.onedrop.dev', 'X-Forwarded-Proto' => 'https'])
        ->get("http://127.0.0.1:{$this->port}/api/files")->body();

    expect($body)->toContain('"file":"https://preview-65.onedrop.dev/api/Files/1"')
        ->and($body)->toContain('"escaped":"https:\\/\\/preview-65.onedrop.dev\\/api\\/Files\\/2"')
        // A port nothing routes to isn't reachable at the visitor's address, so it's left alone.
        ->and($body)->toContain("\"other\":\"http://127.0.0.1:{$this->otherPort}/x\"");
})->group('RT-001');
