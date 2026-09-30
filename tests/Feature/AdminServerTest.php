<?php

use App\Models\User;
use App\Sandbox\ServerSettings;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    File::delete([ServerSettings::requestPath(), ServerSettings::statusPath()]);
    config(['app.url' => 'https://onedrop.example.com', 'sandbox.gateway_domain' => 'onedrop.example.com']);
});

afterEach(function () {
    File::delete([ServerSettings::requestPath(), ServerSettings::statusPath()]);
});

test('admins see the address the app is served at and whether it has HTTPS', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.server.edit'))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/server')
            ->where('current', ['url' => 'https://onedrop.example.com', 'domain' => 'onedrop.example.com', 'https' => true, 'gateway' => true])
            ->where('editable', false)
            ->where('install', null));
})->group('ADMIN-004');

test('on a server install, saving writes the request for the server to apply and shows it pending', function () {
    config(['app.install' => 'server']);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->put(route('admin.server.update'), [
        'domain' => 'Builder.Acme.dev',
        'email' => 'ops@acme.dev',
        'certificates' => 'letsencrypt',
    ])->assertRedirect(route('admin.server.edit'));

    $request = json_decode(File::get(ServerSettings::requestPath()), true);
    expect($request)->toMatchArray(['domain' => 'builder.acme.dev', 'email' => 'ops@acme.dev', 'certificates' => 'letsencrypt'])
        ->and($request['requested_at'])->not->toBeNull();

    $this->actingAs($admin)->get(route('admin.server.edit'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('editable', true)
            ->where('requested.domain', 'builder.acme.dev')
            ->where('requested.status', 'pending'));

    // What apply-server-settings writes back.
    File::put(ServerSettings::statusPath(), json_encode(['requested_at' => $request['requested_at'], 'ok' => false, 'message' => 'Caddy rejected the settings.']));

    $this->actingAs($admin)->get(route('admin.server.edit'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('requested.status', 'failed')
            ->where('requested.message', 'Caddy rejected the settings.'));
})->group('ADMIN-004');

test('domains and emails are checked before they reach the server', function (array $input, string $field) {
    config(['app.install' => 'server']);

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.server.update'), [...['domain' => 'ok.example.com', 'certificates' => 'letsencrypt'], ...$input])
        ->assertSessionHasErrors($field);

    expect(File::exists(ServerSettings::requestPath()))->toBeFalse();
})->with([
    'a shell command' => [['domain' => 'x.com; rm -rf /'], 'domain'],
    'no dot' => [['domain' => 'localhost'], 'domain'],
    'a quote in the email' => [['email' => 'a"b@example.com'], 'email'],
    'an unknown authority' => [['certificates' => 'zerossl'], 'certificates'],
])->group('ADMIN-004');

test('other installs show how to change the address instead of a form', function (?string $install) {
    config(['app.install' => $install]);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get(route('admin.server.edit'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('editable', false)->where('install', $install));

    $this->actingAs($admin)
        ->put(route('admin.server.update'), ['domain' => 'new.example.com', 'certificates' => 'letsencrypt'])
        ->assertForbidden();

    expect(File::exists(ServerSettings::requestPath()))->toBeFalse();
})->with(['container', null])->group('ADMIN-004');

test('non-admins cannot see or change the server settings', function () {
    config(['app.install' => 'server']);
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('admin.server.edit'))->assertForbidden();
    $this->actingAs($user)->put(route('admin.server.update'), ['domain' => 'new.example.com', 'certificates' => 'letsencrypt'])->assertForbidden();
})->group('ADMIN-004');
