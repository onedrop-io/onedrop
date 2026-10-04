<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->script = dirname(__DIR__, 2).'/docker/sandbox/ask';
    $this->bin = sys_get_temp_dir().'/onedrop-ask-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->bin);

    // curl answers with the status and body in FAKE_STATUS and FAKE_BODY, written where -o says.
    File::put("{$this->bin}/curl", <<<'SH'
        #!/bin/bash
        while [ $# -gt 0 ]; do
            [ "$1" = -o ] && out="$2"
            last="$1"
            shift
        done
        printf '%s' "$FAKE_BODY" >"$out"
        echo "$last" >"$FAKE_DIR/url"
        printf '%s' "$FAKE_STATUS"
        SH);
    // The agents write what they were run with: their arguments, the key they got, and their stdin.
    foreach (['opencode', 'claude'] as $agent) {
        File::put("{$this->bin}/{$agent}", <<<SH
            #!/bin/bash
            printf '%s\\n' "\$@" >"\$FAKE_DIR/{$agent}-args"
            printf '%s' "\${ANTHROPIC_API_KEY:-none}" >"\$FAKE_DIR/{$agent}-key"
            cat >"\$FAKE_DIR/{$agent}-stdin"
            echo answered
            SH);
    }
    foreach (['curl', 'opencode', 'claude'] as $name) {
        chmod("{$this->bin}/{$name}", 0755);
    }

    $this->ask = fn (array $args, int $status, array $body, ?string $input = null, array $env = []) => Process::env([
        'PATH' => $this->bin.':'.getenv('PATH'),
        'HOME' => $this->bin,
        'ONEDROP_AI_URL' => 'https://onedrop.test/sandbox-ai/1?signature=abc',
        'ANTHROPIC_API_KEY' => '',
        'FAKE_DIR' => $this->bin,
        'FAKE_STATUS' => (string) $status,
        'FAKE_BODY' => json_encode($body),
        ...$env,
    ])->input($input)->run([$this->script, ...$args]);
});

afterEach(function () {
    File::deleteDirectory($this->bin);
});

test('ask runs OpenCode with the model and credentials the platform gave for this question', function () {
    $result = ($this->ask)(['how', 'do', 'I', 'list', 'ports?'], 200, [
        'harness' => 'opencode',
        'model' => 'anthropic/claude-sonnet-5-5',
        'env' => ['ANTHROPIC_API_KEY' => 'sk-ant-key', 'OPENCODE_CONFIG_CONTENT' => '{"a":"b c"}'],
    ]);

    $args = explode("\n", trim(File::get("{$this->bin}/opencode-args")));

    expect($result->successful())->toBeTrue()
        ->and($result->output())->toBe("answered\n")
        ->and(trim(File::get("{$this->bin}/url")))->toBe('https://onedrop.test/sandbox-ai/1?signature=abc')
        ->and(array_slice($args, 0, 4))->toBe(['run', '-m', 'anthropic/claude-sonnet-5-5', '--'])
        ->and(File::get("{$this->bin}/opencode-args"))->toContain('how do I list ports?')
        ->and(File::get("{$this->bin}/opencode-key"))->toBe('sk-ant-key');
})->group('SBX-012');

test('what is piped into ask goes with the question, and a pipe alone asks what it shows', function () {
    $setup = ['harness' => 'opencode', 'model' => 'm', 'env' => []];

    ($this->ask)(['why'], 200, $setup, "Error: port 8000 in use\n");
    expect(File::get("{$this->bin}/opencode-args"))->toContain("why\n\nPiped into the question:\n```\nError: port 8000 in use\n```");

    ($this->ask)([], 200, $setup, "Error: port 8000 in use\n");
    expect(File::get("{$this->bin}/opencode-args"))->toContain('What does this show, and what should I do about it?')
        ->toContain('Error: port 8000 in use');
})->group('SBX-012');

test('on a Claude subscription ask runs Claude Code read-only, with the question on stdin and no key', function () {
    $result = ($this->ask)(['what', 'does', 'this', 'app', 'do'], 200, ['harness' => 'claude_code', 'model' => 'opus', 'env' => (object) []], env: [
        'ANTHROPIC_API_KEY' => 'sk-ant-should-not-be-used',
    ]);

    expect($result->successful())->toBeTrue()
        ->and(explode("\n", trim(File::get("{$this->bin}/claude-args"))))->toBe(['-p', '--no-session-persistence', '--strict-mcp-config', '--tools', 'Read,Grep,Glob', '--model', 'opus'])
        ->and(File::get("{$this->bin}/claude-key"))->toBe('none')
        ->and(File::get("{$this->bin}/claude-stdin"))->toContain('what does this app do');
})->group('SBX-012');

test('ask says in one line why it can\'t answer', function (int $status, array $body, string $error) {
    $result = ($this->ask)(['hi'], $status, $body);

    expect($result->exitCode())->toBe(1)
        ->and(trim($result->errorOutput()))->toBe("ask: {$error}");
})->with([
    'no AI' => [422, ['message' => 'No connected AI can be asked.'], 'No connected AI can be asked.'],
    'too many' => [429, [], 'too many questions at once. Try again in a minute.'],
    'platform error' => [500, [], "OneDrop couldn't set up the AI (HTTP 500). Try again in a minute."],
])->group('SBX-012');

test('ask without a question or a sandbox address says what to do', function () {
    expect(trim(Process::env(['PATH' => getenv('PATH'), 'HOME' => $this->bin])->run([$this->script])->errorOutput()))
        ->toBe('ask: ask a question, e.g. ask how do I undo my last commit')
        ->and(trim(Process::env(['PATH' => getenv('PATH'), 'HOME' => $this->bin])->run([$this->script, 'hi'])->errorOutput()))
        ->toBe("ask: this sandbox is older than ask. It works once the project's sandbox is updated.");
})->group('SBX-012');
