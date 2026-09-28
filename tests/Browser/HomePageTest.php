<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;

test('a visitor reads the home page and goes to sign up', function () {
    visit('/')
        ->assertSee('for production.')
        ->assertSee('Built on tools your IT team already trusts')
        ->assertSee('Tailscale')
        ->assertSee('Daytona')
        ->assertSee('Google Cloud')
        ->assertSee('Hetzner')
        ->assertSee('DigitalOcean')
        ->assertSee('macOS')
        ->assertSee('From idea to link in four steps')
        ->assertSee('Questions people ask first')
        ->click('Do I need to know how to code?')
        ->assertSee('If you can describe what you want to a coworker')
        ->assertNoJavaScriptErrors()
        ->click('@primary-cta')
        ->assertPathIs('/register');
})->group('HOME-001');

test('the demo builds an app and publishes it to an instant Tailscale link', function () {
    visit('/')
        ->wait(14)
        ->assertSee('Published to your tailnet')
        ->assertSee('https://timeoff.yourteam.ts.net')
        ->press('Pause demo')
        ->assertSee('Play demo')
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
