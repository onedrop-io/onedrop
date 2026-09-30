<?php

use App\Enums\ProjectStatus;
use App\Enums\SandboxStatus;
use App\Enums\SkillSource;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\Skill;
use App\Models\User;
use App\Sandbox\Agents\AgentRunner;
use App\Sandbox\Agents\FakeAgentRunner;
use App\Sandbox\Agents\OpenCodeRunner;
use App\Sandbox\ExecResult;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use App\Sandbox\SandboxSkills;
use App\Sandbox\SkillDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;

beforeEach(function () {
    Queue::fake();
    app()->instance(AgentRunner::class, new FakeAgentRunner);

    // The sandbox runs the real skills.php against a temporary workspace and home.
    $this->root = sys_get_temp_dir().'/onedrop-skills-'.uniqid();
    $this->workspace = "{$this->root}/workspace";
    $this->home = "{$this->root}/home";
    File::ensureDirectoryExists($this->workspace);
    File::ensureDirectoryExists($this->home);

    $this->provider = new FakeSandboxProvider;
    $this->provider->execUsing = function (array $command, array $env) {
        if ($command === ['php', SandboxSkills::SCRIPT]) {
            $process = new Process([PHP_BINARY, base_path('docker/sandbox/skills.php')], env: [
                ...$env, 'APP_WORKSPACE' => $this->workspace, 'APP_SKILLS_HOME' => $this->home,
            ]);
            $process->run();

            return new ExecResult((int) $process->getExitCode(), $process->getOutput(), $process->getErrorOutput());
        }

        // The bundle upload (WorkspaceFiles::writeChunks): printf "$APP_CONTENT" > file.
        if (($command[0] ?? null) === 'sh' && isset($env['APP_CONTENT'])) {
            file_put_contents($command[4], $env['APP_CONTENT'], str_contains($command[2], '>>') ? FILE_APPEND : 0);

            return new ExecResult(0, '');
        }

        return new ExecResult(0, '');
    };
    app()->instance(SandboxProvider::class, $this->provider);

    $this->user = User::factory()->has(AgentConnection::factory())->create(['name' => 'Dev User']);
    $this->other = User::factory()->has(AgentConnection::factory())->create(['name' => 'Sam']);
    $this->project = Project::factory()->for($this->user)->create();
    $this->sandbox = Sandbox::factory()->for($this->project)->create(['external_id' => 'ctr-1']);

    $this->projectSkill = function (string $root, string $name, string $description = 'Does things.') {
        File::ensureDirectoryExists("{$this->workspace}/{$root}/{$name}");
        File::put("{$this->workspace}/{$root}/{$name}/SKILL.md", SkillDocument::compose($name, $description, "Steps for {$name}."));
    };
});

afterEach(fn () => File::deleteDirectory($this->root));

test('the list has the user\'s skills and shared ones, with whether each is on in the project', function () {
    $mine = Skill::factory()->for($this->user)->create(['name' => 'alpha']);
    $shared = Skill::factory()->for($this->other)->shared()->create(['name' => 'beta']);
    Skill::factory()->for($this->other)->create(['name' => 'hidden']);
    $this->project->skills()->attach($mine);

    $response = $this->actingAs($this->user)->getJson(route('projects.skills.index', $this->project))->assertOk();

    expect($response->json('skills'))->toHaveCount(2)
        ->and($response->json('skills.0'))->toMatchArray(['id' => $mine->id, 'name' => 'alpha', 'mine' => true, 'can_edit' => true, 'enabled' => true])
        ->and($response->json('skills.1'))->toMatchArray(['id' => $shared->id, 'owner' => 'Sam', 'mine' => false, 'can_edit' => false, 'enabled' => false]);
})->group('SKILL-001');

test('only the project\'s owner (or an admin) sees and changes its skills', function () {
    $this->actingAs($this->other)->getJson(route('projects.skills.index', $this->project))->assertForbidden();
    $this->actingAs($this->other)->postJson(route('projects.skills.store', $this->project), ['name' => 'x', 'description' => 'x', 'instructions' => 'x'])->assertForbidden();
})->group('SKILL-001');

