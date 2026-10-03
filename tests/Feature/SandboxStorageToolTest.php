<?php

/*
 * docker/sandbox/storage.php, run locally against a temporary storage folder.
 */

beforeEach(function () {
    $this->root = storageRoot();
});

/**
 * Upload bytes through the tool the way WorkspaceStorage does: base64 chunks, then finish.
 *
 * @return array<string, mixed>
 */
function uploadThroughTool(string $root, string $bucket, string $path, string $bytes, int $chunk = 600): array
{
    $upload = bin2hex(random_bytes(16));

    foreach (str_split(base64_encode($bytes), $chunk) as $index => $data) {
        runStorageTool($root, ['op' => 'upload-chunk', 'upload' => $upload, 'data' => $data, 'first' => $index === 0]);
    }

    return runStorageTool($root, ['op' => 'upload-finish', 'upload' => $upload, 'bucket' => $bucket, 'path' => $path]);
}

test('it creates buckets and lists them with their object counts and sizes', function () {
    runStorageTool($this->root, ['op' => 'create-bucket', 'name' => 'photos']);
    runStorageTool($this->root, ['op' => 'create-bucket', 'name' => 'docs-2026']);
    mkdir($this->root.'/photos/cats');
    file_put_contents($this->root.'/photos/a.jpg', '12345');
    file_put_contents($this->root.'/photos/cats/b.jpg', '123');
    mkdir($this->root.'/.uploads');
    mkdir($this->root.'/Not_A_Bucket');

    expect(runStorageTool($this->root, ['op' => 'buckets']))->toBe(['ok' => true, 'data' => ['buckets' => [
        ['name' => 'docs-2026', 'objects' => 0, 'bytes' => 0],
        ['name' => 'photos', 'objects' => 2, 'bytes' => 8],
    ]]]);
})->group('STORE-001');

test('it refuses bad bucket names and names that are taken', function (string $name) {
    $response = runStorageTool($this->root, ['op' => 'create-bucket', 'name' => $name]);

    expect($response['ok'])->toBeFalse()->and($response['error'])->toContain('lowercase letters, digits and dashes');
})->with(['too short' => 'ab', 'uppercase' => 'Photos', 'leading dash' => '-photos', 'trailing dash' => 'photos-', 'dot' => '.uploads', 'traversal' => '../etc'])->group('STORE-001');

test('it says when a bucket already exists', function () {
    runStorageTool($this->root, ['op' => 'create-bucket', 'name' => 'photos']);

    expect(runStorageTool($this->root, ['op' => 'create-bucket', 'name' => 'photos']))
        ->toBe(['ok' => false, 'error' => "There's already a bucket called photos."]);
})->group('STORE-001');

test('it lists one folder at a time, folders first, and searches the whole bucket', function () {
    mkdir($this->root.'/photos/cats/kittens', recursive: true);
    mkdir($this->root.'/photos/dogs');
    file_put_contents($this->root.'/photos/b.png', 'xx');
    file_put_contents($this->root.'/photos/A.png', 'x');
    file_put_contents($this->root.'/photos/cats/kittens/Tabby.PNG', 'xyz');
    touch($this->root.'/photos/A.png', 1_790_000_000);

    $top = runStorageTool($this->root, ['op' => 'list', 'bucket' => 'photos', 'prefix' => ''])['data'];
    $nested = runStorageTool($this->root, ['op' => 'list', 'bucket' => 'photos', 'prefix' => 'cats/kittens/'])['data'];
    $found = runStorageTool($this->root, ['op' => 'search', 'bucket' => 'photos', 'query' => 'tabby'])['data'];

    expect($top['folders'])->toBe([['name' => 'cats', 'path' => 'cats'], ['name' => 'dogs', 'path' => 'dogs']])
        ->and($top['objects'])->toBe([
            ['name' => 'A.png', 'path' => 'A.png', 'size' => 1, 'modified' => 1_790_000_000],
            ['name' => 'b.png', 'path' => 'b.png', 'size' => 2, 'modified' => filemtime($this->root.'/photos/b.png')],
        ])
        ->and($nested['objects'][0]['path'])->toBe('cats/kittens/Tabby.PNG')
        ->and(array_column($found['objects'], 'path'))->toBe(['cats/kittens/Tabby.PNG']);
})->group('STORE-001');

