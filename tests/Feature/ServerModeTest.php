<?php

use App\Models\User;
use App\Sandbox\Providers\DockerSandboxProvider;
use App\Sandbox\SandboxSpec;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

test('sandboxes can reach the host on linux and use an optional runtime', function () {
    Process::preventStrayProcesses();
    Process::fake(['*' => Process::result('abc')]);

    (new DockerSandboxProvider(['image' => 'zap-sandbox:latest', 'memory' => '2g', 'cpus' => '2', 'host' => '127.0.0.1', 'runtime' => 'runsc']))
        ->create(new SandboxSpec('zap-project-1-x'));

    Process::assertRan(function (PendingProcess $process) {
        $command = implode(' ', $process->command);

        return str_contains($command, '--add-host host.docker.internal:host-gateway')
            && str_contains($command, '--runtime runsc');
    });
})->group('DEP-001');

test('sign-ups skip email verification when it is turned off', function (bool $verify, bool $verified) {
    config(['auth.verify_email' => $verify]);

    $this->post(route('register.store'), [
        'name' => 'Tester',
        'email' => 'tester@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    expect(User::where('email', 'tester@example.com')->sole()->hasVerifiedEmail())->toBe($verified);
})->with([
    'verification on' => [true, false],
    'verification off' => [false, true],
])->group('DEP-001');

test('email verification defaults to off only for local installs', function (?bool $setting, string $environment, bool $required) {
    config(['auth.verify_email' => $setting]);
    app()['env'] = $environment;

    expect(User::emailVerificationRequired())->toBe($required);
})->with([
    'unset, local' => [null, 'local', false],
    'unset, production' => [null, 'production', true],
    'on, local' => [true, 'local', true],
    'off, production' => [false, 'production', false],
])->group('DEP-001');

test('zap:admin makes a user an admin', function () {
    $user = User::factory()->create(['email' => 'boss@example.com']);

    $this->artisan('zap:admin', ['email' => 'boss@example.com'])->assertSuccessful();

    expect($user->fresh()->is_admin)->toBeTrue();
})->group('DEP-001');

test('zap:admin explains a missing user', function () {
    $this->artisan('zap:admin', ['email' => 'nobody@example.com'])
        ->expectsOutputToContain('No user with the email nobody@example.com')
        ->assertFailed();
})->group('DEP-001');
