<?php

/*
 * docker/sandbox/auth.php, run locally against a temporary workspace with a real SQLite database.
 */

test('it reports sign-in as not set up until the agent writes the manifest', function () {
    expect(runAuthTool(databaseWorkspace(), ['op' => 'status']))->toBe(['ok' => true, 'data' => ['configured' => false]]);
})->group('APPAUTH-001');

test('it describes the setup with defaults, and only whether provider keys are set', function () {
    $workspace = authWorkspace(['methods' => ['password', 'google', 'passkeys']]);
    file_put_contents($workspace.'/.env', "GOOGLE_CLIENT_ID=abc.apps.googleusercontent.com\nGOOGLE_CLIENT_SECRET=\"s3cret\"\n", FILE_APPEND);

    $response = runAuthTool($workspace, ['op' => 'status']);

    expect($response['data'])->toMatchArray([
        'configured' => true,
        'library' => 'Laravel Fortify + Socialite',
        'login_path' => '/login',
        'callback_path' => '/auth/{provider}/callback',
        'methods' => ['password', 'google'],
        'env_file' => '.env',
        'users_table' => 'users',
    ])
        ->and($response['data']['keys']['google'])->toBe(['client_id' => true, 'client_secret' => true])
        ->and($response['data']['keys']['github'])->toBe(['client_id' => false, 'client_secret' => false])
        ->and(json_encode($response))->not->toContain('s3cret');
})->group('APPAUTH-001');

test('it lists users newest first, searches by name or email, and pages', function () {
    $workspace = authWorkspace();

    $all = runAuthTool($workspace, ['op' => 'users'])['data'];

    expect($all['total'])->toBe(3)
        ->and(array_column($all['users'], 'name'))->toBe(['Cy_1', 'Bob', 'Ann'])
        ->and($all['users'][2])->toBe([
            'id' => 1,
            'name' => 'Ann',
            'email' => 'ann@example.com',
            'role' => null,
            'created_at' => '2026-09-01 10:00:00',
            'last_login_at' => '2026-09-20 08:00:00',
            'disabled_at' => null,
            'password_change_required' => null,
        ]);

    $search = runAuthTool($workspace, ['op' => 'users', 'search' => 'BOB@'])['data'];
    expect($search['total'])->toBe(1)->and($search['users'][0]['name'])->toBe('Bob');

    // LIKE wildcards in the search are matched literally.
    expect(runAuthTool($workspace, ['op' => 'users', 'search' => '_'])['data']['total'])->toBe(1);

    $page = runAuthTool($workspace, ['op' => 'users', 'per_page' => 2, 'page' => 2])['data'];
    expect(array_column($page['users'], 'name'))->toBe(['Ann']);
})->group('APPAUTH-001');

test('it maps renamed columns and leaves missing ones empty', function () {
    $workspace = authWorkspace(['users' => ['table' => 'users', 'name' => 'email', 'last_login_at' => 'signed_in_at']]);

    $user = runAuthTool($workspace, ['op' => 'users', 'search' => 'ann'])['data']['users'][0];

    expect($user)->toMatchArray(['name' => 'ann@example.com', 'last_login_at' => null]);
})->group('APPAUTH-001');

test('it explains when the users table does not exist yet', function () {
    $response = runAuthTool(authWorkspace(['users' => ['table' => 'members']]), ['op' => 'users']);

    expect($response['ok'])->toBeFalse()->and($response['error'])->toContain("Couldn't find the members table");
})->group('APPAUTH-001');