test('it uploads any bytes in chunks, replacing an object at the same path, and reads them back', function () {
    mkdir($this->root.'/photos');
    $bytes = random_bytes(1000)."\0\xff";

    uploadThroughTool($this->root, 'photos', 'cats/a.bin', 'old');
    $response = uploadThroughTool($this->root, 'photos', '/cats/a.bin', $bytes);

    expect($response['data']['object'])->toMatchArray(['name' => 'a.bin', 'path' => 'cats/a.bin', 'size' => strlen($bytes)])
        ->and(file_get_contents($this->root.'/photos/cats/a.bin'))->toBe($bytes)
        ->and(glob($this->root.'/.uploads/*'))->toBe([])
        ->and(base64_decode(runStorageTool($this->root, ['op' => 'read', 'bucket' => 'photos', 'path' => 'cats/a.bin'])['data']['data']))->toBe($bytes);
})->group('STORE-001');

test('it refuses to read an object larger than the limit', function () {
    mkdir($this->root.'/photos');
    file_put_contents($this->root.'/photos/big.bin', str_repeat('x', 2_000_001));

    expect(runStorageTool($this->root, ['op' => 'read', 'bucket' => 'photos', 'path' => 'big.bin', 'max' => 2_000_000]))
        ->toBe(['ok' => false, 'error' => 'big.bin is too large to download here (the limit is 2 MB).']);
})->group('STORE-001');

test('it never reaches outside the bucket', function (string $op, array $request) {
    mkdir($this->root.'/photos');
    mkdir($this->root.'/other');
    file_put_contents($this->root.'/other/secret.txt', 'secret');
    file_put_contents($this->root.'/photos/a.txt', 'a');
    symlink($this->root.'/other', $this->root.'/photos/link');

    $response = runStorageTool($this->root, ['op' => $op, 'bucket' => 'photos', ...$request]);

    expect($response['ok'])->toBeFalse()
        ->and(file_get_contents($this->root.'/other/secret.txt'))->toBe('secret')
        ->and(scandir($this->root.'/other'))->toBe(['.', '..', 'secret.txt']);
})->with([
    'read with ..' => ['read', ['path' => '../other/secret.txt']],
    'read through a symlink' => ['read', ['path' => 'link/secret.txt']],
    'list with ..' => ['list', ['prefix' => '..']],
    'list through a symlink' => ['list', ['prefix' => 'link']],
    'delete with ..' => ['delete', ['paths' => ['../other']]],
    'delete through a symlink' => ['delete', ['paths' => ['link/secret.txt']]],
    'move with ..' => ['move', ['paths' => ['../other/secret.txt'], 'to' => '']],
    'move out with ..' => ['move', ['paths' => ['link'], 'to' => '..']],
    'move through a symlink' => ['move', ['paths' => ['link/secret.txt'], 'to' => '']],
    'move into a symlink' => ['move', ['paths' => ['a.txt'], 'to' => 'link']],
    'zip with ..' => ['zip', ['paths' => ['../other']]],
    'zip through a symlink' => ['zip', ['paths' => ['link/secret.txt']]],
    'mkdir with ..' => ['mkdir', ['path' => '../other/new']],
    'upload with a bad id' => ['upload-finish', ['upload' => '../../other/secret.txt', 'path' => 'x']],
])->group('STORE-001');

test('it creates folders, and deletes objects, folders and whole buckets', function () {
    mkdir($this->root.'/photos');
    runStorageTool($this->root, ['op' => 'mkdir', 'bucket' => 'photos', 'path' => 'cats/kittens']);
    file_put_contents($this->root.'/photos/cats/kittens/a.jpg', 'x');
    file_put_contents($this->root.'/photos/b.jpg', 'x');

    expect(runStorageTool($this->root, ['op' => 'mkdir', 'bucket' => 'photos', 'path' => 'cats'])['error'])->toBe('cats already exists.');

    expect(runStorageTool($this->root, ['op' => 'delete', 'bucket' => 'photos', 'paths' => ['b.jpg', 'cats', 'cats/kittens/a.jpg', 'gone.jpg']]))
        ->toBe(['ok' => true, 'data' => ['deleted' => ['b.jpg', 'cats']]]);
    expect(scandir($this->root.'/photos'))->toBe(['.', '..']);

    runStorageTool($this->root, ['op' => 'delete-bucket', 'name' => 'photos']);
    expect(is_dir($this->root.'/photos'))->toBeFalse();
})->group('STORE-001');

