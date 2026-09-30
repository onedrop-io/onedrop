<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    if (Process::run(['docker', 'compose', 'version'])->failed() || Process::run(['jq', '--version'])->failed()) {
        $this->markTestSkipped('Needs the docker compose CLI and jq.');
    }

    $this->workspace = sys_get_temp_dir().'/onedrop-compose-'.uniqid();
    File::ensureDirectoryExists($this->workspace);

    $this->init = fn (string $arch = 'x86_64', array $args = []) => Process::env([
        'ONEDROP_WORKSPACE' => $this->workspace,
        'ONEDROP_ARCH' => $arch,
        'PORT' => '8000',
        'COMPOSE_FILE' => '',
    ])->run(['bash', dirname(__DIR__, 2).'/docker/sandbox/compose', 'init', ...$args]);
    $this->write = fn (string $path, string $content) => File::put("{$this->workspace}/{$path}", $content);
    $this->dev = fn () => File::get("{$this->workspace}/.onedrop/dev");
});

afterEach(function () {
    File::deleteDirectory($this->workspace);
});

test('init picks the base file and its override, and previews the web port of the service built from the repo', function () {
    ($this->write)('docker-compose.yml', <<<'YAML'
        services:
          app:
            build: .
          graphql:
            build: ./graphql
            ports: ["4000:4000"]
          redis:
            image: redis:5
            ports: ["6379:6379"]
        YAML);
    ($this->write)('docker-compose.override.yml', <<<'YAML'
        services:
          app:
            ports: ["8080:80"]
        YAML);
    ($this->write)('docker-compose.arm64.yml', "services: {}\n");

    $result = ($this->init)();

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and($result->output())->toContain('Preview: port 8080 (service app)')
        ->and(($this->dev)())->toContain("export COMPOSE_FILE='docker-compose.yml:docker-compose.override.yml'")
        ->toContain('exec /opt/onedrop/compose up --preview 8080')
        ->and(is_executable("{$this->workspace}/.onedrop/dev"))->toBeTrue();
})->group('SBX-008');

test('on arm64 machines init adds the arm64 overlay, which compose never loads by itself', function () {
    ($this->write)('compose.yaml', "services:\n  web:\n    image: nginx\n    ports: [\"8080:80\"]\n");
    ($this->write)('compose.arm64.yaml', "services:\n  web:\n    platform: linux/amd64\n");

    expect(($this->init)('arm64')->successful())->toBeTrue()
        ->and(($this->dev)())->toContain("export COMPOSE_FILE='compose.yaml:compose.arm64.yaml'");
})->group('SBX-008');

test('init previews the port it is given', function () {
    ($this->write)('compose.yaml', "services:\n  web:\n    image: nginx\n    ports: [\"8080:80\", \"9000:9000\"]\n");

    $result = ($this->init)(args: ['--preview', '9000']);

    expect($result->output())->toContain('Preview: port 9000 (service web)')
        ->and(($this->dev)())->toContain('--preview 9000');
})->group('SBX-008');

test('init says why a stack can\'t start', function (array $files, string $message) {
    foreach ($files as $path => $content) {
        ($this->write)($path, $content);
    }

    $result = ($this->init)();

    expect($result->failed())->toBeTrue()
        ->and($result->errorOutput())->toContain($message)
        ->and(File::exists("{$this->workspace}/.onedrop/dev"))->toBeFalse();
})->with([
    'no compose file' => [[], 'No compose file here'],
    'a missing env file' => [['compose.yaml' => "services:\n  web:\n    image: nginx\n    env_file: .env\n"], "can't be read"],
    'a port the sandbox uses' => [['compose.yaml' => "services:\n  web:\n    image: nginx\n    ports: [\"7681:80\"]\n"], 'Service web publishes port 7681, which the sandbox uses for its Shell tab'],
    'nothing to preview' => [['compose.yaml' => "services:\n  worker:\n    image: redis\n"], 'No service publishes a port for the preview'],
])->group('SBX-008');