test('it saves provider keys into the env file, replacing old values and keeping the rest', function () {
    $workspace = authWorkspace();
    file_put_contents($workspace.'/.env', "GITHUB_CLIENT_ID=old\n", FILE_APPEND);

    $response = runAuthTool($workspace, ['op' => 'keys', 'provider' => 'github', 'client_id' => 'Iv1.new', 'client_secret' => 'abc123']);

    expect($response['data']['keys']['github'])->toBe(['client_id' => true, 'client_secret' => true])
        ->and(file_get_contents($workspace.'/.env'))->toBe("APP_NAME=Demo\nDB_CONNECTION=sqlite\nGITHUB_CLIENT_ID=Iv1.new\nGITHUB_CLIENT_SECRET=abc123\n");

    // Saving only the ID leaves the secret alone.
    runAuthTool($workspace, ['op' => 'keys', 'provider' => 'github', 'client_id' => 'Iv1.newer']);
    expect(file_get_contents($workspace.'/.env'))->toContain("GITHUB_CLIENT_ID=Iv1.newer\nGITHUB_CLIENT_SECRET=abc123\n");
})->group('APPAUTH-001');

test('it refuses keys that could inject env lines, and env files outside the project', function () {
    $workspace = authWorkspace();

    $injected = runAuthTool($workspace, ['op' => 'keys', 'provider' => 'google', 'client_id' => "abc\nAPP_KEY=x"]);
    expect($injected['ok'])->toBeFalse()
        ->and(file_get_contents($workspace.'/.env'))->not->toContain('APP_KEY');

    $escaped = runAuthTool(authWorkspace(['env_file' => '../outside.env']), ['op' => 'status']);
    expect($escaped['ok'])->toBeFalse()->and($escaped['error'])->toContain('inside the project');
})->group('APPAUTH-001');

test('it counts users who signed in since a time, for Growth', function () {
    $workspace = authWorkspace();

    expect(runAuthTool($workspace, ['op' => 'active', 'since' => '2026-09-19 00:00:00'])['data'])->toBe(['count' => 1])
        ->and(runAuthTool($workspace, ['op' => 'active', 'since' => '2026-09-21 00:00:00'])['data'])->toBe(['count' => 0]);

    $renamed = authWorkspace(['users' => ['table' => 'users', 'last_login_at' => 'signed_in_at']]);
    expect(runAuthTool($renamed, ['op' => 'active', 'since' => '2026-09-19 00:00:00'])['data'])->toBe(['count' => null])
        ->and(runAuthTool(databaseWorkspace(), ['op' => 'active', 'since' => '2026-09-19 00:00:00'])['ok'])->toBeFalse();
})->group('GROW-001');

test('it explains when the app has no database it can find', function () {
    $workspace = authWorkspace();
    unlink($workspace.'/database/database.sqlite');
    file_put_contents($workspace.'/.env', "APP_NAME=Demo\n");

    $response = runAuthTool($workspace, ['op' => 'users']);

    expect($response['ok'])->toBeFalse()->and($response['error'])->toContain('DATABASE_URL');
})->group('APPAUTH-001');

test('it edits a user and checks the email', function () {
    $workspace = authWorkspace();

    expect(runAuthTool($workspace, ['op' => 'update-user', 'id' => '2', 'name' => 'Robert', 'email' => ' rob@example.com ']))
        ->toBe(['ok' => true, 'data' => ['updated' => true]]);

    $user = runAuthTool($workspace, ['op' => 'users', 'search' => 'rob@'])['data']['users'][0];
    expect($user)->toMatchArray(['id' => 2, 'name' => 'Robert', 'email' => 'rob@example.com']);

    expect(runAuthTool($workspace, ['op' => 'update-user', 'id' => '2', 'email' => 'nope'])['error'])->toBe('Enter a valid email address.')
        ->and(runAuthTool($workspace, ['op' => 'update-user', 'id' => '99', 'name' => 'Ghost'])['error'])->toContain("doesn't exist");
})->group('APPAUTH-001');

test('it deletes a user, and explains when other data depends on them', function () {
    $workspace = authWorkspace();

    expect(runAuthTool($workspace, ['op' => 'delete-user', 'id' => '3'])['ok'])->toBeTrue()
        ->and(runAuthTool($workspace, ['op' => 'users'])['data']['total'])->toBe(2);

    // Stands in for a foreign key without ON DELETE CASCADE.
    (new PDO('sqlite:'.$workspace.'/database/database.sqlite'))
        ->exec("CREATE TRIGGER keep_owner BEFORE DELETE ON users BEGIN SELECT RAISE(ABORT, 'contacts still reference this user'); END");

    $response = runAuthTool($workspace, ['op' => 'delete-user', 'id' => '1']);

    expect($response['ok'])->toBeFalse()
        ->and($response['error'])->toContain('other data in your app belongs to them')
        ->and(runAuthTool($workspace, ['op' => 'users'])['data']['total'])->toBe(2);
})->group('APPAUTH-001');

