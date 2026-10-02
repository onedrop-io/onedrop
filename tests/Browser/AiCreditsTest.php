<?php

use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Http;

test('the dev user builds without connecting an AI, on AI credits', function () {
    $this->seed(DatabaseSeeder::class);
    app()->instance(SandboxProvider::class, new FakeSandboxProvider);
    config([
        'services.autumn.key' => 'am_sk_test',
        'services.autumn.url' => 'https://autumn.test/v1',
        'services.openrouter.provisioning_key' => 'sk-or-mgmt',
    ]);
    Http::fake([
        'autumn.test/v1/customers' => Http::response(['id' => 'org']),
        'autumn.test/v1/check' => Http::response(['allowed' => true, 'balance' => ['remaining' => 600, 'next_reset_at' => null, 'breakdown' => [
            ['included_grant' => 100, 'remaining' => 100, 'reset' => ['interval' => 'month']],
            ['included_grant' => 500, 'remaining' => 500, 'reset' => ['interval' => 'one_off']],
        ]]]),
    ]);

    visit('/login')
        ->fill('email', 'dev@example.com')
        ->fill('password', 'password')
        ->press('@login-button')
        ->assertPathIs(orgPath())
        ->assertSee('Dev, what are we working on today?')
        ->assertNoJavaScriptErrors();

    $user = User::where('email', 'dev@example.com')->sole();
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['preview_url' => null]);

    visit("/projects/{$project->id}")
        ->assertSeeIn('@model-picker', 'DeepSeek V4.1 Flash')
        ->assertSeeIn('@ai-credits-balance', '$6.00')
        ->click('@model-picker')
        ->assertVisible('[aria-label="AI credits · $6.00 left"]')
        ->assertNoJavaScriptErrors();

    visit('/usage')
        ->assertSeeIn('@ai-credits-card', '$6.00')
        ->assertSeeIn('@ai-credits-card', "This month's credits")
        ->assertSeeIn('@ai-credits-card', '$5.00 of $5.00')
        ->assertNoJavaScriptErrors();
})->group('CREDIT-001');