test('it explains a missing bucket', function () {
    expect(runStorageTool($this->root, ['op' => 'list', 'bucket' => 'photos']))
        ->toBe(['ok' => false, 'error' => "There's no bucket called photos. Refresh to see the current list."]);
})->group('STORE-001');

test('it moves objects and folders into a folder, creating it, and moves nothing on a clash', function () {
    mkdir($this->root.'/photos/cats', 0755, true);
    file_put_contents($this->root.'/photos/a.jpg', 'a');
    file_put_contents($this->root.'/photos/b.jpg', 'b');
    file_put_contents($this->root.'/photos/cats/tabby.jpg', 't');

    expect(runStorageTool($this->root, ['op' => 'move', 'bucket' => 'photos', 'paths' => ['a.jpg', 'cats'], 'to' => 'archive/2026']))
        ->toBe(['ok' => true, 'data' => ['moved' => [
            ['from' => 'a.jpg', 'to' => 'archive/2026/a.jpg'],
            ['from' => 'cats', 'to' => 'archive/2026/cats'],
        ]]]);
    expect(file_get_contents($this->root.'/photos/archive/2026/cats/tabby.jpg'))->toBe('t');

    file_put_contents($this->root.'/photos/a.jpg', 'new');

    expect(runStorageTool($this->root, ['op' => 'move', 'bucket' => 'photos', 'paths' => ['b.jpg', 'a.jpg'], 'to' => 'archive/2026']))
        ->toBe(['ok' => false, 'error' => 'a.jpg is already in archive/2026.'])
        ->and(file_exists($this->root.'/photos/b.jpg'))->toBeTrue();

    expect(runStorageTool($this->root, ['op' => 'move', 'bucket' => 'photos', 'paths' => ['archive'], 'to' => 'archive/2026']))
        ->toBe(['ok' => false, 'error' => "archive can't move into itself."]);

    expect(runStorageTool($this->root, ['op' => 'move', 'bucket' => 'photos', 'paths' => ['archive/2026/a.jpg'], 'to' => '']))
        ->toBe(['ok' => false, 'error' => 'a.jpg is already in the top of the bucket.']);
})->group('STORE-001');

test('it zips objects and folders, named from the folder being viewed, up to the limit', function () {
    mkdir($this->root.'/photos/2026/cats', 0755, true);
    file_put_contents($this->root.'/photos/2026/a.jpg', 'aaa');
    file_put_contents($this->root.'/photos/2026/cats/tabby.jpg', 'ttt');
    mkdir($this->root.'/photos/2026/empty');

    $response = runStorageTool($this->root, ['op' => 'zip', 'bucket' => 'photos', 'paths' => ['2026/a.jpg', '2026/cats', '2026/empty'], 'base' => '2026']);
    $file = tempnam(sys_get_temp_dir(), 'zip-test-');
    file_put_contents($file, base64_decode($response['data']['data']));
    $zip = new ZipArchive;
    $zip->open($file);
    $names = array_map(fn (int $i) => $zip->getNameIndex($i), range(0, $zip->numFiles - 1));
    sort($names);

    expect($names)->toBe(['a.jpg', 'cats/tabby.jpg'])
        ->and($zip->getFromName('cats/tabby.jpg'))->toBe('ttt')
        ->and($response['data']['files'])->toBe(2);

    $zip->close();
    unlink($file);

    expect(runStorageTool($this->root, ['op' => 'zip', 'bucket' => 'photos', 'paths' => ['2026'], 'max' => 5]))
        ->toBe(['ok' => false, 'error' => 'The selection is too large to download as a zip (the limit is 0 MB).']);
    expect(runStorageTool($this->root, ['op' => 'zip', 'bucket' => 'photos', 'paths' => ['2026/empty']]))
        ->toBe(['ok' => false, 'error' => 'There are no files in the selection, only empty folders.']);
})->group('STORE-001');

test('batch actions need 1 to 1000 paths', function (array $paths) {
    mkdir($this->root.'/photos');

    expect(runStorageTool($this->root, ['op' => 'delete', 'bucket' => 'photos', 'paths' => $paths]))
        ->toBe(['ok' => false, 'error' => 'Choose 1 to 1000 items.']);
})->with(['none' => [[]], 'too many' => [array_map(fn (int $i) => "f{$i}.jpg", range(1, 1001))]])->group('STORE-001');
