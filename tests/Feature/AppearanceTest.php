<?php

test('pages render in the dark theme by default', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee('<html lang="en" class="dark">', false);
})->group('SET-001');

test('pages render in the theme the user picked', function () {
    $this->withUnencryptedCookie('appearance', 'light')
        ->get('/login')
        ->assertOk()
        ->assertDontSee('class="dark"', false);
})->group('SET-001');
