<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;

test('a visitor reads the home page and goes to sign up', function () {
    visit('/')
        ->assertSee('for production.')
        ->assertSee('Built on tools your IT team already trusts')
        ->assertSee('Tailscale')
        ->assertSee('Blaxel')
        ->assertSee('Runtime')
        ->assertSee('Daytona')
        ->assertPresent('#integrations a[href="https://blaxel.ai"]')
        ->assertPresent('#integrations a[href="https://withruntime.com"]')
        ->assertSee('Google Cloud')
        ->assertSee('Hetzner')
        ->assertSee('DigitalOcean')
        ->assertSee('macOS')
        ->assertSee('From idea to link in four steps')
        ->assertSeeIn('@install-command', 'curl -fsSL https://onedrop.io/install | sh')
        ->click('@copy-install-command')
        ->assertSeeIn('@copy-install-command', 'Copied')
        ->assertPresent('#install a[href="https://docs.onedrop.io/install"]')
        ->assertPresent('#install a[href="https://github.com/onedrop-io/onedrop"]')
        ->assertPresent('#compare a[href="#install"]')
        ->assertPresent('header a[href="/#install"]')
        ->assertPresent('header a[href="https://github.com/onedrop-io/onedrop"]')
        ->assertPresent('footer a[href="https://github.com/onedrop-io/onedrop"]')
        ->assertSee('Questions people ask first')
        ->assertPresent('header a[href="https://docs.onedrop.io/introduction"]')
        ->assertPresent('footer a[href="https://docs.onedrop.io/introduction"]')
        ->assertSee('Planet maps by Solar System Scope')
        ->assertPresent('footer a[href="https://www.solarsystemscope.com/textures/"]')
        ->assertPresent('footer a[href="https://creativecommons.org/licenses/by/4.0/"]')
        ->assertSee("Moon maps by NASA's Scientific Visualization Studio")
        ->assertPresent('footer a[href="https://svs.gsfc.nasa.gov/4720"]')
        ->assertSee('Hurricane Isabel photo by Jeff Schmaltz, MODIS Land Rapid Response Team, NASA GSFC')
        ->assertSee('Apollo 11 moonwalk footage by NASA')
        ->assertPresent('footer a[href="https://commons.wikimedia.org/wiki/File:Apollo_11_Moonwalk_Montage.webm"]')
        ->assertSee('Voyager and Pioneer models and the Golden Record photo by NASA')
        ->assertSee('Pioneer plaque drawing by Oona Räisänen')
        ->assertSee('2MASS Redshift Survey')
        ->click('Do I need to know how to code?')
        ->assertSee('If you can describe what you want to a coworker')
        ->assertNoJavaScriptErrors()
        ->click('@primary-cta')
        ->assertPathIs('/register');
})->group('HOME-001');

test('a visitor picks where to install and copies the command', function () {
    visit('/')
        ->assertSee('Install it in one command')
        ->assertSee('Create your account')
        ->assertSee('5 GB of free disk')
        ->click('@install-tab-server')
        ->assertSeeIn('@install-command', 'onedrop.io/install | sh -s -- --domain auto')
        ->assertSee('sslip.io')
        ->click('@install-tab-domain')
        ->assertSeeIn('@install-command', 'onedrop.io/install | sh -s -- --domain onedrop.example.com')
        ->click('@copy-install-command')
        ->assertSeeIn('@copy-install-command', 'Copied')
        ->click('@install-tab-laptop')
        ->assertSeeIn('@install-command', 'onedrop.io/install | sh')
        ->assertDontSeeIn('@install-command', '--domain')
        ->assertNoJavaScriptErrors();
})->group('HOME-001', 'INSTALL-001');

test('the demo builds an app and publishes it to an instant Tailscale link', function () {
    visit('/')
        ->wait(14)
        ->assertSee('Published to your tailnet')
        ->assertSee('https://timeoff.yourteam.ts.net')
        ->press('Pause demo')
        ->assertSee('Play demo')
        ->assertNoJavaScriptErrors();
})->group('HOME-001');

test('a visitor watches the demo video and closes it', function () {
    visit('/')
        ->click('@watch-demo-video')
        ->assertVisible('@demo-video')
        ->assertAttribute('@demo-video', 'src', 'https://pub-c655146bc458440aa8c0969e063c9a4c.r2.dev/onedrop.mp4')
        ->keys('@demo-video', 'Escape')
        ->assertMissing('@demo-video')
        ->assertNoJavaScriptErrors();
})->group('HOME-001');

test('a visitor opens the product menu and jumps to a feature', function () {
    visit('/')
        ->assertDontSee('Share via Tailscale')
        ->hover('@product-menu')
        ->assertSee('Share via Tailscale')
        ->assertSee('Watch the demo')
        ->click('Share via Tailscale')
        ->assertFragmentIs('feature-share')
        ->assertDontSee('Share via Tailscale')
        ->assertNoJavaScriptErrors();
})->group('HOME-001');

test('hovering over the logo droplet pops it into particles and it comes back', function () {
    visit('/')
        ->wait(5)
        ->hover('@logo-drop')
        ->assertAttribute('@logo-drop', 'data-state', 'popped')
        ->wait(1.5)
        ->assertAttribute('@logo-drop', 'data-state', 'idle')
        ->assertNoJavaScriptErrors();
})->group('HOME-001');

test('the dev user sees a button to open their dashboard', function () {
    $this->seed(DatabaseSeeder::class);
    $this->actingAs(User::where('email', 'dev@example.com')->sole());

    visit('/')
        ->assertSeeIn('@primary-cta', 'Open your dashboard')
        ->assertDontSee('Log in')
        ->assertNoJavaScriptErrors();
})->group('HOME-001');

test('the home page fits a phone screen without scrolling sideways', function () {
    $page = visit('/')->resize(390, 844);

    expect($page->script('document.documentElement.scrollWidth'))->toBeLessThanOrEqual(390);

    $page->assertVisible('@copy-install-command')
        ->assertNoJavaScriptErrors();
})->group('HOME-001', 'INSTALL-001');

test('Droppy offers hints, points at an easter egg, and cheers when one is found', function () {
    $page = visit('/')->resize(1440, 900)->wait(5);

    // Headless Chrome draws the fallback black hole, so there's no galaxy: announce one the way the galaxy does.
    $page->script("window.dispatchEvent(new CustomEvent('cosmic-zoom', { detail: { level: 'galaxy', isAvailable: true } }))");

    $page->wait(16)
        ->assertSeeIn('@droppy-bubble', 'Would you like a hint?')
        ->click('Yes please')
        ->assertSeeIn('@droppy-bubble', 'labeled Sol')
        ->click('Show me')
        ->assertAttribute('@earth', 'data-hinted', 'true')
        ->hover('@logo-drop')
        ->assertSeeIn('@droppy-bubble', 'You found my cousin in the logo!')
        ->assertSeeIn('@droppy-found', '1 of 9 found')
        ->click("Don't show Droppy again")
        ->assertMissing('@droppy')
        ->assertNoJavaScriptErrors();

    expect($page->script("localStorage.getItem('onedrop.droppy-dismissed')"))->toBe('1')
        ->and($page->script("localStorage.getItem('onedrop.easter-eggs')"))->toBe('["logo"]');
})->group('HOME-003');
