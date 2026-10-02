<?php

use App\Enums\ProjectSort;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->seed(DatabaseSeeder::class);
    $this->user = User::where('email', 'dev@example.com')->sole();
    AgentConnection::factory()->for($this->user)->create();
    $this->actingAs($this->user);
});

test('projects can be sorted from the sidebar and dragged into place', function () {
    Project::factory()->for($this->user)->create(['name' => 'Todo App', 'read_at' => now(), 'created_at' => now()->subDays(2)]);
    $blog = Project::factory()->for($this->user)->create(['name' => 'Blog', 'read_at' => now(), 'created_at' => now()->subDay()]);
    Project::withoutTimestamps(fn () => $blog->forceFill(['updated_at' => now()->subHour()])->save());

    $names = "Array.from(document.querySelectorAll('[data-test=sidebar-project] a')).filter(a => !a.dataset.test).map(a => a.innerText.split('\\n').pop().trim()).join(',')";

    $page = visit('/dashboard')
        ->assertScript($names, 'Todo App,Blog')
        ->click('@sidebar-sort')
        ->click('@sidebar-sort-created')
        ->assertScript($names, 'Blog,Todo App');

    expect($this->user->fresh()->project_sort)->toBe(ProjectSort::Created);

    $page->drag('li[data-test="sidebar-project"]:has-text("Todo App")', 'li[data-test="sidebar-project"]:has-text("Blog")')
        ->assertScript($names, 'Todo App,Blog')
        ->assertSeeIn('[data-sidebar="group-label"]', 'Projects')
        ->assertNoJavaScriptErrors();

    expect($this->user->fresh()->project_sort)->toBe(ProjectSort::Manual);

    // The order is kept after a reload.
    visit('/dashboard')->assertScript($names, 'Todo App,Blog');
})->group('PRJ-010');