test('it adds users and sets passwords through the app helper', function () {
    $workspace = withUsersHelper(authWorkspace());

    expect(runAuthTool($workspace, ['op' => 'status'])['data']['can_create'])->toBeTrue();

    $created = runAuthTool($workspace, ['op' => 'create-user', 'name' => 'Dee', 'email' => 'dee@example.com', 'password' => 'correct horse']);
    expect($created)->toBe(['ok' => true, 'data' => ['created' => true, 'id' => 4]])
        ->and(password_verify('correct horse', workspacePasswordHash($workspace, 4)))->toBeTrue();

    expect(runAuthTool($workspace, ['op' => 'create-user', 'name' => 'Dee', 'email' => 'dee@example.com', 'password' => 'correct horse'])['error'])
        ->toBe('That email already has an account.');

    runAuthTool($workspace, ['op' => 'set-password', 'id' => '1', 'password' => 'new password']);
    expect(password_verify('new password', workspacePasswordHash($workspace, 1)))->toBeTrue()
        ->and(runAuthTool($workspace, ['op' => 'set-password', 'id' => '1', 'password' => 'short'])['error'])->toContain('at least 8');
})->group('APPAUTH-001');

test('without the helper, adding users says to ask the agent', function () {
    $workspace = authWorkspace();

    expect(runAuthTool($workspace, ['op' => 'status'])['data']['can_create'])->toBeFalse()
        ->and(runAuthTool($workspace, ['op' => 'create-user', 'name' => 'Dee', 'email' => 'dee@example.com', 'password' => 'correct horse'])['error'])
        ->toContain('Ask the agent');
})->group('APPAUTH-001');

test('it turns accounts off and on and requires a new password', function () {
    $workspace = withAccountControls(authWorkspace());

    expect(runAuthTool($workspace, ['op' => 'update-user', 'id' => '1', 'disabled' => true, 'password_change_required' => true])['ok'])->toBeTrue();

    $ann = runAuthTool($workspace, ['op' => 'users', 'search' => 'ann'])['data'];
    expect($ann['users'][0]['disabled_at'])->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/')
        ->and($ann['users'][0]['password_change_required'])->toBeTrue()
        ->and($ann['capabilities'])->toBe(['helper' => false, 'disable' => true, 'require_password_change' => true, 'roles' => true, 'sign_in_as' => false]);

    runAuthTool($workspace, ['op' => 'update-user', 'id' => '1', 'disabled' => false, 'password_change_required' => false]);
    expect(runAuthTool($workspace, ['op' => 'users', 'search' => 'ann'])['data']['users'][0])
        ->toMatchArray(['disabled_at' => null, 'password_change_required' => false]);
})->group('APPAUTH-001');

test('account controls an older app lacks are explained, not silently skipped', function () {
    $workspace = authWorkspace();

    expect(runAuthTool($workspace, ['op' => 'capabilities'])['data'])->toBe(['helper' => false, 'disable' => false, 'require_password_change' => false, 'roles' => false, 'sign_in_as' => false])
        ->and(runAuthTool($workspace, ['op' => 'users'])['data']['users'][0])->toMatchArray(['disabled_at' => null, 'password_change_required' => null])
        ->and(runAuthTool($workspace, ['op' => 'update-user', 'id' => '1', 'disabled' => true])['error'])->toContain('Turning accounts off needs an update')
        ->and(runAuthTool($workspace, ['op' => 'update-user', 'id' => '1', 'password_change_required' => true])['error'])->toContain('Requiring a new password needs an update');
})->group('APPAUTH-001');

