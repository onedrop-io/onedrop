<?php

use App\Enums\ProjectStatus;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Agents\AgentRunner;
use App\Sandbox\Agents\FakeAgentRunner;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use Illuminate\Support\Facades\Queue;

test('app storage creates a bucket, uploads and browses objects, and asks the agent to use it', function () {
    Queue::fake();
    app()->instance(AgentRunner::class, new FakeAgentRunner);
    $root = storageRoot();
    app()->instance(SandboxProvider::class, fakeStorageSandbox($root));

    $photo = sys_get_temp_dir().'/tabby-'.bin2hex(random_bytes(3)).'.png';
    file_put_contents($photo, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
    register_shutdown_function(fn () => @unlink($photo));

    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['preview_url' => null]);
    $this->actingAs($user);

    $page = visit("/projects/{$project->id}")
        ->resize(1500, 1000)
        ->click('@tab-tools')
        ->click('@tool-storage')
        ->assertSeeIn('@storage-empty', 'Store files for your app')
        ->click('@storage-create-bucket')
        ->assertVisible('@storage-create-dialog')
        ->assertScript('document.activeElement?.dataset.test', 'storage-bucket-name')
        ->clear('@storage-bucket-name')
        ->type('@storage-bucket-name', 'photos')
        ->click('@storage-bucket-submit')
        ->assertSeeIn('@storage-bucket-menu', 'photos')
        ->assertSeeIn('@storage-objects-empty', 'No objects')
        ->click('@storage-create-folder')
        ->assertScript('document.activeElement?.dataset.test', 'storage-folder-name')
        ->type('@storage-folder-name', 'cats')
        ->click('@storage-folder-submit')
        ->click('[data-test="storage-folder-cats"] td:nth-child(2) button')
        ->assertSeeIn('@storage-breadcrumbs', 'cats');

    // Pest's browser server doesn't pass multipart uploads through yet; ProjectStorageTest covers uploading.
    copy($photo, $root.'/photos/cats/'.basename($photo));
    file_put_contents($root.'/photos/invoice.pdf', '%PDF-1.4');

    $page->click('@storage-refresh')
        ->assertSeeIn('@storage-list', basename($photo))
        ->assertPresent('[data-test="storage-object-'.basename($photo).'"] svg.lucide-file-image')
        ->assertSeeIn('@storage-usage', '2 objects')
        ->click('[data-test="storage-breadcrumbs"] > button')
        ->assertPresent('[data-test="storage-object-invoice.pdf"] svg.lucide-file-text')
        ->type('@storage-search', 'tabby')
        ->assertSeeIn('@storage-list', 'cats/'.basename($photo))
        ->click('@storage-view-menu')
        ->click('@storage-view-commands')
        ->assertSeeIn('@storage-commands', "path.join(process.env.APP_STORAGE_DIR ?? '.storage', 'photos')")
        ->type('@storage-uses', 'Profile photos')
        ->click('@storage-ask-agent')
        ->assertSeeIn('@storage-agent-notice', 'The agent is setting it up');

    expect($project->messages()->latest('id')->value('content'))->toStartWith('Use the App Storage bucket "photos" to store files in the app: Profile photos.');

    // Back on the objects view to delete the photo. A fresh page opens on it: switching views through the menu is
    // covered above, and right after asking the agent that menu sometimes wouldn't open on CI.
    Project::whereKey($project->id)->update(['status' => ProjectStatus::Idle]);
    $page = visit("/projects/{$project->id}?tool=storage")->resize(1500, 1000);

    $page->assertSeeIn('@storage-view-menu', 'Objects')
        ->click('[data-test="storage-folder-cats"] td:nth-child(2) button')
        ->click('[data-test="storage-menu-'.basename($photo).'"]')
        ->click('[data-test="storage-delete-'.basename($photo).'"]')
        ->click('@storage-delete-submit')
        ->assertSeeIn('@storage-objects-empty', 'No objects')
        ->click('@storage-bucket-menu')
        ->click('@storage-delete-bucket')
        ->assertScript('document.activeElement?.dataset.test', 'storage-delete-bucket-confirm')
        ->type('@storage-delete-bucket-confirm', 'photos')
        ->click('@storage-delete-bucket-submit')
        ->assertVisible('@storage-empty')
        ->assertNoJavaScriptErrors();

    expect(is_dir($root.'/photos'))->toBeFalse();
})->group('STORE-001');

test('app storage explains when the sandbox is not running', function () {
    app()->instance(SandboxProvider::class, new FakeSandboxProvider);
    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['status' => 'paused', 'preview_url' => null]);
    $this->actingAs($user);

    visit("/projects/{$project->id}")
        ->click('@tab-tools')
        ->click('@tool-storage')
        ->assertSeeIn('@storage-message', 'App Storage works when the sandbox is running')
        ->assertNoJavaScriptErrors();
})->group('STORE-001');

test('app storage loads while the agent is working', function () {
    app()->instance(SandboxProvider::class, fakeStorageSandbox(storageRoot()));
    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create(['status' => ProjectStatus::Working]);
    Sandbox::factory()->for($project)->create(['preview_url' => null]);
    $this->actingAs($user);

    visit("/projects/{$project->id}")
        ->resize(1500, 1000)
        ->click('@tab-tools')
        ->click('@tool-storage')
        ->assertSeeIn('@storage-empty', 'Store files for your app')
        ->assertNoJavaScriptErrors();
})->group('STORE-001');

test('app storage selects a range of objects, moves them and deletes them together', function () {
    $root = storageRoot();
    app()->instance(SandboxProvider::class, fakeStorageSandbox($root));
    mkdir($root.'/photos');

    foreach (['a.jpg', 'b.jpg', 'c.jpg', 'd.jpg'] as $name) {
        file_put_contents($root.'/photos/'.$name, $name);
    }

    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['preview_url' => null]);
    $this->actingAs($user);

    $page = visit("/projects/{$project->id}?tool=storage")->resize(1500, 1000);

    // A range: a.jpg, then Shift on c.jpg.
    $page->click('[data-test="storage-select-a.jpg"]')
        ->script("document.querySelector('[data-test=\"storage-select-c.jpg\"]').dispatchEvent(new MouseEvent('click', { bubbles: true, shiftKey: true }))");

    $page->assertSeeIn('@storage-selection', '3 selected')
        ->click('@storage-selection-move')
        ->assertScript('document.activeElement?.dataset.test', 'storage-move-to')
        ->type('@storage-move-to', 'archive')
        ->click('@storage-move-submit')
        ->assertVisible('[data-test="storage-folder-archive"]')
        ->assertMissing('@storage-selection')
        ->assertSeeIn('@storage-list', 'd.jpg');

    expect(scandir($root.'/photos/archive'))->toBe(['.', '..', 'a.jpg', 'b.jpg', 'c.jpg']);

    $page->click('@storage-select-all')
        ->assertSeeIn('@storage-selection', '2 selected')
        ->click('@storage-selection-delete')
        ->assertSeeIn('@storage-delete-dialog', 'Delete 2 items?')
        ->click('@storage-delete-submit')
        ->assertSeeIn('@storage-objects-empty', 'No objects')
        ->assertNoJavaScriptErrors();

    expect(scandir($root.'/photos'))->toBe(['.', '..']);
})->group('STORE-001');
