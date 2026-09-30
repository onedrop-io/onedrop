<?php

use App\Enums\SandboxStatus;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\ExecResult;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use App\Sandbox\WorkspaceFiles;
use Illuminate\Http\UploadedFile;

beforeEach(function () {
    $this->provider = new FakeSandboxProvider;
    app()->instance(SandboxProvider::class, $this->provider);

    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->project = Project::factory()->for($this->user)->create();
    Sandbox::factory()->for($this->project)->create(['external_id' => 'ctr-1']);
});

test('lists workspace files, folders and collapsed folders', function () {
    $this->provider->execUsing = fn () => new ExecResult(0, "d src\nf src/App.tsx\nf package.json\nd node_modules\nf index.html\n");

    $this->actingAs($this->user)
        ->getJson(route('projects.files.index', $this->project))
        ->assertOk()
        ->assertExactJson(['files' => [
            ['path' => 'index.html', 'type' => 'file'],
            ['path' => 'node_modules', 'type' => 'dir'],
            ['path' => 'package.json', 'type' => 'file'],
            ['path' => 'src', 'type' => 'dir'],
            ['path' => 'src/App.tsx', 'type' => 'file'],
        ]]);

    $command = $this->provider->executed[0]['command'];
    expect($command[0])->toBe('find')
        ->and($command)->toContain('/workspace', '-prune', 'node_modules', 'vendor', '.git');
})->group('FILE-001');

test('reads a file inside the workspace', function () {
    $this->provider->execUsing = fn () => new ExecResult(0, "export default 1;\n");

    $this->actingAs($this->user)
        ->getJson(route('projects.files.show', [$this->project, 'path' => 'src/App.tsx']))
        ->assertOk()
        ->assertExactJson(['path' => 'src/App.tsx', 'content' => "export default 1;\n", 'notice' => null]);

    expect($this->provider->executed[0]['command'])->toBe(['head', '--bytes', '200001', '--', '/workspace/src/App.tsx']);
})->group('FILE-001');

test('binary and oversized files get a notice instead of content', function (string $output, string $notice) {
    $this->provider->execUsing = fn () => new ExecResult(0, $output);

    $this->actingAs($this->user)
        ->getJson(route('projects.files.show', [$this->project, 'path' => 'logo.png']))
        ->assertOk()
        ->assertJson(['content' => null])
        ->assertJsonFragment(['notice' => $notice]);
})->with([
    'binary' => ["\x89PNG\0\0data", "This file isn't text, so it can't be shown here."],
    'too large' => [str_repeat('a', 200_001), 'This file is too large to show here.'],
])->group('FILE-001');

test('paths outside the workspace are refused', function (string $path) {
    $this->actingAs($this->user)
        ->getJson(route('projects.files.show', [$this->project, 'path' => $path]))
        ->assertStatus(422);

    expect($this->provider->executed)->toBe([]);
})->with(['parent' => ['../etc/passwd'], 'nested parent' => ['src/../../etc/passwd'], 'absolute' => ['/etc/passwd']])->group('FILE-001');

test('other users cannot list or read files', function () {
    $other = User::factory()->has(AgentConnection::factory())->create();

    $this->actingAs($other)->getJson(route('projects.files.index', $this->project))->assertForbidden();
    $this->actingAs($other)->getJson(route('projects.files.show', [$this->project, 'path' => 'a.txt']))->assertForbidden();

    expect($this->provider->executed)->toBe([]);
})->group('FILE-001');

test('a stopped sandbox reports why files are unavailable', function () {
    $this->project->sandbox->update(['status' => SandboxStatus::Failed]);

    $this->actingAs($this->user)
        ->getJson(route('projects.files.index', $this->project))
        ->assertStatus(409)
        ->assertJson(['message' => "The project's sandbox isn't running."]);
})->group('FILE-001');

test('a failed read returns an error message', function () {
    $this->provider->execUsing = fn () => new ExecResult(1, '', 'No such file');

    $this->actingAs($this->user)
        ->getJson(route('projects.files.show', [$this->project, 'path' => 'missing.txt']))
        ->assertStatus(502)
        ->assertJson(['message' => "Couldn't open missing.txt."]);
})->group('FILE-001');