test('it signs a user out everywhere, alone or with a new password', function () {
    $workspace = withUsersHelper(authWorkspace());

    expect(runAuthTool($workspace, ['op' => 'sign-out', 'id' => '2']))->toBe(['ok' => true, 'data' => ['signed_out' => true]]);
    runAuthTool($workspace, ['op' => 'set-password', 'id' => '1', 'password' => 'new password', 'sign_out' => true]);
    runAuthTool($workspace, ['op' => 'set-password', 'id' => '3', 'password' => 'new password']);

    expect(signedOutUsers($workspace))->toBe(['2', '1']);
})->group('APPAUTH-001');

test('it changes roles, only to roles the app declares', function () {
    $workspace = withAccountControls(authWorkspace(['roles' => ['owner', 'editor', 'viewer']]));
    (new PDO('sqlite:'.$workspace.'/database/database.sqlite'))->exec("UPDATE users SET role = 'viewer'");

    expect(runAuthTool($workspace, ['op' => 'status'])['data']['roles'])->toBe(['owner', 'editor', 'viewer'])
        ->and(runAuthTool($workspace, ['op' => 'update-user', 'id' => '2', 'role' => 'editor'])['ok'])->toBeTrue()
        ->and(runAuthTool($workspace, ['op' => 'users', 'search' => 'bob'])['data']['users'][0]['role'])->toBe('editor')
        ->and(runAuthTool($workspace, ['op' => 'update-user', 'id' => '2', 'role' => 'admin'])['error'])->toBe('Your app has no role called that.');

    expect(runAuthTool(authWorkspace(), ['op' => 'status'])['data']['roles'])->toBe(['admin', 'member'])
        ->and(runAuthTool(authWorkspace(), ['op' => 'update-user', 'id' => '2', 'role' => 'admin'])['error'])->toContain('Roles need an update');
})->group('APPAUTH-001');

test('it exports every user', function () {
    $export = runAuthTool(withAccountControls(authWorkspace()), ['op' => 'export'])['data'];

    expect($export['truncated'])->toBeFalse()
        ->and(array_column($export['users'], 'email'))->toBe(['100%@example.com', 'bob@example.com', 'ann@example.com'])
        ->and($export['users'][2]['role'])->toBe('admin');
})->group('APPAUTH-001');

test('it gets one-time sign-in links from helpers that declare them', function () {
    $old = withUsersHelper(authWorkspace());

    expect(runAuthTool($old, ['op' => 'sign-in-link', 'id' => '1'])['error'])->toContain('Signing in as a user needs an update');

    $workspace = withUsersHelper(authWorkspace(['helper' => ['sign-in-link']]));

    expect(runAuthTool($workspace, ['op' => 'capabilities'])['data']['sign_in_as'])->toBeTrue()
        ->and(runAuthTool($workspace, ['op' => 'sign-in-link', 'id' => '1'])['data'])->toBe(['path' => '/auth/zap-sign-in?token=one-time-1']);
})->group('APPAUTH-001');

test('it writes OneDrop sign-in settings, even before sign-in is set up', function () {
    $settings = [
        'op' => 'onedrop',
        'client_id' => 'od_abc123',
        'client_secret' => 'Secret123',
        'authorize_url' => 'http://zap.test/oauth/authorize',
        'token_url' => 'http://host.docker.internal:8000/oauth/token',
        'userinfo_url' => 'http://host.docker.internal:8000/oauth/userinfo',
    ];
    $workspace = databaseWorkspace();

    expect(runAuthTool($workspace, $settings)['ok'])->toBeTrue()
        ->and(file_get_contents($workspace.'/.env'))->toContain("ONEDROP_CLIENT_ID=od_abc123\n")
        ->toContain("ONEDROP_TOKEN_URL=http://host.docker.internal:8000/oauth/token\n");

    $injected = runAuthTool($workspace, [...$settings, 'authorize_url' => "http://x.test\nAPP_KEY=stolen"]);
    expect($injected['ok'])->toBeFalse()
        ->and(file_get_contents($workspace.'/.env'))->not->toContain('stolen');
})->group('APPAUTH-002');
