<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;

// Only the hosted install has the home page (HOME-005).
beforeEach(function () {
    config(['app.multi_tenant' => true]);
});

test('a visitor compares plans and switches between yearly and monthly prices', function () {
    visit('/')
        ->click('Pricing')
        ->assertPathIs('/pricing')
        ->assertSee('Bring your own AI. We never mark it up.')
        ->assertSee('Self-hosted')
        ->assertSee('Most popular')
        ->assertSeeIn('@plan-team', '$50 in AI credits')
        ->assertSeeIn('@team-price', '$79')
        ->click('Monthly')
        ->assertSeeIn('@team-price', '$99')
        ->assertSeeIn('@solo-price', '$20')
        ->assertSee('No surprise bills. Ever.')
        ->assertSee('Pricing questions')
        ->assertNoJavaScriptErrors();
})->group('PRICE-001');

test('a visitor starts signing up from a paid plan', function () {
    visit('/pricing')
        ->click('@solo-cta')
        ->assertPathIs('/register')
        ->assertNoJavaScriptErrors();
})->group('PRICE-001');

test('the dev user sees a button to open their dashboard on each paid plan', function () {
    $this->seed(DatabaseSeeder::class);
    $this->actingAs(User::where('email', 'dev@example.com')->sole());

    visit('/pricing')
        ->assertSeeIn('@plan-team', 'Open your dashboard')
        ->assertSeeIn('@plan-self-hosted', 'Read the install guide')
        ->assertNoJavaScriptErrors();
})->group('PRICE-001');
