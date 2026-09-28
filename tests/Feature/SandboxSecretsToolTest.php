<?php

use Dotenv\Dotenv;

/*
 * docker/sandbox/secrets.php, run locally against a temporary workspace.
 */

beforeEach(function () {
    $this->workspace = sys_get_temp_dir().'/zap-secrets-'.bin2hex(random_bytes(4));
    mkdir($this->workspace);
    $root = $this->workspace;
    register_shutdown_function(fn () => exec('rm -rf '.escapeshellarg($root)));
});

test('it lists the names in .env in order, once each, without values', function () {
    file_put_contents($this->workspace.'/.env', <<<'ENV'
        # App
        APP_NAME=Demo
        export STRIPE_SECRET_KEY="sk_live_123"
        PEM="-----BEGIN KEY-----
        abc
        -----END KEY-----"
        APP_NAME=Again
        not a variable
        ENV);

    $response = runSecretsTool($this->workspace, ['op' => 'list']);

    expect($response)->toBe(['ok' => true, 'data' => ['secrets' => [['name' => 'APP_NAME'], ['name' => 'STRIPE_SECRET_KEY'], ['name' => 'PEM']]]])
        ->and(json_encode($response))->not->toContain('sk_live');
})->group('SECRET-001');

test('it lists nothing when there is no .env yet', function () {
    expect(runSecretsTool($this->workspace, ['op' => 'list'])['data'])->toBe(['secrets' => []]);
})->group('SECRET-001');

test('it reveals values written by hand in the usual dotenv styles', function (string $line, string $expected) {
    file_put_contents($this->workspace.'/.env', "OTHER=1\n{$line}\nLAST=2\n");

    expect(runSecretsTool($this->workspace, ['op' => 'reveal', 'name' => 'KEY'])['data'])->toBe(['name' => 'KEY', 'value' => $expected]);
})->with([
    'plain' => ['KEY=abc123', 'abc123'],
    'inline comment' => ['KEY=abc # note', 'abc'],
    'single quotes' => ["KEY='a \"b\" \\n'", 'a "b" \\n'],
    'double quotes with escapes' => ['KEY="say \"hi\"\nbye"', "say \"hi\"\nbye"],
    'multi-line' => ["KEY=\"line one\nline two\"", "line one\nline two"],
    'export' => ['export KEY=value', 'value'],
    'empty' => ['KEY=', ''],
])->group('SECRET-001');

test('it adds secrets that read back exactly, with Laravel\'s dotenv too, keeping the rest of the file', function (string $value) {
    file_put_contents($this->workspace.'/.env', "# keep me\nAPP_NAME=Demo\n");

    $response = runSecretsTool($this->workspace, ['op' => 'set-many', 'secrets' => [['name' => 'NEW_SECRET', 'value' => $value]]]);
    $content = file_get_contents($this->workspace.'/.env');

    expect($response['data']['secrets'])->toBe([['name' => 'APP_NAME'], ['name' => 'NEW_SECRET']])
        ->and($content)->toStartWith("# keep me\nAPP_NAME=Demo\nNEW_SECRET=")
        ->and(runSecretsTool($this->workspace, ['op' => 'reveal', 'name' => 'NEW_SECRET'])['data']['value'])->toBe($value)
        ->and(Dotenv::parse($content)['NEW_SECRET'])->toBe($value)
        ->and(fileperms($this->workspace.'/.env') & 0777)->toBe(0600);
})->with([
    'token' => 'sk_live_51H8x-y.z/=+',
    'spaces and symbols' => 'p@ss word #1 $HOME ${APP_NAME}',
    'double quotes' => 'say "hi"',
    'single quote' => "it's",
    'both quotes and a backslash' => 'it\'s "C:\\tmp"',
    'multi-line key' => "-----BEGIN KEY-----\nabc\n-----END KEY-----",
    'empty' => '',
])->group('SECRET-001');