test('writing a skill adds it to the user\'s skills and turns it on in the project', function () {
    $response = $this->actingAs($this->user)->postJson(route('projects.skills.store', $this->project), [
        'name' => 'release-notes',
        'description' => 'Writes release notes. Use when asked for a changelog.',
        'instructions' => "# Release notes\n\n1. Read the log.",
        'shared' => true,
    ])->assertCreated();

    $skill = $this->user->skills()->sole();

    expect($response->json('skills.0'))->toMatchArray(['name' => 'release-notes', 'enabled' => true, 'shared' => true])
        ->and($skill->source)->toBe(SkillSource::Written)
        ->and(SkillDocument::parse($skill->content))->toBe([
            'fields' => ['name' => 'release-notes', 'description' => 'Writes release notes. Use when asked for a changelog.'],
            'body' => "# Release notes\n\n1. Read the log.\n",
        ]);
})->group('SKILL-002');

test('a skill needs a valid name the user doesn\'t already have', function () {
    Skill::factory()->for($this->user)->create(['name' => 'taken']);

    $add = fn (string $name) => $this->actingAs($this->user)->postJson(route('projects.skills.store', $this->project), [
        'name' => $name, 'description' => 'x', 'instructions' => 'x',
    ]);

    $add('Not Valid')->assertUnprocessable()->assertJsonPath('message', fn (string $message) => str_contains($message, 'isn\'t a valid skill name'));
    $add('taken')->assertUnprocessable()->assertJsonPath('message', 'You already have a skill named "taken". Rename or delete it first.');
})->group('SKILL-002');

test('turning skills on and off, and two skills with one name can\'t both be on', function () {
    $mine = Skill::factory()->for($this->user)->create(['name' => 'deploy']);
    $shared = Skill::factory()->for($this->other)->shared()->create(['name' => 'deploy']);
    $private = Skill::factory()->for($this->other)->create();
    $toggle = fn (Skill $skill, bool $enabled) => $this->actingAs($this->user)
        ->putJson(route('projects.skills.toggle', [$this->project, $skill]), ['enabled' => $enabled]);

    $toggle($mine, true)->assertOk()->assertJsonPath('skills.0.enabled', true);
    $toggle($shared, true)->assertUnprocessable()->assertJsonPath('message', 'A skill named "deploy" is already on in this project. Turn that one off first.');
    $toggle($mine, false)->assertOk();
    $toggle($shared, true)->assertOk();
    $toggle($private, true)->assertForbidden();

    expect($this->project->skills()->pluck('skills.id')->all())->toBe([$shared->id]);
})->group('SKILL-001');

test('editing a skill keeps its other frontmatter, and only its owner or an admin can', function () {
    $skill = Skill::factory()->for($this->user)->create([
        'name' => 'pdf',
        'content' => "---\nname: pdf\ndescription: Old\nlicense: MIT\n---\n\nOld body\n",
    ]);

    $this->actingAs($this->other)->patchJson(route('skills.update', $skill), ['description' => 'Mine now'])->assertForbidden();

    $this->actingAs($this->user)->patchJson(route('skills.update', $skill), [
        'name' => 'pdf-tools', 'description' => 'New', 'instructions' => 'New body',
    ])->assertOk()->assertJsonPath('skill.name', 'pdf-tools');

    expect($skill->fresh()->content)->toBe("---\nname: pdf-tools\ndescription: \"New\"\nlicense: MIT\n---\n\nNew body\n");

    $admin = User::factory()->has(AgentConnection::factory())->create(['is_admin' => true]);
    $this->actingAs($admin)->patchJson(route('skills.update', $skill), ['description' => 'Fixed by admin'])->assertOk();
    expect($skill->fresh()->description)->toBe('Fixed by admin');
})->group('SKILL-001');

test('stopping sharing turns a skill off in other people\'s projects, and deleting turns it off everywhere', function () {
    $skill = Skill::factory()->for($this->other)->shared()->create();
    $theirs = Project::factory()->for($this->other)->create();
    $skill->projects()->attach([$this->project->id, $theirs->id]);

    $this->actingAs($this->other)->patchJson(route('skills.update', $skill), ['shared' => false])->assertOk();

    expect($skill->projects()->pluck('projects.id')->all())->toBe([$theirs->id]);

    $this->actingAs($this->other)->deleteJson(route('skills.destroy', $skill))->assertOk();

    expect(Skill::count())->toBe(0)->and($theirs->skills()->count())->toBe(0);
})->group('SKILL-001');

