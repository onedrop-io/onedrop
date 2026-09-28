<?php

use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\User;
use App\Sandbox\Agents\ProjectNamer;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->seed(DatabaseSeeder::class);
    $this->user = User::where('email', 'dev@example.com')->sole();
    AgentConnection::factory()->for($this->user)->create();
    $this->actingAs($this->user);
});

test('the sidebar menu pins, renames, marks unread, archives, and deletes projects', function () {
    $todo = Project::factory()->for($this->user)->create(['name' => 'Todo App', 'read_at' => now()]);
    $blog = Project::factory()->for($this->user)->create(['name' => 'Blog', 'read_at' => now()]);
    $sidebar = '[data-sidebar="sidebar"]';
    $todoMenu = '[aria-label="Actions for Todo App"]';

    $page = visit('/dashboard')
        ->assertSeeIn($sidebar, 'Todo App')
        ->assertDontSeeIn($sidebar, 'Pinned')
        ->click($todoMenu)
        ->assertSee('Mark as unread')
        ->assertSee('Copy link')
        ->click('@project-menu-pin')
        ->assertSeeIn($sidebar, 'Pinned');

    expect($todo->fresh()->pinned_at)->not->toBeNull();

    // Letters run the matching action while the menu is open.
    $page->click($todoMenu)
        ->keys('[role="menu"]', 'r')
        ->assertVisible('@project-rename-input')
        ->assertScript('document.activeElement?.dataset.test', 'project-rename-input')
        ->fill('@project-rename-input', 'Team Todos')
        ->click('@project-rename-save')
        ->assertSeeIn($sidebar, 'Team Todos')
        ->assertDontSeeIn($sidebar, 'Todo App');

    $page->click('[aria-label="Actions for Team Todos"]')
        ->click('@project-menu-unread')
        ->assertVisible('@sidebar-project-unread')
        ->click('[aria-label="Actions for Team Todos"]')
        ->assertSee('Mark as read')
        ->click('@project-menu-archive')
        ->assertDontSeeIn($sidebar, 'Team Todos')
        ->click('@sidebar-archived-toggle')
        ->assertSeeIn($sidebar, 'Team Todos');

    $page->click('[aria-label="Actions for Blog"]')
        ->click('@project-menu-delete')
        ->assertSee('Delete Blog?')
        ->click('@project-delete-confirm')
        ->assertSee('Deleted “Blog”.')
        ->assertDontSeeIn($sidebar, 'Blog')
        ->assertNoJavaScriptErrors();

    expect(Project::find($blog->id))->toBeNull()
        ->and($todo->fresh())->name->toBe('Team Todos')
        ->archived_at->not->toBeNull()
        ->read_at->toBeNull();
})->group('PRJ-003');

test('share shows the live app link, or explains how to get one', function () {
    Project::factory()->for($this->user)->create(['name' => 'Todo App', 'publish_status' => 'live', 'published_url' => 'https://todo.example.ts.net']);
    Project::factory()->for($this->user)->create(['name' => 'Blog']);

    visit('/dashboard')
        ->click('[aria-label="Actions for Todo App"]')
        ->click('@project-menu-share')
        ->assertValue('@project-share-url', 'https://todo.example.ts.net')
        ->keys('@project-share-url', 'Escape')
        ->click('[aria-label="Actions for Blog"]')
        ->click('@project-menu-share')
        ->assertSee('Publish the app to get a link you can share.')
        ->assertNoJavaScriptErrors();
})->group('PRJ-003');

test('regenerating the title shows it is being named until the new title arrives', function () {
    $project = Project::factory()->for($this->user)->create(['name' => 'Untitled Project']);

    $page = visit('/dashboard')
        ->click('[aria-label="Actions for Untitled Project"]')
        ->click('@project-menu-regenerate')
        ->assertVisible('@sidebar-project-naming');

    $project->update(['name' => 'Team Todo Tracker']);
    ProjectNamer::doneNaming($project);

    $page->assertSeeIn('[data-sidebar="sidebar"]', 'Team Todo Tracker')
        ->assertMissing('@sidebar-project-naming')
        ->assertNoJavaScriptErrors();
})->group('PRJ-003');
