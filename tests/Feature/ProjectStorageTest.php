<?php

use App\Enums\ProjectStatus;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Agents\AgentRunner;
use App\Sandbox\Agents\FakeAgentRunner;
use App\Sandbox\ExecResult;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    app()->instance(AgentRunner::class, new FakeAgentRunner);

    $this->root = storageRoot();
    app()->instance(SandboxProvider::class, fakeStorageSandbox($this->root));

    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->project = Project::factory()->for($this->user)->create();
    Sandbox::factory()->for($this->project)->create(['external_id' => 'ctr-1']);
});

test('the owner creates, lists and deletes buckets', function () {
    $this->actingAs($this->user)
        ->postJson(route('projects.storage.store', $this->project), ['name' => 'photos'])
        ->assertOk()
        ->assertExactJson(['buckets' => [['name' => 'photos', 'objects' => 0, 'bytes' => 0]]]);

    expect(is_dir($this->root.'/photos'))->toBeTrue();

    file_put_contents($this->root.'/photos/a.jpg', 'abc');

    $this->getJson(route('projects.storage.index', $this->project))
        ->assertOk()
        ->assertExactJson(['buckets' => [['name' => 'photos', 'objects' => 1, 'bytes' => 3]]]);

    $this->deleteJson(route('projects.storage.destroy', [$this->project, 'photos']))
        ->assertOk()
        ->assertExactJson(['buckets' => []]);

    expect(is_dir($this->root.'/photos'))->toBeFalse();
})->group('STORE-001');

test('bucket names are validated, and a taken name says so', function () {
    $this->actingAs($this->user)
        ->postJson(route('projects.storage.store', $this->project), ['name' => 'My Photos'])
        ->assertJsonValidationErrors(['name' => 'Use 3–63 lowercase letters, digits and dashes, starting and ending with a letter or digit.']);

    mkdir($this->root.'/photos');

    $this->postJson(route('projects.storage.store', $this->project), ['name' => 'photos'])
        ->assertUnprocessable()
        ->assertExactJson(['message' => "There's already a bucket called photos."]);
})->group('STORE-001');