test('reading a skill shows its instructions and other files to people who can see it', function () {
    $skill = Skill::factory()->for($this->other)->shared()->create([
        'content' => SkillDocument::compose('ref', 'Has files.', 'Read ref.md.'),
        'files' => [['path' => 'ref.md', 'data' => base64_encode('ref')]],
    ]);
    $private = Skill::factory()->for($this->other)->create();

    $this->actingAs($this->user)->getJson(route('skills.show', $skill))
        ->assertOk()
        ->assertJsonPath('skill.content', "Read ref.md.\n")
        ->assertJsonPath('skill.files', ['ref.md']);
    $this->actingAs($this->user)->getJson(route('skills.show', $private))->assertForbidden();
})->group('SKILL-001');

test('importing from a GitHub folder link brings in the skill and its files', function () {
    Http::fake([
        'api.github.com/repos/acme/skills/git/trees/main*' => Http::response(['tree' => [
            ['path' => 'skills/pdf/SKILL.md', 'type' => 'blob', 'size' => 60],
            ['path' => 'skills/pdf/scripts/fill.py', 'type' => 'blob', 'size' => 10],
            ['path' => 'skills/other/SKILL.md', 'type' => 'blob', 'size' => 60],
            ['path' => 'skills/pdf', 'type' => 'tree'],
        ]]),
        'api.github.com/repos/acme/skills/contents/skills/pdf/SKILL.md*' => Http::response(SkillDocument::compose('pdf', 'Fills PDFs.', 'Run the script.')),
        'api.github.com/repos/acme/skills/contents/skills/pdf/scripts/fill.py*' => Http::response('print(1)'),
    ]);

    $this->actingAs($this->user)->postJson(route('projects.skills.import', $this->project), [
        'url' => 'https://github.com/acme/skills/tree/main/skills/pdf',
    ])->assertCreated()->assertJsonPath('skills.0.enabled', true);

    $skill = $this->user->skills()->sole();

    expect($skill)->toMatchArray(['name' => 'pdf', 'description' => 'Fills PDFs.', 'source_url' => 'https://github.com/acme/skills/tree/main/skills/pdf'])
        ->and($skill->source)->toBe(SkillSource::GitHub)
        ->and($skill->files)->toBe([['path' => 'scripts/fill.py', 'data' => base64_encode('print(1)')]]);
})->group('SKILL-002');

test('importing explains links that aren\'t one skill', function (string $url, string $message) {
    Http::fake([
        'api.github.com/repos/acme/skills' => Http::response(['default_branch' => 'main']),
        'api.github.com/repos/acme/skills/git/trees/main*' => Http::response(['tree' => [
            ['path' => 'skills/a/SKILL.md', 'type' => 'blob', 'size' => 10],
            ['path' => 'skills/b/SKILL.md', 'type' => 'blob', 'size' => 10],
        ]]),
        'api.github.com/repos/acme/missing*' => Http::response([], 404),
    ]);

    $this->actingAs($this->user)->postJson(route('projects.skills.import', $this->project), ['url' => $url])
        ->assertUnprocessable()
        ->assertJsonPath('message', fn (string $text) => str_contains($text, $message));
})->with([
    'several skills' => ['https://github.com/acme/skills', 'That has 2 skills (a, b). Pick one of them.'],
    'not GitHub' => ['https://gitlab.com/acme/skills', 'That isn\'t a GitHub link.'],
    'missing' => ['https://github.com/acme/missing', 'There\'s nothing at that link.'],
])->group('SKILL-002');

test('uploading a SKILL.md or a zip adds the skill', function () {
    $this->actingAs($this->user)->postJson(route('projects.skills.upload', $this->project), [
        'file' => UploadedFile::fake()->createWithContent('SKILL.md', SkillDocument::compose('single', 'One file.', 'Body')),
    ])->assertCreated();

    $zipPath = "{$this->root}/skill.zip";
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFromString('zipped/SKILL.md', SkillDocument::compose('zipped', 'From a zip.', 'Body'));
    $zip->addFromString('zipped/templates/a.txt', 'A');
    $zip->addFromString('__MACOSX/zipped/._a.txt', 'junk');
    $zip->close();

    $this->actingAs($this->user)->postJson(route('projects.skills.upload', $this->project), [
        'file' => new UploadedFile($zipPath, 'skill.zip', 'application/zip', test: true),
    ])->assertCreated();

    expect($this->user->skills()->where('name', 'zipped')->sole()->files)->toBe([['path' => 'templates/a.txt', 'data' => base64_encode('A')]])
        ->and($this->user->skills()->where('name', 'single')->sole()->source)->toBe(SkillSource::Upload);

    $this->actingAs($this->user)->postJson(route('projects.skills.upload', $this->project), [
        'file' => UploadedFile::fake()->createWithContent('notes.md', '# No frontmatter'),
    ])->assertUnprocessable()->assertJsonPath('message', 'The skill needs a name.');
})->group('SKILL-002');

