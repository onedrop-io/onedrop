<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests see the pricing page', function () {
    $this->get(route('pricing'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('pricing')->where('auth.user', null));
})->group('PRICE-001');

test('logged-in users see the pricing page with their account', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('pricing'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('pricing')->where('auth.user.id', $user->id));
})->group('PRICE-001');