test('saves a file through a temp file, keeping its permissions', function () {
    $this->actingAs($this->user)
        ->putJson(route('projects.files.update', $this->project), ['path' => 'src/App.tsx', 'content' => "export default 2;\n"])
        ->assertOk()
        ->assertExactJson(['path' => 'src/App.tsx', 'saved' => true]);

    [$write, $replace] = $this->provider->executed;

    expect($write['command'])->toBe(['sh', '-c', 'printf %s "$APP_CONTENT" > "$1"', 'sh', '/workspace/src/App.tsx.onedrop-tmp'])
        ->and($write['env'])->toBe(['APP_CONTENT' => "export default 2;\n"])
        ->and($replace['command'])->toBe(['sh', '-c', 'cat "$1" > "$2" && rm -f "$1"', 'sh', '/workspace/src/App.tsx.onedrop-tmp', '/workspace/src/App.tsx']);
})->group('FILE-002');

test('large files are written in chunks', function () {
    $content = str_repeat('a', WorkspaceFiles::WRITE_CHUNK_BYTES).'bc';

    $this->actingAs($this->user)
        ->putJson(route('projects.files.update', $this->project), ['path' => 'big.txt', 'content' => $content])
        ->assertOk();

    $writes = array_slice($this->provider->executed, 0, 2);

    expect($writes[0]['command'][2])->toContain('> "$1"')
        ->and($writes[1]['command'][2])->toContain('>> "$1"')
        ->and($writes[0]['env']['APP_CONTENT'].$writes[1]['env']['APP_CONTENT'])->toBe($content)
        ->and($this->provider->executed)->toHaveCount(3);
})->group('FILE-002');

test('an empty file can be saved', function () {
    $this->actingAs($this->user)
        ->putJson(route('projects.files.update', $this->project), ['path' => 'empty.txt', 'content' => ''])
        ->assertOk();

    expect($this->provider->executed[0]['env'])->toBe(['APP_CONTENT' => '']);
})->group('FILE-002');

test('saving refuses paths outside the workspace', function () {
    $this->actingAs($this->user)
        ->putJson(route('projects.files.update', $this->project), ['path' => '../etc/passwd', 'content' => 'x'])
        ->assertStatus(422);

    expect($this->provider->executed)->toBe([]);
})->group('FILE-002');

test('other users cannot save files', function () {
    $other = User::factory()->has(AgentConnection::factory())->create();

    $this->actingAs($other)
        ->putJson(route('projects.files.update', $this->project), ['path' => 'a.txt', 'content' => 'x'])
        ->assertForbidden();

    expect($this->provider->executed)->toBe([]);
})->group('FILE-002');

test('a failed save returns an error message', function () {
    $this->provider->execUsing = fn () => new ExecResult(1, '', 'Read-only file system');

    $this->actingAs($this->user)
        ->putJson(route('projects.files.update', $this->project), ['path' => 'a.txt', 'content' => 'x'])
        ->assertStatus(502)
        ->assertJson(['message' => "Couldn't save a.txt."]);
})->group('FILE-002');

test('creates an empty file or folder, with missing parent folders', function (string $type, string $make) {
    $this->actingAs($this->user)
        ->postJson(route('projects.files.store', $this->project), ['path' => '/src/new/', 'type' => $type])
        ->assertOk()
        ->assertExactJson(['path' => 'src/new', 'type' => $type]);

    expect($this->provider->executed[0]['command'])
        ->toBe(['sh', '-c', 'if [ -e "$1" ]; then exit 3; fi; '.$make, 'sh', '/workspace/src/new']);
})->with([
    'file' => ['file', 'mkdir -p -- "$(dirname -- "$1")" && : > "$1"'],
    'folder' => ['dir', 'mkdir -p -- "$1"'],
])->group('FILE-003');

