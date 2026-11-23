<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->source = dirname(__DIR__, 2).'/docker/sandbox';
    $this->root = sys_get_temp_dir().'/onedrop-tools-'.bin2hex(random_bytes(4));
    $this->bin = "{$this->root}-bin";
    File::ensureDirectoryExists($this->bin);
    // flock isn't on macOS; the image has it.
    File::put("{$this->bin}/flock", "#!/bin/sh\nexit 0\n");
    chmod("{$this->bin}/flock", 0755);

    $this->tool = fn (string ...$args) => Process::env([
        'ONEDROP_TOOLS_DIR' => $this->root,
        'PATH' => $this->bin.':'.getenv('PATH'),
    ])->run([$this->source.'/onedrop-tool', ...$args]);

    $this->version = function (string $tool): string {
        preg_match('/^'.$tool.'=(\S+)$/m', File::get($this->source.'/onedrop-tool'), $match);

        return $match[1];
    };
});

afterEach(function () {
    File::deleteDirectory($this->root);
    File::deleteDirectory($this->bin);
});

test('every command has a stand-in on PATH that runs it through onedrop-tool, and every stand-in is a command', function () {
    $commands = collect(preg_split('/\R/', trim(($this->tool)('commands')->throw()->output())))
        ->map(fn (string $line) => explode(' ', $line)[1]);
    // Docker's credential helper for ghcr.io (GIT-016) and git's for GitHub (GIT-021) are on PATH the same way, but aren't tools.
    $standIns = collect(File::files($this->source.'/lazy'))->map->getFilename()->reject(fn (string $name) => in_array($name, ['onedrop-tool', 'docker-credential-onedrop', 'git-credential-onedrop'], true));

    expect($commands)->toContain('aws', 'sam', 'gcloud', 'az', 'wrangler', 'supabase', 'gh', 'fly')
        ->and($standIns->sort()->values()->all())->toBe($commands->sort()->values()->all());

    foreach ($standIns as $name) {
        expect(File::get("{$this->source}/lazy/{$name}"))->toContain('exec /opt/onedrop/onedrop-tool run "${0##*/}" "$@"')
            ->and(is_executable("{$this->source}/lazy/{$name}"))->toBeTrue();
    }
})->group('SBX-011');

test('every tool has a version Renovate keeps current and a way to install it', function () {
    $script = File::get($this->source.'/onedrop-tool');
    $tools = collect(preg_split('/\R/', trim(($this->tool)('commands')->throw()->output())))
        ->map(fn (string $line) => explode(' ', $line)[0])->unique();

    foreach ($tools as $tool) {
        expect($script)->toMatch('/^# renovate: datasource=\S+ depName=\S+.*\n'.$tool.'=\d\S*$/m')
            ->and($script)->toContain("install_{$tool}()");
    }
})->group('SBX-011');

test('every version in the image is one Renovate keeps current', function () {
    $dockerfile = File::get($this->source.'/Dockerfile');
    preg_match_all('/^ARG (\w+_VERSION)=/m', $dockerfile, $versions);

    expect($versions[1])->toContain('JQ_VERSION', 'UV_VERSION', 'CLAUDE_CODE_VERSION');

    foreach ($versions[1] as $name) {
        expect($dockerfile)->toMatch('/^# renovate: datasource=\S+ depName=\S+.*\nARG '.$name.'=/m');
    }

    expect($dockerfile)->toMatch('/^FROM php:8\.4-cli-bookworm@sha256:[0-9a-f]{64}$/m')
        ->and($dockerfile)->toContain('PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:/opt/onedrop/lazy ');
})->group('SBX-011');

test('a stand-in runs the installed tool with its arguments, without installing it again', function () {
    $dir = "{$this->root}/gh-".($this->version)('gh');
    File::ensureDirectoryExists("{$dir}/bin");
    File::put("{$dir}/.installed", '');
    File::put("{$dir}/bin/gh", "#!/bin/sh\necho \"gh \$*\"\n");
    chmod("{$dir}/bin/gh", 0755);

    $result = ($this->tool)('run', 'gh', 'pr', 'list', '--state', 'open');

    expect($result->successful())->toBeTrue()
        ->and($result->output())->toBe("gh pr list --state open\n")
        ->and($result->errorOutput())->not->toContain('Installing');
})->group('SBX-011');

test('gh reads the project\'s GitHub App repository with the token git gets, unless someone signed gh in or set GH_TOKEN', function () {
    $dir = "{$this->root}/gh-".($this->version)('gh');
    File::ensureDirectoryExists("{$dir}/bin");
    File::put("{$dir}/.installed", '');
    File::put("{$dir}/bin/gh", "#!/bin/sh\necho \"token=\${GH_TOKEN:-none}\"\n");
    chmod("{$dir}/bin/gh", 0755);
    $config = "{$this->root}-config";
    File::ensureDirectoryExists("{$config}/gh");

    $gh = fn (array $env = []) => trim(Process::env([
        'ONEDROP_TOOLS_DIR' => $this->root,
        'PATH' => $this->bin.':'.getenv('PATH'),
        'XDG_CONFIG_HOME' => $config,
        ...$env,
    ])->run([$this->source.'/onedrop-tool', 'run', 'gh', 'pr', 'list'])->output());

    // No GitHub App repository: no token.
    expect($gh())->toBe('token=none');

    File::put("{$this->bin}/git-credential-onedrop", "#!/bin/sh\n[ \"\$1\" = token ] && echo ghs_read\n");
    chmod("{$this->bin}/git-credential-onedrop", 0755);

    expect($gh())->toBe('token=ghs_read')
        ->and($gh(['GH_TOKEN' => 'ghp_mine']))->toBe('token=ghp_mine');

    File::put("{$config}/gh/hosts.yml", "github.com:\n    user: me\n");
    expect($gh())->toBe('token=none');

    File::deleteDirectory($config);
})->group('GIT-021');

test('a failed install says so and leaves nothing half-installed', function () {
    File::put("{$this->bin}/curl", "#!/bin/sh\necho 'curl: (6) Could not resolve host' >&2\nexit 6\n");
    chmod("{$this->bin}/curl", 0755);

    $result = ($this->tool)('run', 'doctl', 'version');

    expect($result->failed())->toBeTrue()
        ->and($result->errorOutput())->toContain('Installing doctl')->toContain("couldn't install doctl")
        ->and(File::isDirectory("{$this->root}/doctl-".($this->version)('doctl')))->toBeFalse();
})->group('SBX-011');

test('a command onedrop-tool does not install is refused', function () {
    $result = ($this->tool)('run', 'rm');

    expect($result->failed())->toBeTrue()
        ->and($result->errorOutput())->toContain("rm isn't a tool onedrop-tool installs");
})->group('SBX-011');

test('list says which tools are installed and which install on first use', function () {
    File::ensureDirectoryExists("{$this->root}/gh-".($this->version)('gh'));
    File::put("{$this->root}/gh-".($this->version)('gh').'/.installed', '');

    $list = ($this->tool)('list')->throw()->output();

    expect($list)->toMatch('/^GitHub\s.*\binstalled$/m')
        ->and($list)->toMatch('/^Google Cloud\s.*gcloud gsutil bq\s+installs on first use$/m');
})->group('SBX-011');