test('it refuses to add a name that already exists, and changes the value when asked to replace', function () {
    file_put_contents($this->workspace.'/.env', "API_KEY=old\nOTHER=1\nAPI_KEY=duplicate\n");

    $taken = runSecretsTool($this->workspace, ['op' => 'set-many', 'secrets' => [['name' => 'API_KEY', 'value' => 'new']]]);
    expect($taken['ok'])->toBeFalse()->and($taken['error'])->toContain('already a secret called API_KEY');

    runSecretsTool($this->workspace, ['op' => 'set-many', 'secrets' => [['name' => 'API_KEY', 'value' => "two\nlines"]], 'replace' => ['API_KEY']]);

    expect(file_get_contents($this->workspace.'/.env'))->toBe("API_KEY=\"two\nlines\"\nOTHER=1\n");

    // Replacing a multi-line value removes all of its lines.
    runSecretsTool($this->workspace, ['op' => 'set-many', 'secrets' => [['name' => 'API_KEY', 'value' => 'one']], 'replace' => ['API_KEY']]);
    expect(file_get_contents($this->workspace.'/.env'))->toBe("API_KEY=one\nOTHER=1\n");
})->group('SECRET-001');

test('it deletes a secret, all of its lines and repeats', function () {
    file_put_contents($this->workspace.'/.env', "A=1\nPEM=\"x\ny\"\nB=2\nPEM=again\n");

    $response = runSecretsTool($this->workspace, ['op' => 'delete', 'name' => 'PEM']);

    expect($response['data']['secrets'])->toBe([['name' => 'A'], ['name' => 'B']])
        ->and(file_get_contents($this->workspace.'/.env'))->toBe("A=1\nB=2\n");
})->group('SECRET-001');

test('it rejects names that are not environment variable names', function (string $name) {
    $response = runSecretsTool($this->workspace, ['op' => 'set-many', 'secrets' => [['name' => $name, 'value' => 'x']]]);

    expect($response['ok'])->toBeFalse()
        ->and($response['error'])->toContain('letters, digits and underscores')
        ->and(file_exists($this->workspace.'/.env'))->toBeFalse();
})->with(['1ABC', 'MY-KEY', 'A B', "A\nB=1", ''])->group('SECRET-001');

test('it explains when a secret to reveal does not exist', function () {
    $response = runSecretsTool($this->workspace, ['op' => 'reveal', 'name' => 'MISSING']);

    expect($response['ok'])->toBeFalse()->and($response['error'])->toContain("There's no secret called MISSING");
})->group('SECRET-001');

test('it adds several secrets in one write, replacing only the existing names it was told to', function () {
    file_put_contents($this->workspace.'/.env', "APP_NAME=Demo\nAPI_KEY=old\n");

    $refused = runSecretsTool($this->workspace, ['op' => 'set-many', 'secrets' => [
        ['name' => 'NEW_ONE', 'value' => '1'],
        ['name' => 'API_KEY', 'value' => 'new'],
    ]]);

    expect($refused['ok'])->toBeFalse()
        ->and($refused['error'])->toContain('already a secret called API_KEY')
        ->and(file_get_contents($this->workspace.'/.env'))->toBe("APP_NAME=Demo\nAPI_KEY=old\n");

    $response = runSecretsTool($this->workspace, ['op' => 'set-many', 'secrets' => [
        ['name' => 'NEW_ONE', 'value' => '1'],
        ['name' => 'API_KEY', 'value' => 'new'],
        ['name' => 'NEW_TWO', 'value' => 'two words'],
    ], 'replace' => ['API_KEY']]);

    expect($response['data']['secrets'])->toBe([['name' => 'APP_NAME'], ['name' => 'API_KEY'], ['name' => 'NEW_ONE'], ['name' => 'NEW_TWO']])
        ->and(file_get_contents($this->workspace.'/.env'))->toBe("APP_NAME=Demo\nAPI_KEY=new\nNEW_ONE=1\nNEW_TWO='two words'\n");
})->group('SECRET-001');

test('it keeps .env out of git', function () {
    mkdir($this->workspace.'/.git');
    file_put_contents($this->workspace.'/.gitignore', '/vendor');

    runSecretsTool($this->workspace, ['op' => 'set-many', 'secrets' => [['name' => 'A', 'value' => '1']]]);
    runSecretsTool($this->workspace, ['op' => 'set-many', 'secrets' => [['name' => 'B', 'value' => '2']]]);

    expect(file_get_contents($this->workspace.'/.gitignore'))->toBe("/vendor\n.env\n");
})->group('SECRET-001');
