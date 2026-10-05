<?php

use App\Models\Project;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the sidebar offers the desktop app once the user has a project', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('offerDesktopApp', false));

    Project::factory()->for($user)->create();

    $this->actingAs($user)->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('offerDesktopApp', true));
})->group('DESK-005');

test('the sidebar stops offering the desktop app once it is signed in anywhere', function () {
    $user = User::factory()->has(Project::factory())->create();
    $user->createToken('Laptop');

    $this->actingAs($user)->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('offerDesktopApp', false));
})->group('DESK-005');

test('guests are not offered the desktop app', function () {
    config(['app.multi_tenant' => true]);

    $this->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page->where('offerDesktopApp', false));
})->group('DESK-005');