test('the owner uploads, browses, searches, downloads and deletes objects', function () {
    mkdir($this->root.'/photos');
    $bytes = random_bytes(150_000);

    $this->actingAs($this->user)
        ->post(route('projects.storage.upload', [$this->project, 'photos']), [
            'path' => 'cats/tabby.png',
            'file' => UploadedFile::fake()->createWithContent('tabby.png', $bytes),
        ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('object.path', 'cats/tabby.png')
        ->assertJsonPath('object.size', 150_000);

    expect(file_get_contents($this->root.'/photos/cats/tabby.png'))->toBe($bytes);

    $this->postJson(route('projects.storage.folder', [$this->project, 'photos']), ['path' => 'dogs'])->assertOk();

    $this->getJson(route('projects.storage.objects', [$this->project, 'photos']))
        ->assertOk()
        ->assertJsonPath('folders', [['name' => 'cats', 'path' => 'cats'], ['name' => 'dogs', 'path' => 'dogs']])
        ->assertJsonPath('objects', []);

    $this->getJson(route('projects.storage.objects', [$this->project, 'photos', 'search' => 'TABBY']))
        ->assertOk()
        ->assertJsonPath('objects.0.path', 'cats/tabby.png');

    $download = $this->get(route('projects.storage.download', [$this->project, 'photos', 'path' => 'cats/tabby.png']))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/octet-stream')
        ->assertDownload('tabby.png');
    expect($download->getContent())->toBe($bytes);

    $this->get(route('projects.storage.download', [$this->project, 'photos', 'path' => 'cats/tabby.png', 'inline' => 1]))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('Content-Disposition', 'inline; filename=tabby.png');

    $this->deleteJson(route('projects.storage.objects.destroy', [$this->project, 'photos']), ['path' => 'cats'])->assertOk();

    expect(is_dir($this->root.'/photos/cats'))->toBeFalse();
})->group('STORE-001');

test('only images are shown inline; HTML and SVG always download', function (string $name) {
    mkdir($this->root.'/photos');
    file_put_contents($this->root.'/photos/'.$name, '<script>alert(1)</script>');

    $this->actingAs($this->user)
        ->get(route('projects.storage.download', [$this->project, 'photos', 'path' => $name, 'inline' => 1]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/octet-stream')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertDownload($name);
})->with(['page.html', 'logo.svg'])->group('STORE-001');

test('uploads over 10 MB are refused', function () {
    mkdir($this->root.'/photos');

    $this->actingAs($this->user)
        ->post(route('projects.storage.upload', [$this->project, 'photos']), [
            'path' => 'big.bin',
            'file' => UploadedFile::fake()->create('big.bin', 10_241),
        ], ['Accept' => 'application/json'])
        ->assertJsonValidationErrors('file');
})->group('STORE-001');

test('paths outside the bucket are refused', function () {
    mkdir($this->root.'/photos');
    file_put_contents($this->root.'/secret.txt', 'secret');

    $this->actingAs($this->user)
        ->get(route('projects.storage.download', [$this->project, 'photos', 'path' => '../secret.txt']), ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertExactJson(['message' => "That path isn't valid."]);
})->group('STORE-001');

test('asking the agent to set up a bucket sends a chat message that follows the guide', function () {
    mkdir($this->root.'/photos');

    $this->actingAs($this->user)
        ->postJson(route('projects.storage.agent', [$this->project, 'photos']), ['uses' => 'profile photos on the account page'])
        ->assertOk()
        ->assertExactJson(['queued' => false]);

    expect($this->project->messages()->latest('id')->value('content'))
        ->toBe('Use the App Storage bucket "photos" to store files in the app: profile photos on the account page. Follow the guide at /opt/onedrop/guides/storage.md.');
})->group('STORE-001');

test('the request is queued while the agent works, and refused for a bucket that does not exist', function () {
    mkdir($this->root.'/photos');
    $this->project->update(['status' => ProjectStatus::Working]);

    $this->actingAs($this->user)
        ->postJson(route('projects.storage.agent', [$this->project, 'photos']))
        ->assertOk()
        ->assertExactJson(['queued' => true]);

    $this->postJson(route('projects.storage.agent', [$this->project, 'missing']))
        ->assertUnprocessable()
        ->assertExactJson(['message' => "There's no bucket called missing. Refresh to see the current list."]);
})->group('STORE-001');

test('another user cannot see or change the project\'s storage', function () {
    mkdir($this->root.'/photos');
    $other = User::factory()->has(AgentConnection::factory())->create();

    $this->actingAs($other)->getJson(route('projects.storage.index', $this->project))->assertForbidden();
    $this->actingAs($other)->deleteJson(route('projects.storage.destroy', [$this->project, 'photos']))->assertForbidden();

    expect(is_dir($this->root.'/photos'))->toBeTrue();
})->group('STORE-001');

test('it explains when the sandbox is not running or has no storage tool', function () {
    $this->project->sandbox->update(['status' => 'paused']);

    $this->actingAs($this->user)
        ->getJson(route('projects.storage.index', $this->project))
        ->assertStatus(409)
        ->assertExactJson(['message' => "The project's sandbox isn't running."]);

    $this->project->sandbox->update(['status' => 'running']);
    $provider = new FakeSandboxProvider;
    $provider->execUsing = fn () => new ExecResult(127, '');
    app()->instance(SandboxProvider::class, $provider);

    $this->getJson(route('projects.storage.index', $this->project))
        ->assertStatus(502)
        ->assertJsonPath('message', "This sandbox doesn't have App Storage yet. Rebuild the sandbox image and recreate the sandbox.");
})->group('STORE-001');
