<?php

use App\Models\AgentConnection;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the home page has the templates, and the free apps after it shows', function () {
    $this->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page->component('welcome')
            ->where('templates.0.value', 'crm')
            ->where('compose', false)
            ->missing('apps')
            ->loadDeferredProps(fn (Assert $reload) => $reload->where('apps.0.value', 'dokploy/n8n')));
})->group('HOME-004');

test('a visitor who sends a prompt signs up, then finds it on the new-project page, once', function () {
    $this->post(route('start'), ['prompt' => 'A tracker for our vans', 'template' => 'crm'])
        ->assertRedirect(route('register'));

    expect(session('url.intended'))->toBe(route('dashboard'))
        ->and(session('start'))->toBe(['prompt' => 'A tracker for our vans', 'template' => 'crm']);

    $visitor = User::factory()->has(AgentConnection::factory())->create();

    $this->actingAs($visitor)->followingRedirects()->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->component('projects/create')
            ->where('start', ['prompt' => 'A tracker for our vans', 'template' => 'crm']));

    $this->actingAs($visitor)->followingRedirects()->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('start', null));
})->group('HOME-004');

test('a visitor who picks a free app keeps it through signing up', function () {
    $this->post(route('start'), ['template' => 'dokploy/n8n'])->assertRedirect(route('register'));

    expect(session('start'))->toBe(['prompt' => null, 'template' => 'dokploy/n8n']);
})->group('HOME-004');

test('a signed-in user goes straight to the new-project page', function () {
    $user = User::factory()->has(AgentConnection::factory())->create();

    $this->actingAs($user)->post(route('start'), ['prompt' => 'A tracker for our vans'])
        ->assertRedirect(route('dashboard'));
})->group('HOME-004');

test('starting needs a prompt or a template that exists', function () {
    $this->post(route('start'), [])->assertSessionHasErrors(['prompt' => 'Describe what you want to build.']);
    $this->post(route('start'), ['template' => 'dokploy/missing'])->assertSessionHasErrors('template');
    $this->post(route('start'), ['prompt' => str_repeat('a', 5001)])->assertSessionHasErrors('prompt');

    expect(session('start'))->toBeNull();
})->group('HOME-004');

test('signing up shows the free app they picked until they drop it', function () {
    $this->post(route('start'), ['template' => 'dokploy/n8n']);

    $this->get(route('register'))
        ->assertInertia(fn (Assert $page) => $page->component('auth/register')
            ->where('pendingStart.prompt', null)
            ->where('pendingStart.template.label', 'n8n')
            ->where('pendingStart.template.version', '1.104.0')
            ->has('pendingStart.template.cover'));

    $this->from(route('register'))->delete(route('start.destroy'))->assertRedirect(route('register'));

    $this->get(route('register'))->assertInertia(fn (Assert $page) => $page->where('pendingStart', null));
})->group('HOME-004');

test('a prompt they typed waits beside logging in, and the new-project page takes it', function () {
    $this->post(route('start'), ['prompt' => 'A tracker for our vans']);

    $this->get(route('login'))
        ->assertInertia(fn (Assert $page) => $page->where('pendingStart', ['prompt' => 'A tracker for our vans', 'template' => null]));

    $user = User::factory()->has(AgentConnection::factory())->create();

    $this->actingAs($user)->followingRedirects()->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('start.prompt', 'A tracker for our vans')->where('pendingStart', null));
})->group('HOME-004');