test('creating something that already exists is refused', function () {
    $this->provider->execUsing = fn () => new ExecResult(3, '');

    $this->actingAs($this->user)
        ->postJson(route('projects.files.store', $this->project), ['path' => 'package.json', 'type' => 'file'])
        ->assertStatus(422)
        ->assertJson(['message' => 'package.json already exists.']);
})->group('FILE-003');

test('uploads a file as base64, creating its folders', function () {
    $bytes = "\x89PNG\0\0binary";

    $this->actingAs($this->user)
        ->post(route('projects.files.upload', $this->project), [
            'path' => 'site/img/logo.png',
            'file' => UploadedFile::fake()->createWithContent('logo.png', $bytes),
        ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertExactJson(['path' => 'site/img/logo.png', 'uploaded' => true]);

    [$mkdir, $write, $decode] = $this->provider->executed;

    expect($mkdir['command'])->toBe(['mkdir', '-p', '--', '/workspace/site/img'])
        ->and($write['command'])->toBe(['sh', '-c', 'printf %s "$APP_CONTENT" > "$1"', 'sh', '/workspace/site/img/logo.png.onedrop-upload'])
        ->and($write['env'])->toBe(['APP_CONTENT' => base64_encode($bytes)])
        ->and($decode['command'][4])->toBe('/workspace/site/img/logo.png.onedrop-upload')
        ->and($decode['command'][5])->toBe('/workspace/site/img/logo.png')
        ->and($decode['command'][2])->toContain('base64 -d');
})->group('FILE-003');

test('uploading refuses paths outside the workspace and oversized files', function (string $path, int $kilobytes) {
    $this->actingAs($this->user)
        ->post(route('projects.files.upload', $this->project), [
            'path' => $path,
            'file' => UploadedFile::fake()->create('a.bin', $kilobytes),
        ], ['Accept' => 'application/json'])
        ->assertStatus(422);

    expect($this->provider->executed)->toBe([]);
})->with([
    'outside' => ['../a.bin', 1],
    'too large' => ['a.bin', WorkspaceFiles::MAX_UPLOAD_KILOBYTES + 1],
])->group('FILE-003');

test('downloads the workspace as a zip built in the sandbox', function () {
    $this->project->update(['name' => 'Todo App']);
    $this->provider->execUsing = fn (array $command) => $command[0] === 'php'
        ? new ExecResult(0, '')
        : new ExecResult(0, base64_encode('PK zip bytes')."\n");

    $response = $this->actingAs($this->user)
        ->get(route('projects.files.download', $this->project))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/zip')
        ->assertDownload('todo-app.zip');

    expect($response->getContent())->toBe('PK zip bytes');

    [$zip, $read] = $this->provider->executed;
    $temp = $zip['command'][4];

    expect(array_slice($zip['command'], 0, 2))->toBe(['php', '-r'])
        ->and($temp)->toStartWith('/tmp/onedrop-download-')
        ->and(array_slice($zip['command'], 5))->toBe(WorkspaceFiles::COLLAPSED)
        ->and($read['command'][4])->toBe($temp);
})->group('FILE-003');

test('a failed zip returns an error message', function () {
    $this->provider->execUsing = fn () => new ExecResult(1, '', 'No space left');

    $this->actingAs($this->user)
        ->getJson(route('projects.files.download', $this->project))
        ->assertStatus(502)
        ->assertJson(['message' => "Couldn't zip the project's files."]);
})->group('FILE-003');

test('other users cannot create, upload or download files', function () {
    $other = User::factory()->has(AgentConnection::factory())->create();
    $this->actingAs($other);

    $this->postJson(route('projects.files.store', $this->project), ['path' => 'a.txt', 'type' => 'file'])->assertForbidden();
    $this->post(route('projects.files.upload', $this->project), [
        'path' => 'a.txt', 'file' => UploadedFile::fake()->create('a.txt', 1),
    ], ['Accept' => 'application/json'])->assertForbidden();
    $this->getJson(route('projects.files.download', $this->project))->assertForbidden();

    expect($this->provider->executed)->toBe([]);
})->group('FILE-003');
