<?php

use App\Enums\GitSyncStatus;
use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Jobs\CreateSandbox;
use App\Jobs\ImportRepository;
use App\Jobs\RunAgentTask;
use App\Jobs\UpdateProjectIcon;
use App\Models\AgentConnection;
use App\Models\GitHubAuthorization;
use App\Models\GitHubInstallation;
use App\Models\Project;
use App\Models\User;
use App\Sandbox\GitRemote;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['sandbox.git.protocols' => ['https', 'file'], 'services.github_app.private_key' => null]);

    // The branch the queued import brings in, after checking the chain is sandbox → import → agent.
    $this->importedBranch = function (): string {
        Queue::assertPushedWithChain(CreateSandbox::class, [ImportRepository::class, RunAgentTask::class, UpdateProjectIcon::class]);

        return unserialize(Queue::pushed(CreateSandbox::class)->sole()->chained[0])->branch;
    };

    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->actingAs($this->user);
    $this->store = fn (array $data) => $this->post(route('projects.store', $this->user->currentOrganization()), $data);

    // A repository anyone can read, with one commit on main.
    $this->root = sys_get_temp_dir().'/onedrop-import-'.uniqid();
    $this->remote = "{$this->root}/team-timer.git";
    File::ensureDirectoryExists("{$this->root}/work");
    Process::path("{$this->root}/work")->run('git init -q -b main && echo hi > a.txt && git add a.txt && git -c user.name=Me -c user.email=me@example.com commit -q -m One')->throw();
    Process::path($this->root)->run(['git', 'clone', '-q', '--bare', "{$this->root}/work", $this->remote])->throw();
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

test('pointing a new project at a public repository imports it, then starts the agent', function () {
    Queue::fake();

    ($this->store)(['prompt' => '', 'repository' => "file://{$this->remote}"])->assertSessionHasNoErrors();

    $project = $this->user->projects()->sole();

    expect($project->name)->toBe('Team Timer')
        ->and($project->git_remote_url)->toBe("file://{$this->remote}")
        ->and($project->github_installation_id)->toBeNull()
        ->and($project->git_sync_status)->toBe(GitSyncStatus::Pulling)
        ->and($project->status)->toBe(ProjectStatus::Working)
        ->and($project->messages()->sole()->content)->toBe('I imported this app from its repository. Get it running in the preview.');

    expect(($this->importedBranch)())->toBe('main');
})->group('PRJ-009');

test('a prompt sent with the repository is what the agent is asked, and a template is ignored', function () {
    Queue::fake();

    ($this->store)(['prompt' => 'add dark mode', 'template' => 'crm', 'repository' => "file://{$this->remote}"]);

    $project = $this->user->projects()->sole();

    expect($project->name)->toBe('Team Timer')
        ->and($project->messages()->sole()->content)->toBe('add dark mode');
})->group('PRJ-009');

test('a repository that can\'t be reached, or has no commits, is refused before a project is made', function () {
    Process::path($this->root)->run(['git', 'init', '-q', '--bare', "{$this->root}/empty.git"])->throw();

    ($this->store)(['repository' => "file://{$this->root}/missing.git"])
        ->assertSessionHasErrors(['repository' => 'Couldn\'t reach that repository. Only public repositories can be imported without GitHub connected.']);
    ($this->store)(['repository' => "file://{$this->root}/empty.git"])
        ->assertSessionHasErrors(['repository' => 'That repository has no commits yet.']);
    ($this->store)(['repository' => 'http://example.com/repo.git'])->assertSessionHasErrors('repository');

    expect(Project::count())->toBe(0);
})->group('PRJ-009');

test('a private GitHub repository is imported through the user\'s GitHub App installation, on the linked branch', function () {
    Queue::fake();
    Process::fake();
    Http::preventStrayRequests();
    config(['services.github_app' => ['id' => '1', 'slug' => 'onedrop-test', 'client_id' => 'Iv1.client', 'client_secret' => 'secret', 'private_key' => 'key']]);
    GitHubInstallation::factory()->for($this->user)->create(['installation_id' => 777, 'account_login' => 'Dev', 'account_type' => 'User']);
    GitHubAuthorization::factory()->for($this->user)->create(['access_token' => 'ghu_user']);
    Http::fake(['api.github.com/repos/dev/timer' => Http::response([
        'full_name' => 'dev/timer', 'name' => 'timer', 'private' => true, 'default_branch' => 'trunk', 'size' => 10,
        'clone_url' => 'https://github.com/dev/timer.git', 'html_url' => 'https://github.com/dev/timer',
    ])]);

    ($this->store)(['repository' => 'https://github.com/dev/timer/tree/redesign'])->assertSessionHasNoErrors();

    $project = $this->user->projects()->sole();

    expect($project->name)->toBe('Timer')
        ->and($project->git_remote_url)->toBe('https://github.com/dev/timer.git')
        ->and($project->github_installation_id)->toBe(777)
        ->and($project->git_remote_token)->toBeNull();

    expect(($this->importedBranch)())->toBe('redesign');
    // GitHub was asked as the user; git never went to the remote without a token.
    Process::assertNothingRan();
})->group('PRJ-009');

test('when the import fails, the chat says why and the agent never starts', function () {
    // The fake sandbox has no git tool, so the import fails once the sandbox is up.
    ($this->store)(['repository' => "file://{$this->remote}"]);

    $project = $this->user->projects()->sole();
    $messages = $project->messages()->orderBy('id')->get();

    expect($messages->pluck('role')->all())->toBe([MessageRole::User, MessageRole::Assistant])
        ->and($messages->last()->content)->toStartWith('Couldn\'t import the repository: ')
        ->and($project->status)->toBe(ProjectStatus::Idle)
        ->and($project->git_sync_status)->toBe(GitSyncStatus::Failed);
})->group('PRJ-009');

test('a successful import records the sync', function () {
    $project = Project::factory()->for($this->user)->create(['git_remote_url' => "file://{$this->remote}", 'git_sync_status' => GitSyncStatus::Pulling]);
    $message = $project->messages()->create(['role' => MessageRole::User, 'content' => 'go']);
    $this->mock(GitRemote::class)->shouldReceive('pullStep')->once()->withArgs(fn (Project $pulled, string $branch) => $pulled->is($project) && $branch === 'main')->andReturnTrue();

    ImportRepository::dispatchSync($project, $message, 'main');

    expect($project->fresh()->git_sync_status)->toBeNull()
        ->and($project->fresh()->git_synced_at)->not->toBeNull();
})->group('PRJ-009');
