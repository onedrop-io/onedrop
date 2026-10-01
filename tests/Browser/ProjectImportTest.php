<?php

use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    AgentConnection::factory()->for(User::where('email', 'dev@example.com')->sole())->create();
    config(['sandbox.git.protocols' => ['https', 'file'], 'services.github_app.private_key' => null]);

    $this->root = sys_get_temp_dir().'/onedrop-browser-import-'.uniqid();
    File::ensureDirectoryExists("{$this->root}/work");
    Process::path("{$this->root}/work")->run('git init -q -b main && echo hi > a.txt && git add a.txt && git -c user.name=Me -c user.email=me@example.com commit -q -m One')->throw();
    Process::path($this->root)->run(['git', 'clone', '-q', '--bare', "{$this->root}/work", "{$this->root}/team-timer.git"])->throw();
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

test('turning on Repository next to the agent picker starts the project from a repository', function () {
    $page = visit('/login')
        ->fill('email', 'dev@example.com')
        ->fill('password', 'password')
        ->press('@login-button')
        ->assertSee('Or start from a template')
        ->press('@repository-toggle')
        ->assertVisible('@repository-input')
        ->assertDontSee('Or start from a template')
        ->assertSee('Paste a public repository’s link.')
        ->fill('@repository-input', "file://{$this->root}/team-timer.git")
        ->press('@composer-send')
        ->assertSee('I imported this app from its repository. Get it running in the preview.');

    $project = Project::sole();

    $page->assertPathIs("/projects/{$project->id}")
        ->assertSeeIn('[data-sidebar="sidebar"]', 'Team Timer')
        ->assertNoJavaScriptErrors();

    expect($project->git_remote_url)->toBe("file://{$this->root}/team-timer.git");
})->group('PRJ-009');

test('an unreachable repository shows why under the prompt', function () {
    visit('/login')
        ->fill('email', 'dev@example.com')
        ->fill('password', 'password')
        ->press('@login-button')
        ->press('@repository-toggle')
        ->fill('@repository-input', "file://{$this->root}/missing.git")
        ->press('@composer-send')
        ->assertSee('Couldn\'t reach that repository.')
        ->assertNoJavaScriptErrors();

    expect(Project::count())->toBe(0);
})->group('PRJ-009');
