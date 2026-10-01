<?php

use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    if (Process::run('command -v tmux')->failed()) {
        $this->markTestSkipped('tmux is not installed.');
    }

    $this->session = 'test-'.bin2hex(random_bytes(4));
    // Without a terminal the final attach fails, but the session is made (or found) first, as for a Shell tab.
    $this->shellEntry = fn (string ...$args) => Process::input('exit')
        ->run([dirname(__DIR__, 2).'/docker/sandbox/shell-entry', ...$args]);
    $this->tmux = fn (string ...$args) => trim(Process::run(['tmux', '-L', 'onedrop', ...$args])->output());
});

afterEach(function () {
    Process::run(['tmux', '-L', 'onedrop', 'kill-session', '-t', "={$this->session}"]);
});

test('a Shell with a session runs its start in a tmux session of that name', function () {
    ($this->shellEntry)('cd', 'src', 'session', $this->session);

    expect(($this->tmux)('display-message', '-p', '-t', "={$this->session}:", '#{pane_start_command}'))
        ->toContain('shell-entry')->toContain('cd src')->not->toContain('session');
})->group('LAYOUT-005');

test('a Shell coming back to its session reattaches instead of starting another', function () {
    ($this->shellEntry)('session', $this->session);
    $created = ($this->tmux)('display-message', '-p', '-t', "={$this->session}:", '#{session_created}');
    ($this->tmux)('send-keys', '-t', "={$this->session}:", 'echo still-here', 'Enter');

    ($this->shellEntry)('cd', 'elsewhere', 'session', $this->session);

    expect(($this->tmux)('display-message', '-p', '-t', "={$this->session}:", '#{session_created}'))->toBe($created)
        ->and(($this->tmux)('display-message', '-p', '-t', "={$this->session}:", '#{pane_start_command}'))->not->toContain('elsewhere');
})->group('LAYOUT-005');

test('a session name that is not plain letters, digits and dashes is ignored', function () {
    ($this->shellEntry)('session', '../x;rm');

    expect(($this->tmux)('list-sessions', '-F', '#{session_name}'))->not->toContain('x;rm');
})->group('LAYOUT-005');
