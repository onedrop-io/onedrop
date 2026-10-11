<?php

test('pages render in the dark theme by default', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee('<html lang="en" class="dark" data-theme="onedrop-dark">', false);
})->group('SET-001', 'SET-002');

test('pages render in the theme the user picked', function () {
    $this->withUnencryptedCookie('appearance', 'light')
        ->get('/login')
        ->assertOk()
        ->assertDontSee('class="dark"', false);
})->group('SET-001');

test('pages render in the color theme picked for the mode showing', function () {
    $this->withUnencryptedCookie('appearance', 'dark')
        ->withUnencryptedCookie('theme-dark', 'dracula')
        ->withUnencryptedCookie('theme-light', 'github-light')
        ->get('/login')
        ->assertOk()
        ->assertSee('class="dark" data-theme="dracula"', false);

    $this->withUnencryptedCookie('appearance', 'light')
        ->withUnencryptedCookie('theme-dark', 'dracula')
        ->withUnencryptedCookie('theme-light', 'github-light')
        ->get('/login')
        ->assertOk()
        ->assertSee('data-theme="github-light"', false);
})->group('SET-002');

test('the system mode picks between the light and dark theme in the browser', function () {
    $this->withUnencryptedCookie('appearance', 'system')
        ->withUnencryptedCookie('theme-dark', 'nord')
        ->withUnencryptedCookie('theme-light', 'solarized-light')
        ->get('/login')
        ->assertOk()
        ->assertSee("prefersDark ? 'nord' : 'solarized-light'", false);
})->group('SET-002');

test('a malformed theme or appearance cookie falls back to OneDrop', function () {
    $this->withUnencryptedCookie('appearance', '\'</script>')
        ->withUnencryptedCookie('theme-dark', '"><script>alert(1)</script>')
        ->get('/login')
        ->assertOk()
        ->assertSee('class="dark" data-theme="onedrop-dark"', false)
        ->assertDontSee('alert(1)', false);
})->group('SET-002');
