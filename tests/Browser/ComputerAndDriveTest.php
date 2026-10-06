<?php

use App\Models\AgentConnection;
use App\Models\DriveItem;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->user = User::where('email', 'dev@example.com')->sole();
    AgentConnection::factory()->for($this->user)->create();
});

function signInAsDev()
{
    return visit('/login')
        ->fill('email', 'dev@example.com')
        ->fill('password', 'password')
        ->press('@login-button')
        ->assertPathIs(orgPath());
}

test('the dev user opens their computer from the sidebar and gets a desktop with a chat beside it', function () {
    signInAsDev()
        ->click('@sidebar-computer')
        ->assertPathIs(orgPath('/computer'))
        ->assertSeeIn('@tab-preview', 'Desktop')
        ->assertMissing('@tab-tools')
        ->assertSee('Starting your computer…')
        ->assertPresent('[placeholder="Ask the AI to do something on your computer…"]')
        ->click('@computer-menu')
        ->click('@computer-reset')
        ->assertSee('Reset your computer?')
        ->assertNoJavaScriptErrors();

    expect($this->user->computers()->count())->toBe(1);
})->group('CMP-001', 'CMP-002');

test('the dev user keeps files in Drive: a folder, an upload, a rename, Trash and back', function () {
    Storage::fake('local');
    $file = tempnam(sys_get_temp_dir(), 'drive').'.txt';
    file_put_contents($file, 'Lisbon flights');

    $page = signInAsDev()
        ->click('@sidebar-drive')
        ->assertPathIs(orgPath('/drive/personal'))
        ->assertSee('Drop files or folders here')
        ->click('@drive-new-folder')
        ->fill('@drive-name-input', 'Trips')
        ->click('@drive-name-save')
        ->assertSeeIn('@drive-list', 'Trips');

    $trips = DriveItem::where('name', 'Trips')->sole();

    $page->click('[data-test="drive-item-name"]:has-text("Trips")')
        ->assertPathIs(orgPath("/drive/personal/{$trips->id}"))
        ->attach('@drive-file-input', $file)
        ->assertSeeIn('[data-test="drive-item"]', basename($file));

    $uploaded = DriveItem::where('name', basename($file))->sole();
    expect($uploaded->parent->name)->toBe('Trips')
        ->and(Storage::disk('local')->get($uploaded->blob))->toBe('Lisbon flights');

    $page->click('@drive-item-menu')
        ->click('@drive-rename')
        ->fill('@drive-name-input', 'lisbon.txt')
        ->click('@drive-name-save')
        ->assertSeeIn('@drive-list', 'lisbon.txt')
        ->click('@drive-item-menu')
        ->click('@drive-delete')
        ->assertSee('Drop files or folders here')
        ->click('@drive-trash')
        ->assertSeeIn('@drive-list', 'lisbon.txt')
        ->click('@drive-item-menu')
        ->click('@drive-restore')
        ->assertSee('Trash is empty.')
        ->assertNoJavaScriptErrors();

    expect($uploaded->fresh()->name)->toBe('lisbon.txt')
        ->and($uploaded->fresh()->trashed_at)->toBeNull();
})->group('DRIVE-001', 'DRIVE-002');
