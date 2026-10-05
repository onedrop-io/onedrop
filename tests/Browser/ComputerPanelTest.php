<?php

use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\ExecResult;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use Database\Seeders\DatabaseSeeder;

/**
 * A stand-in for the desktop app's bridge (DESK-006), keeping what the panel asks of it in window.desktopCalls.
 */
const FAKE_DESKTOP = <<<'JS'
(() => {
    const listeners = new Set();
    const forwards = [];
    let sharing = false;
    window.desktopCalls = [];
    const changed = () => listeners.forEach((listener) => listener());
    window.onedropDesktop = {
        version: 'test',
        device: { id: 1, name: 'Test Mac' },
        forwards: {
            list: async () => forwards.slice(),
            start: async (projectId, remotePort) => {
                const forward = { projectId, remotePort, localPort: remotePort, state: 'listening', error: null };
                forwards.push(forward);
                changed();
                return forward;
            },
            stop: async (projectId, remotePort) => {
                forwards.splice(forwards.findIndex((f) => f.remotePort === remotePort), 1);
                changed();
            },
        },
        editor: {
            configured: async () => true,
            open: async (projectId, alias, editor) => window.desktopCalls.push(['editor', alias, editor]),
        },
        network: {
            status: async () => ({ sharing, state: sharing ? 'connected' : 'off', error: null }),
            setSharing: async (projectId, on) => {
                sharing = on;
                return { sharing, state: on ? 'connected' : 'off', error: null };
            },
        },
        docker: {
            status: async () => ({ state: 'ready', detail: null, progress: null, hasImage: true }),
            prepare: async () => ({ state: 'ready', detail: null, progress: null, hasImage: true }),
        },
        devices: { status: async () => ({ available: false, state: 'off', error: null }) },
        onChange: (listener) => {
            listeners.add(listener);
            return () => listeners.delete(listener);
        },
    };
})()
JS;

beforeEach(function () {
    $provider = new FakeSandboxProvider;
    // The sandbox's listening ports, for the panel's suggestions.
    $provider->execUsing = fn () => new ExecResult(0, json_encode([
        ['address' => '0.0.0.0', 'port' => 8081, 'process' => 'node'],
        ['address' => '127.0.0.1', 'port' => 5432, 'process' => 'postgres'],
    ]));
    app()->instance(SandboxProvider::class, $provider);

    $this->seed(DatabaseSeeder::class);
    $this->user = User::where('email', 'dev@example.com')->sole();
    AgentConnection::factory()->for($this->user)->create();
    $this->project = Project::factory()->for($this->user)->create(['name' => 'Room bookings']);
    Sandbox::factory()->for($this->project)->create(['preview_url' => null]);
    $this->actingAs($this->user);
});

test('in a browser, This computer says what the desktop app does and offers it', function () {
    visit("/projects/{$this->project->id}")
        ->resize(1500, 1000)
        ->click('@tab-tools')
        ->click('@tool-computer')
        ->assertVisible('@computer-pitch')
        ->assertSee('Your network')
        ->assertSeeIn('@computer-download', 'Download for')
        ->assertPresent("a[href=\"onedrop://projects/{$this->project->id}\"]")
        ->assertNoJavaScriptErrors();
})->group('DESK-006');

test('in the desktop app, the owner forwards ports, opens an editor and shares their network', function () {
    $page = visit("/projects/{$this->project->id}")->resize(1500, 1000);
    $page->script(FAKE_DESKTOP);

    $page->click('@tab-tools')
        ->click('@tool-computer')
        ->assertVisible('@computer-panel')
        ->assertSeeIn('@computer-ports', 'Postgres')
        ->click('@computer-forward-8081')
        ->assertSeeIn('@computer-forward', 'localhost:8081')
        ->assertSeeIn('@computer-forward', 'The app')
        ->click('@computer-open-vscode')
        ->fill('@computer-network-input', 'db.internal:5432')
        ->press('Add')
        ->assertSeeIn('@computer-network-host', 'db.internal:5432')
        ->click('@computer-network-share')
        ->assertSeeIn('@computer-network', 'Connected: the sandbox reaches these hosts through Test Mac.')
        ->assertNoJavaScriptErrors();

    expect($page->script('window.desktopCalls'))->toBe([['editor', "onedrop-room-bookings-{$this->project->id}", 'vscode']])
        ->and($this->project->fresh()->network_hosts)->toBe([['host' => 'db.internal', 'port' => 5432]]);
})->group('DESK-006', 'DESK-007', 'DESK-008', 'DESK-009');
