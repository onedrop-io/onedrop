<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class);

function filesFreePort(): int
{
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
    fclose($server);

    return $port;
}

beforeEach(function () {
    $this->workspace = sys_get_temp_dir().'/onedrop-files-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists("{$this->workspace}/media");
    File::put("{$this->workspace}/media/clip.mp4", '0123456789');
    File::put("{$this->workspace}/logo.svg", '<svg xmlns="http://www.w3.org/2000/svg"/>');
    File::put(sys_get_temp_dir().'/onedrop-outside.txt', 'secret');
    symlink(sys_get_temp_dir().'/onedrop-outside.txt', "{$this->workspace}/escape.txt");

    $this->port = filesFreePort();
    $this->proxy = Process::env([
        'PORT' => filesFreePort(),
        'PROXY_PORT' => $this->port,
        'ONEDROP_WORKSPACE' => $this->workspace,
        'ONEDROP_ROUTES_FILE' => "{$this->workspace}/routes.json",
    ])->start(['node', base_path('docker/sandbox/host-proxy.mjs')]);

    for ($i = 0; $i < 100 && @fsockopen('127.0.0.1', $this->port) === false; $i++) {
        usleep(50_000);
    }
});

afterEach(function () {
    $this->proxy->stop(1);
    @unlink(sys_get_temp_dir().'/onedrop-outside.txt');
    File::deleteDirectory($this->workspace);
});

/** A request to the proxy as if from the preview's address, or another host. */
function filesRequest(int $port, string $path, array $headers = [], string $host = 'localhost')
{
    return Http::withHeaders(['Host' => $host, ...$headers])->get("http://127.0.0.1:{$port}/__onedrop/files/{$path}");
}

test('the proxy serves a workspace file with its type, and the part asked for', function () {
    $whole = filesRequest($this->port, 'raw?path=media/clip.mp4');

    expect($whole->status())->toBe(200)
        ->and($whole->body())->toBe('0123456789')
        ->and($whole->header('Content-Type'))->toBe('video/mp4')
        ->and($whole->header('Accept-Ranges'))->toBe('bytes');

    $part = filesRequest($this->port, 'raw?path=media/clip.mp4', ['Range' => 'bytes=2-4']);

    expect($part->status())->toBe(206)
        ->and($part->body())->toBe('234')
        ->and($part->header('Content-Range'))->toBe('bytes 2-4/10');

    expect(filesRequest($this->port, 'raw?path=media/clip.mp4', ['Range' => 'bytes=50-'])->status())->toBe(416);
})->group('FILE-001');

test('the proxy shows video in a page of its own, and keeps SVGs from running scripts', function () {
    expect(filesRequest($this->port, 'view?path=media/clip.mp4')->body())
        ->toContain('<video src="/__onedrop/files/raw?path=media%2Fclip.mp4" controls');

    expect(filesRequest($this->port, 'raw?path=logo.svg')->header('Content-Security-Policy'))->toBe('sandbox');
})->group('FILE-001');

test('the proxy serves nothing outside the workspace, and nothing on other addresses', function (string $path, string $host) {
    expect(filesRequest($this->port, $path, host: $host)->status())->toBe(404);
})->with([
    'climbing out' => ['raw?path=../onedrop-outside.txt', 'localhost'],
    'a link out' => ['raw?path=escape.txt', 'localhost'],
    'a folder' => ['raw?path=media', 'localhost'],
    'a published address' => ['raw?path=media/clip.mp4', 'my-app.example.ts.net'],
])->group('FILE-001');
