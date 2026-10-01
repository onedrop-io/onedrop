<?php

use App\Models\AgentConnection;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Vite;

beforeEach(function () {
    $this->hot = storage_path('framework/testing-vite-hot');
    File::put($this->hot, 'http://[::1]:5173');
    Vite::useHotFile($this->hot);
    $this->user = User::factory()->has(AgentConnection::factory())->create();
});

afterEach(fn () => File::delete($this->hot));

test('on this machine pages use the vite dev server', function () {
    $this->actingAs($this->user)
        ->get('http://localhost:8000/o/'.$this->user->currentOrganization()->slug)
        ->assertSee('http://[::1]:5173', escape: false);
})->group('REM-001');

test('through a remote hostname pages use built https assets', function () {
    $response = $this->actingAs($this->user)
        ->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->withHeaders(['X-Forwarded-Proto' => 'https'])
        ->get('http://macbook.tail2eb16.ts.net/o/'.$this->user->currentOrganization()->slug);

    $response->assertOk()
        ->assertDontSee('[::1]:5173', escape: false)
        ->assertSee('https://macbook.tail2eb16.ts.net/build/assets/', escape: false);
})->group('REM-001');

test('forwarded headers are only trusted from the local proxy', function () {
    $this->actingAs($this->user)
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->withHeaders(['X-Forwarded-Proto' => 'https'])
        ->get('http://macbook.tail2eb16.ts.net/o/'.$this->user->currentOrganization()->slug)
        ->assertDontSee('https://macbook.tail2eb16.ts.net/build/', escape: false);
})->group('REM-001');
