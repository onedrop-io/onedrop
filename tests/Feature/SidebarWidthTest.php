<?php

use App\Models\AgentConnection;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the saved sidebar width is shared with every page', function () {
    $this->actingAs(User::factory()->has(AgentConnection::factory())->create())
        ->withUnencryptedCookie('sidebar_width', '320')
        ->followingRedirects()->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('sidebarWidth', 320));
})->group('PRJ-002');

test('there is no sidebar width until one is saved', function () {
    $this->actingAs(User::factory()->has(AgentConnection::factory())->create())
        ->followingRedirects()->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('sidebarWidth', null));
})->group('PRJ-002');
