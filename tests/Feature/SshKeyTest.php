<?php

use App\Enums\SandboxStatus;
use App\Jobs\SyncSshKeys;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\SshKey;
use App\Models\User;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use Illuminate\Support\Facades\Queue;

const PUBLIC_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIPIBiZ++49W3bv5jUEcyynm2S7IIDJcUcE9sM1vFHKsa';

beforeEach(function () {
    $this->user = User::factory()->has(AgentConnection::factory())->create();
});

test('a user can add an SSH key, named from its comment by default', function () {
    Queue::fake();

    $this->actingAs($this->user)
        ->postJson(route('ssh-keys.store'), ['public_key' => PUBLIC_KEY.' jeff@laptop'])
        ->assertCreated()
        ->assertJsonPath('key.name', 'jeff@laptop')
        ->assertJsonPath('key.type', 'ssh-ed25519')
        ->assertJsonPath('key.fingerprint', 'SHA256:ImC8H8npCwPtG6OXskOMrY0meQbMijXXJ6h+avSBuIM');

    // The comment isn't kept in the key; the name is.
    expect($this->user->sshKeys()->sole()->public_key)->toBe(PUBLIC_KEY);

    Queue::assertPushed(SyncSshKeys::class, fn (SyncSshKeys $job) => $job->user->is($this->user));
})->group('DEVTOOLS-001');

test('a given name wins over the key comment', function () {
    Queue::fake();

    $this->actingAs($this->user)
        ->postJson(route('ssh-keys.store'), ['name' => 'Work laptop', 'public_key' => PUBLIC_KEY.' jeff@laptop'])
        ->assertCreated()
        ->assertJsonPath('key.name', 'Work laptop');
})->group('DEVTOOLS-001');

test('bad keys, private keys and duplicates are refused', function (string $key, string $message) {
    Queue::fake();
    SshKey::factory()->for($this->user)->create(['public_key' => PUBLIC_KEY, 'fingerprint' => SshKey::parse(PUBLIC_KEY)['fingerprint']]);

    $this->actingAs($this->user)
        ->postJson(route('ssh-keys.store'), ['public_key' => $key])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['public_key' => $message]);

    expect($this->user->sshKeys()->count())->toBe(1);
})->with([
    'not a key' => ['hello there', "doesn't look like an SSH public key"],
    'private key' => ["-----BEGIN OPENSSH PRIVATE KEY-----\nabc\n-----END OPENSSH PRIVATE KEY-----", "That's a private key"],
    'duplicate' => [PUBLIC_KEY.' other-comment', 'already on your account'],
])->group('DEVTOOLS-001');

test('the same key can be on two accounts', function () {
    Queue::fake();
    SshKey::factory()->create(['public_key' => PUBLIC_KEY, 'fingerprint' => SshKey::parse(PUBLIC_KEY)['fingerprint']]);

    $this->actingAs($this->user)
        ->postJson(route('ssh-keys.store'), ['public_key' => PUBLIC_KEY])
        ->assertCreated();
})->group('DEVTOOLS-001');

test('a user only sees their own keys, newest first', function () {
    $older = SshKey::factory()->for($this->user)->create(['name' => 'Old']);
    $newer = SshKey::factory()->for($this->user)->create(['name' => 'New']);
    SshKey::factory()->create(['name' => 'Someone else']);

    $this->actingAs($this->user)
        ->getJson(route('ssh-keys.index'))
        ->assertOk()
        ->assertJsonPath('keys.*.name', ['New', 'Old'])
        ->assertJsonPath('keys.0.id', $newer->id)
        ->assertJsonPath('keys.1.id', $older->id);
})->group('DEVTOOLS-001');

test('a user can delete their own key but not someone else\'s', function () {
    Queue::fake();
    $mine = SshKey::factory()->for($this->user)->create();
    $theirs = SshKey::factory()->create();

    $this->actingAs($this->user)->deleteJson(route('ssh-keys.destroy', $theirs))->assertNotFound();
    $this->actingAs($this->user)->deleteJson(route('ssh-keys.destroy', $mine))->assertOk();

    expect(SshKey::find($mine->id))->toBeNull()
        ->and(SshKey::find($theirs->id))->not->toBeNull();
    Queue::assertPushed(SyncSshKeys::class, 1);
})->group('DEVTOOLS-001');

test('guests cannot manage keys', function () {
    $this->getJson(route('ssh-keys.index'))->assertUnauthorized();
    $this->postJson(route('ssh-keys.store'), ['public_key' => PUBLIC_KEY])->assertUnauthorized();
})->group('DEVTOOLS-001');

test('syncing puts the owner\'s keys into each of their running sandboxes only', function () {
    $provider = new FakeSandboxProvider;
    app()->instance(SandboxProvider::class, $provider);

    SshKey::factory()->for($this->user)->create(['name' => 'Work laptop', 'public_key' => PUBLIC_KEY]);
    $running = Sandbox::factory()->for(Project::factory()->for($this->user))->create(['external_id' => 'mine-running']);
    Sandbox::factory()->for(Project::factory()->for($this->user))->create(['external_id' => 'mine-paused', 'status' => SandboxStatus::Paused]);
    Sandbox::factory()->create(['external_id' => 'someone-elses']);

    SyncSshKeys::dispatchSync($this->user);

    expect(collect($provider->executed)->pluck('id')->all())->toBe(['mine-running'])
        ->and($provider->executed[0]['env']['APP_SSH_KEYS'])->toBe(PUBLIC_KEY.' Work-laptop')
        ->and($provider->executed[0]['command'][2])->toContain('/home/sandbox/.ssh/authorized_keys');
})->group('DEVTOOLS-001');