test('creating a skill with the agent asks it in the chat, following the guide', function () {
    $this->project->update(['status' => ProjectStatus::Working]);

    $this->actingAs($this->user)->postJson(route('projects.skills.create', $this->project), ['description' => 'How we name migrations.'])
        ->assertOk()
        ->assertJsonPath('queued', true);

    expect($this->project->queuedMessages()->value('content'))
        ->toBe('Create an agent skill for this project: How we name migrations. Follow the guide at /opt/onedrop/guides/skills.md.');
})->group('SKILL-002');

test('project skills are listed, readable and can be saved to the user\'s skills', function () {
    ($this->projectSkill)('.agents/skills', 'deploy', 'Deploys the app.');
    ($this->projectSkill)('.claude/skills', 'review');

    $this->actingAs($this->user)->getJson(route('projects.project-skills.index', $this->project))
        ->assertOk()
        ->assertExactJson(['skills' => [
            ['name' => 'deploy', 'description' => 'Deploys the app.', 'path' => '.agents/skills/deploy'],
            ['name' => 'review', 'description' => 'Does things.', 'path' => '.claude/skills/review'],
        ]]);

    $this->actingAs($this->user)->getJson(route('projects.project-skills.show', [$this->project, 'path' => '.agents/skills/deploy']))
        ->assertOk()
        ->assertJsonPath('skill.content', "Steps for deploy.\n");

    $this->actingAs($this->user)->postJson(route('projects.project-skills.save', $this->project), ['path' => '.agents/skills/deploy'])->assertOk();
    $this->actingAs($this->user)->postJson(route('projects.project-skills.save', $this->project), ['path' => '.agents/skills/deploy'])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'You already have a skill named "deploy". Rename or delete it first.');

    $saved = $this->user->skills()->sole();

    expect($saved->source)->toBe(SkillSource::Project)
        ->and($this->project->skills()->count())->toBe(0);
})->group('SKILL-003');

test('project skills need the sandbox running', function () {
    $this->sandbox->update(['status' => SandboxStatus::Paused]);

    $this->actingAs($this->user)->getJson(route('projects.project-skills.index', $this->project))
        ->assertStatus(409)
        ->assertJsonPath('message', "The project's sandbox isn't running.");
})->group('SKILL-003');

test('before a run the skills that are on are put where the agent looks, uploaded only when they changed', function () {
    $skill = Skill::factory()->for($this->user)->create([
        'name' => 'notes',
        'files' => [['path' => 'ref.md', 'data' => base64_encode('ref')]],
    ]);
    $this->project->skills()->attach($skill);
    $skills = app(SandboxSkills::class);

    $skills->install($this->sandbox, $this->project, 'claude_code');
    $uploads = collect($this->provider->executed)->filter(fn (array $call) => isset($call['env']['APP_CONTENT']))->count();

    expect(File::get("{$this->home}/.claude/skills/notes/SKILL.md"))->toBe($skill->content)
        ->and(File::get("{$this->home}/.claude/skills/notes/ref.md"))->toBe('ref')
        ->and($uploads)->toBe(1);

    $this->provider->executed = [];
    $skills->install($this->sandbox, $this->project, 'claude_code');

    expect($this->provider->executed)->toHaveCount(1);

    $this->project->skills()->detach($skill);
    $skills->install($this->sandbox, $this->project, 'claude_code');

    expect(File::exists("{$this->home}/.claude/skills/notes"))->toBeFalse();
})->group('SKILL-004');

test('a sandbox without the skills tool still runs the agent', function () {
    $this->provider->execUsing = fn (array $command) => new ExecResult($command[0] === 'php' ? 1 : 0, 'Could not open input file');
    $message = $this->project->messages()->create(['role' => 'user', 'content' => 'hi']);

    app(OpenCodeRunner::class)->start($this->project, $message);

    expect(collect($this->provider->executed)->last()['command'])->toBe(['node', '/opt/onedrop/forwarder.mjs']);
})->group('SKILL-004');
