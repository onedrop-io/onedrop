<?php

use App\Actions\Auth\DesktopSignIn;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Symfony\Component\Process\Process;

test('the desktop app signs in through the browser and can be signed out in settings', function () {
    $this->seed(DatabaseSeeder::class);
    $dev = User::where('email', 'dev@example.com')->sole();
    $this->actingAs($dev);

    // Stands in for the app's loopback listener (desktop/src-tauri/src/sign_in.rs).
    $docroot = sys_get_temp_dir().'/onedrop-desktop-callback-'.getmypid();
    @mkdir($docroot);
    file_put_contents("{$docroot}/index.php", '<?php echo "Back in the app with ".htmlspecialchars($_GET["code"] ?? "no code");');
    $port = random_int(40000, 49999);
    $app = new Process([PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $docroot, "{$docroot}/index.php"]);
    $app->start();
    usleep(300_000);

    try {
        $verifier = str_repeat('v', 64);
        $redirectUri = "http://127.0.0.1:{$port}/callback";

        $page = visit('/desktop/authorize?'.http_build_query([
            'redirect_uri' => $redirectUri,
            'state' => 'xyz',
            'code_challenge' => DesktopSignIn::challengeFor($verifier),
            'code_challenge_method' => 'S256',
            'device' => 'Test MacBook',
        ]))
            ->assertSeeIn('@desktop-device', 'Test MacBook')
            ->assertSee('Signs in as dev@example.com')
            ->assertNoJavaScriptErrors()
            ->click('@desktop-allow')
            ->assertSee('Back in the app with ');

        parse_str((string) parse_url($page->url(), PHP_URL_QUERY), $query);
        expect($query['state'])->toBe('xyz');
        $code = $query['code'];

        $this->postJson(route('api.desktop.token.store'), [
            'code' => $code,
            'code_verifier' => $verifier,
            'redirect_uri' => $redirectUri,
        ])->assertOk();

        visit('/settings/desktop')
            ->assertSeeIn('@desktop-device', 'Test MacBook')
            ->click('@desktop-device-sign-out')
            ->assertSee('Signed out “Test MacBook”.')
            ->assertPresent('@desktop-devices-empty')
            ->assertNoJavaScriptErrors();

        expect($dev->tokens()->count())->toBe(0);
    } finally {
        $app->stop();
        @unlink("{$docroot}/index.php");
        @rmdir($docroot);
    }
})->group('DESK-001');
