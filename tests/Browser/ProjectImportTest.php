<?php

use App\Models\AgentConnection;
use App\Models\GitHubAuthorization;
use App\Models\GitHubInstallation;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
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

test('picking "Something that exists" starts the project from a repository', function () {
    $page = visit('/login')
        ->fill('email', 'dev@example.com')
        ->fill('password', 'password')
        ->press('@login-button')
        ->click('@way-existing')
        ->assertVisible('@repository-input')
        ->assertSee('Or install a free app')
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
        ->click('@way-existing')
        ->fill('@repository-input', "file://{$this->root}/missing.git")
        ->press('@composer-send')
        ->assertSee('Couldn\'t reach that repository.')
        ->assertNoJavaScriptErrors();

    expect(Project::count())->toBe(0);
})->group('PRJ-009');

test('once GitHub is connected, the repository field suggests their repositories and links to adding more', function () {
    openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]), $pem);
    config(['services.github_app' => ['id' => '1', 'slug' => 'onedrop-test', 'client_id' => 'Iv1.x', 'client_secret' => 's', 'private_key' => $pem]]);
    Http::fake([
        'api.github.com/user/installations/777/repositories*' => Http::response(['repositories' => [[
            'full_name' => 'dev/timer', 'name' => 'timer', 'private' => true, 'default_branch' => 'main', 'size' => 10,
            'clone_url' => 'https://github.com/dev/timer.git', 'html_url' => 'https://github.com/dev/timer', 'pushed_at' => now()->toIso8601String(),
        ]]]),
    ]);
    $user = User::where('email', 'dev@example.com')->sole();
    GitHubInstallation::factory()->for($user)->create(['installation_id' => 777, 'account_login' => 'dev', 'account_type' => 'User']);
    GitHubAuthorization::factory()->for($user)->create(['github_login' => 'dev']);

    visit('/login')
        ->fill('email', 'dev@example.com')
        ->fill('password', 'password')
        ->press('@login-button')
        ->click('@way-existing')
        ->assertSee('Pick one of your repositories')
        ->assertAttributeContains('@repository-add-github', 'href', '/github/install')
        ->click('@repository-input')
        ->assertSeeIn('@repository-options', 'dev/timer')
        ->assertNoJavaScriptErrors();
})->group('PRJ-009');
