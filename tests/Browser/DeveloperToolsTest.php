<?php

use App\Enums\PublishStatus;
use App\Enums\PublishVisibility;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Agents\AgentRunner;
use App\Sandbox\Agents\FakeAgentRunner;
use App\Sandbox\ExecResult;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;

beforeEach(function () {
    // Answers the in-sandbox scripts by what they read; SSH key syncs succeed.
    $provider = new FakeSandboxProvider;
    $provider->execUsing = fn (array $command) => new ExecResult(0, match (true) {
        str_contains($command[2], '/proc/net/tcp') => json_encode([
            ['address' => '0.0.0.0', 'port' => 8000, 'pid' => 40, 'process' => 'php'],
            ['address' => '0.0.0.0', 'port' => 5173, 'pid' => 41, 'process' => 'node'],
        ]),
        str_contains($command[2], 'cpu.stat') => json_encode(['cpus' => 2, 'cpu' => 0.25, 'user' => 0.2, 'system' => 0.05, 'memory_limit' => 2147483648, 'memory' => 536870912, 'active' => 400000000, 'cache' => 100000000]),
        str_contains($command[2], 'disk_total_space') => json_encode(['workspace' => 700000000, 'dependencies' => 600000000, 'storage' => 0, 'disk_total' => 64000000000, 'disk_free' => 32000000000]),
        default => '',
    });
    app()->instance(SandboxProvider::class, $provider);
    app()->instance(AgentRunner::class, new FakeAgentRunner);

    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->project = Project::factory()->for($this->user)->create([
        'name' => 'Todo App',
        'publish_status' => PublishStatus::Live,
        'publish_visibility' => PublishVisibility::Private,
        'published_url' => 'https://todo-app.tail123.ts.net',
    ]);
    Sandbox::factory()->for($this->project)->create(['preview_url' => 'http://127.0.0.1:49152', 'ssh_address' => '127.0.0.1:49222']);
    $this->actingAs($this->user);
});

test('the user checks networking and resources, adds an SSH key and gets connection details', function () {
    $alias = 'onedrop-todo-app-'.$this->project->id;

    $page = visit("/projects/{$this->project->id}")
        ->resize(1920, 1080)
        ->click('@tab-tools')
        ->click('@tool-developer')
        ->assertSee('Networking')
        ->assertSee('Resources');

    // Networking: addresses (a QR code only for the published one) and ports.
    $page->click('@developer-networking')
        ->assertSeeIn('@developer-preview-url', 'http://127.0.0.1:49152')
        ->assertSeeIn('@developer-preview-url', 'machine running the app builder')
        ->assertSeeIn('@developer-published-url', 'https://todo-app.tail123.ts.net')
        ->assertCount('@developer-qr', 1)
        ->assertSeeIn('@developer-ports', '0.0.0.0:8000')
        ->assertSeeIn('@developer-ports', 'Your app')
        ->assertSeeIn('@developer-ports', 'Inside the sandbox only')
        ->click('@developer-back');

    // Resources: limits, live CPU and memory, storage.
    $page->click('@developer-resources')
        ->assertSeeIn('@developer-limits', '2 vCPU, 2 GiB RAM')
        ->assertSeeIn('@developer-cpu', '25%')
        ->assertSeeIn('@developer-memory', '25%')
        ->assertSeeIn('@developer-storage-workspace', '700 MB')
        ->click('@developer-back');

    // Connect needs a key first.
    $page->click('@developer-ssh-connect')
        ->assertVisible('@ssh-needs-key')
        ->click('Go to Keys')
        ->assertVisible('@ssh-keys-empty')
        ->click('@ssh-key-add')
        ->assertScript('document.activeElement?.dataset.test', 'ssh-key-input')
        ->type('@ssh-key-input', 'not a key')
        ->click('@ssh-key-save')
        ->assertSeeIn('@ssh-key-error', "doesn't look like an SSH public key")
        ->clear('@ssh-key-input')
        ->type('@ssh-key-input', 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIPIBiZ++49W3bv5jUEcyynm2S7IIDJcUcE9sM1vFHKsa jeff@laptop')
        ->click('@ssh-key-save')
        ->assertMissing('@ssh-key-dialog')
        ->assertSeeIn('@ssh-keys', 'jeff@laptop')
        ->assertSeeIn('@ssh-keys', 'SHA256:ImC8H8npCwPtG6OXskOMrY0meQbMijXXJ6h+avSBuIM')
        ->click('@developer-back');

    expect($this->user->sshKeys()->count())->toBe(1);

    $page->click('@developer-ssh-connect')
        ->assertSeeIn('@ssh-config', "Host {$alias}")
        ->assertSeeIn('@ssh-config', 'Port 49222')
        ->assertSeeIn('@ssh-command', "ssh {$alias}")
        ->assertAttribute('@ssh-open-vscode', 'href', "vscode://vscode-remote/ssh-remote+{$alias}/workspace")
        ->assertAttribute('@ssh-open-cursor', 'href', "cursor://vscode-remote/ssh-remote+{$alias}/workspace")
        ->click('@developer-back');

    // Delete the key again.
    $page->click('@developer-ssh-keys')
        ->click('@ssh-key-delete')
        ->click('@ssh-key-delete-confirm')
        ->assertVisible('@ssh-keys-empty')
        ->assertNoJavaScriptErrors();

    expect($this->user->sshKeys()->count())->toBe(0);
})->group('DEVTOOLS-001');
