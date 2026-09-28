<?php

use App\Models\AgentConnection;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

test('the dev user sees the OneDrop name and links after logging in', function () {
    $this->seed(DatabaseSeeder::class);
    AgentConnection::factory()->for(User::where('email', 'dev@example.com')->sole())->create();

    visit('/login')
        ->fill('email', 'dev@example.com')
        ->fill('password', 'password')
        ->press('@login-button')
        ->assertPathIs('/dashboard')
        ->assertTitleContains('OneDrop')
        ->assertSee('OneDrop')
        ->assertDontSee('Laravel')
        ->assertAttribute('a[href="https://github.com/phishy/zap"]', 'target', '_blank')
        ->assertPresent('a[href="https://github.com/phishy/zap/tree/main/docs"]')
        ->assertNoJavaScriptErrors();
})->group('BRAND-001');
