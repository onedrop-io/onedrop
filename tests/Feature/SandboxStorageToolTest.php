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
    symlink($this->root.'/other', $this->root.'/photos/link');

    $response = runStorageTool($this->root, ['op' => $op, 'bucket' => 'photos', ...$request]);

    expect($response['ok'])->toBeFalse()
        ->and(file_get_contents($this->root.'/other/secret.txt'))->toBe('secret');
})->with([
    'read with ..' => ['read', ['path' => '../other/secret.txt']],
    'read through a symlink' => ['read', ['path' => 'link/secret.txt']],
    'list with ..' => ['list', ['prefix' => '..']],
    'list through a symlink' => ['list', ['prefix' => 'link']],
    'delete with ..' => ['delete', ['path' => '../other']],
    'delete through a symlink' => ['delete', ['path' => 'link/secret.txt']],
    'mkdir with ..' => ['mkdir', ['path' => '../other/new']],
    'upload with a bad id' => ['upload-finish', ['upload' => '../../other/secret.txt', 'path' => 'x']],
])->group('STORE-001');

test('it creates folders, and deletes objects, folders and whole buckets', function () {
    mkdir($this->root.'/photos');
    runStorageTool($this->root, ['op' => 'mkdir', 'bucket' => 'photos', 'path' => 'cats/kittens']);
    file_put_contents($this->root.'/photos/cats/kittens/a.jpg', 'x');
    file_put_contents($this->root.'/photos/b.jpg', 'x');

    expect(runStorageTool($this->root, ['op' => 'mkdir', 'bucket' => 'photos', 'path' => 'cats'])['error'])->toBe('cats already exists.');

    runStorageTool($this->root, ['op' => 'delete', 'bucket' => 'photos', 'path' => 'b.jpg']);
    runStorageTool($this->root, ['op' => 'delete', 'bucket' => 'photos', 'path' => 'cats']);
    expect(scandir($this->root.'/photos'))->toBe(['.', '..']);

    runStorageTool($this->root, ['op' => 'delete-bucket', 'name' => 'photos']);
    expect(is_dir($this->root.'/photos'))->toBeFalse();
})->group('STORE-001');

test('it explains a missing bucket', function () {
    expect(runStorageTool($this->root, ['op' => 'list', 'bucket' => 'photos']))
        ->toBe(['ok' => false, 'error' => "There's no bucket called photos. Refresh to see the current list."]);
})->group('STORE-001');
