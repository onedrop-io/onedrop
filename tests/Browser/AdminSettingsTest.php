<?php

use App\Models\AgentConnection;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    AgentConnection::factory()->for(User::where('email', 'dev@example.com')->sole())->create();
    Storage::fake('local');
    Process::fake(['*docker*system*df*' => Process::result('{"Active":"1","Reclaimable":"0B (0%)","Size":"1.5GB","TotalCount":"3","Type":"Images"}')]);
    config(['sandbox.provider' => 'docker', 'sandbox.providers.blaxel.api_key' => null, 'sandbox.providers.blaxel.workspace' => null]);
});

test('an admin renames the app, sees the providers, monitoring, server and backups pages', function () {
    $page = visit('/login')
        ->fill('email', 'dev@example.com')
        ->fill('password', 'password')
        ->press('@login-button')
        ->assertPathIs('/dashboard')
        ->click('@sidebar-menu-button')
        ->click('@settings-link')
        ->click('[data-test="settings-modal"] a:has-text("General")')
        ->assertPathIs('/admin/general')
        ->fill('@app-name-input', 'Acme Builder')
        ->press('@save-name-button')
        ->assertSee('Name saved.')
        ->assertSee('Acme Builder');

    $page->click('[data-test="settings-modal"] a:has-text("Sandboxes")')
        ->assertPathIs('/admin/sandboxes')
        ->assertTitleContains('Acme Builder')
        ->assertSeeIn('@provider-docker', 'Active')
        ->click('@provider-blaxel-select')
        ->assertSeeIn('@provider-blaxel', 'Needs api key and workspace')
        ->click('[data-test="settings-modal"] a:has-text("Monitoring")')
        ->assertPathIs('/admin/monitoring')
        ->assertSeeIn('@disk-panel', 'Used:')
        ->assertSeeIn('@docker-panel', '1.5GB')
        ->click('[data-test="settings-modal"] a:has-text("Server")')
        ->assertPathIs('/admin/server')
        ->assertPresent('@server-instructions')
        ->click('[data-test="settings-modal"] a:has-text("Backups")')
        ->assertPathIs('/admin/backups')
        ->assertSee('No backups at this destination yet.')
        ->assertNoJavaScriptErrors();
})->group('ADMIN-001', 'ADMIN-002', 'ADMIN-003', 'ADMIN-004', 'ADMIN-005');

test('an admin sets up a provider, turns it on and drags it first', function () {
    visit('/login')
        ->fill('email', 'dev@example.com')
        ->fill('password', 'password')
        ->press('@login-button')
        ->assertPathIs('/dashboard')
        ->navigate('/admin/sandboxes')
        ->assertSeeIn('@provider-docker-item', 'Active')
        ->click('@provider-blaxel-select')
        ->fill('#blaxel-api_key', 'bl-key')
        ->fill('#blaxel-workspace', 'acme')
        ->press('@provider-blaxel-save')
        ->assertSee('Blaxel saved.')
        ->click('@provider-blaxel-enabled')
        ->assertSee('Blaxel saved.')
        ->drag('@provider-blaxel-handle', '@provider-docker-item')
        ->assertSee('New projects now run on Blaxel.')
        ->assertSeeIn('@provider-blaxel-item', 'Active')
        ->assertDontSeeIn('@provider-docker-item', 'Active')
        // The arrow keys on a handle move it too.
        ->keys('@provider-docker-handle', 'ArrowUp')
        ->assertSee('New projects now run on Docker.')
        ->assertSeeIn('@provider-docker-item', 'Active')
        ->assertNoJavaScriptErrors();
})->group('ADMIN-002');

test('members do not see the admin settings', function () {
    AgentConnection::factory()->for(User::where('email', 'sam@example.com')->sole())->create();

    visit('/login')
        ->fill('email', 'sam@example.com')
        ->fill('password', 'password')
        ->press('@login-button')
        ->assertPathIs('/dashboard')
        ->click('@sidebar-menu-button')
        ->click('@settings-link')
        ->assertDontSee('Monitoring')
        ->assertDontSee('Backups')
        ->assertNoJavaScriptErrors();
})->group('ADMIN-001');
