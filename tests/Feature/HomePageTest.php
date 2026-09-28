<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests see the home page', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('welcome')->where('auth.user', null));
})->group('HOME-001');

test('logged-in users see the home page with their account', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('welcome')->where('auth.user.id', $user->id));
})->group('HOME-001');

test('pages include link preview tags for social sharing', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('<meta property="og:title" content="OneDrop: vibe-code apps for production">', false)
        ->assertSee('<meta property="og:image" content="'.asset('images/og.png').'">', false)
        ->assertSee('<meta name="twitter:card" content="summary_large_image">', false);

    expect(public_path('images/og.png'))->toBeFile();
})->group('HOME-002');
