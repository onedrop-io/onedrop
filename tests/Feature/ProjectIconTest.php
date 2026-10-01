<?php

use App\Actions\DeleteProject;
use App\Enums\ProjectStatus;
use App\Enums\SandboxStatus;
use App\Jobs\UpdateProjectIcon;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\ExecResult;
use App\Sandbox\ProjectIcons;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\Publishing\FakePublisher;
use App\Sandbox\Publishing\Publisher;
use App\Sandbox\SandboxProvider;
use App\Sandbox\WorkspaceFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

const DRAWN_ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" rx="14" fill="#2563eb"/><path d="M20 32h24" stroke="#fff" onclick="alert(1)"/><script>alert(1)</script></svg>';

/**
 * A sandbox whose app has the given favicons, that answers AI prompts with $answer, and remembers written files.
 */
function iconSandbox(array $appFiles = [], string $answer = DRAWN_ICON, bool $hasPublic = true): FakeSandboxProvider
{
    $provider = new class extends FakeSandboxProvider
    {
        /** @var array<string, string> Files uploaded into the workspace, by path. */
        public array $written = [];
    };
    $temp = [];
    $provider->execUsing = function (array $command, array $env) use ($appFiles, $answer, $hasPublic, $provider, &$temp) {
        $script = $command[2] ?? '';

        return match (true) {
            $command[0] === 'test' => new ExecResult($hasPublic ? 0 : 1, ''),
            str_contains($script, 'ls -t') => new ExecResult(0, collect($appFiles)
                ->map(fn (string $bytes, string $path) => $path."\t".base64_encode($bytes))
                ->implode("\n")."\n"),
            str_contains($script, 'opencode run') => new ExecResult(0, json_encode(['type' => 'text', 'part' => ['text' => "Here you go:\n{$answer}"]])),
            str_contains($script, 'claude -p') => new ExecResult(0, json_encode(['type' => 'result', 'is_error' => false, 'result' => "Here you go:\n{$answer}"])),
            str_contains($script, 'printf %s "$APP_CONTENT"') => (function () use ($command, $env, &$temp) {
                $temp[$command[4]] = (str_contains($command[2], '>>') ? ($temp[$command[4]] ?? '') : '').$env['APP_CONTENT'];

                return new ExecResult(0, '');
            })(),
            str_contains($script, 'base64 -d') => (function () use ($command, $provider, &$temp) {
                $provider->written[str_replace(WorkspaceFiles::ROOT.'/', '', $command[5])] = base64_decode($temp[$command[4]]);

                return new ExecResult(0, '');
            })(),
            default => new ExecResult(0, ''),
        };
    };
    app()->instance(SandboxProvider::class, $provider);

    return $provider;
}

/**
 * The call that points the app's logo component at its icon, if one was made.
 *
 * @return array{id: string, command: list<string>, env: array<string, string>}|null
 */
function logoCall(FakeSandboxProvider $provider): ?array
{
    return collect($provider->executed)->firstWhere(fn ($call) => str_contains($call['command'][2] ?? '', 'app-logo-icon.tsx'));
}

beforeEach(function () {
    Storage::fake(ProjectIcons::disk());
    app()->instance(Publisher::class, new FakePublisher);
    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->project = Project::factory()->for($this->user)->create(['name' => 'Team CRM', 'prompt' => 'A CRM for our sales team']);
    $this->sandbox = Sandbox::factory()->for($this->project)->create(['external_id' => 'sbx-1', 'status' => SandboxStatus::Running]);
});

test('finishing an agent run picks up the app\'s icon in the background', function () {
    Queue::fake();
    $this->project->update(['status' => ProjectStatus::Working]);

    $this->withToken($this->sandbox->issueEventsToken())
        ->postJson(route('sandbox-events.store', $this->sandbox), ['events' => [['type' => 'onedrop.exit', 'code' => 0, 'stderr' => '']]])
        ->assertOk();

    Queue::assertPushed(UpdateProjectIcon::class, fn (UpdateProjectIcon $job) => $job->project->is($this->project) && ! $job->redraw);
    expect(ProjectIcons::drawing([$this->project->id]))->toBe([$this->project->id]);
})->group('PRJ-007');

test('the app\'s own favicon becomes the project\'s icon', function () {
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    $provider = iconSandbox(['public/favicon.png' => $png]);

    UpdateProjectIcon::dispatchSync($this->project);

    $this->project->refresh();
    expect($this->project->icon_mime)->toBe('image/png')
        ->and(Storage::disk(ProjectIcons::disk())->get($this->project->icon_path))->toBe($png)
        ->and($provider->written)->toBe([])
        ->and(collect($provider->executed)->pluck('command')->flatten()->contains(fn ($part) => str_contains((string) $part, 'opencode run')))->toBeFalse()
        ->and(logoCall($provider)['env']['APP_CONTENT'])->toContain('src="/favicon.png?v='.substr($this->project->icon_hash, 0, 12).'"');

    $sidebar = $this->actingAs($this->user)->followingRedirects()->get(route('dashboard'))->inertiaProps('sidebarProjects');
    expect($sidebar['recent'][0]['icon_url'])->toBe(ProjectIcons::url($this->project));

    $this->get($sidebar['recent'][0]['icon_url'])
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy');
})->group('PRJ-007');

test('a cloned repository\'s favicon outside the usual places becomes the project\'s icon', function () {
    $provider = iconSandbox([
        'docs/static/img/favicon.svg' => '<svg xmlns="http://www.w3.org/2000/svg" id="docs"/>',
        'apps/web/favicon.png' => 'png',
        'apps/web/favicon.svg' => '<svg xmlns="http://www.w3.org/2000/svg" id="web"/>',
    ]);

    UpdateProjectIcon::dispatchSync($this->project);

    $this->project->refresh();
    expect(Storage::disk(ProjectIcons::disk())->get($this->project->icon_path))->toContain('id="web"')
        ->and($provider->written)->toBe([])
        ->and(collect($provider->executed)->contains(fn ($call) => str_contains($call['command'][2] ?? '', 'opencode run')))->toBeFalse()
        ->and(logoCall($provider))->toBeNull();
})->group('PRJ-007');

test('a favicon in the usual places wins over one elsewhere in the app', function () {
    iconSandbox([
        'src/favicon.svg' => '<svg xmlns="http://www.w3.org/2000/svg" id="src"/>',
        'public/favicon.ico' => 'ico',
    ]);

    UpdateProjectIcon::dispatchSync($this->project);

    expect($this->project->refresh()->icon_mime)->toBe('image/x-icon');
})->group('PRJ-007');

test('an unchanged favicon is not stored again', function () {
    iconSandbox(['public/favicon.svg' => '<svg xmlns="http://www.w3.org/2000/svg"/>']);
    UpdateProjectIcon::dispatchSync($this->project);
    $path = $this->project->refresh()->icon_path;

    UpdateProjectIcon::dispatchSync($this->project);

    expect($this->project->refresh()->icon_path)->toBe($path);
})->group('PRJ-007');

test('an unchanged favicon whose stored copy went missing is stored again', function () {
    iconSandbox(['public/favicon.svg' => '<svg xmlns="http://www.w3.org/2000/svg"/>']);
    UpdateProjectIcon::dispatchSync($this->project);
    Storage::disk(ProjectIcons::disk())->delete($this->project->refresh()->icon_path);

    UpdateProjectIcon::dispatchSync($this->project);

    Storage::disk(ProjectIcons::disk())->assertExists($this->project->refresh()->icon_path);
})->group('PRJ-007');

test('an app with only the starter kit\'s logo gets an icon drawn by its AI, cleaned and installed', function () {
    $starterLogo = file_get_contents(base_path('tests/Fixtures/starter-kit-favicon.svg'));
    $provider = iconSandbox(['public/favicon.svg' => $starterLogo, 'public/favicon.ico' => '']);
    ProjectIcons::markDrawing($this->project);

    UpdateProjectIcon::dispatchSync($this->project);

    $this->project->refresh();
    $installed = $provider->written['public/favicon.svg'];
    $prompt = collect($provider->executed)->firstWhere(fn ($call) => str_contains($call['command'][2] ?? '', 'opencode run'))['env']['APP_PROMPT'];

    expect($installed)->toContain('<rect')->toContain('rx="14"')
        ->not->toContain('script')->not->toContain('onclick')
        ->and(Storage::disk(ProjectIcons::disk())->get($this->project->icon_path))->toBe($installed)
        ->and($this->project->icon_mime)->toBe('image/svg+xml')
        ->and($prompt)->toContain('Team CRM')->toContain('A CRM for our sales team')
        ->and(ProjectIcons::drawing([$this->project->id]))->toBe([])
        // The starter kit's other icons are taken out so browsers don't pick them instead.
        ->and(collect($provider->executed)->contains(fn ($call) => str_contains($call['command'][2] ?? '', 'apple-touch-icon.png')))->toBeTrue()
        // And its logo shows the new icon.
        ->and(logoCall($provider)['env']['APP_CONTENT'])->toContain('src="/favicon.svg?v='.substr($this->project->icon_hash, 0, 12).'"');
})->group('PRJ-007');

test('the icon replaces the starter kit\'s Laravel logo, but not a logo the agent drew', function () {
    $provider = iconSandbox();
    UpdateProjectIcon::dispatchSync($this->project);
    $call = logoCall($provider);
    $root = storage_path('framework/testing/logo-'.uniqid());
    $component = "{$root}/resources/js/components/app-logo-icon.tsx";
    $runLogoScript = fn () => Process::env($call['env'])->run([...array_slice($call['command'], 0, -1), $root])->throw();
    File::ensureDirectoryExists(dirname($component));

    try {
        File::copy(base_path('tests/Fixtures/starter-kit-app-logo-icon.tsx'), $component);
        $runLogoScript();
        expect(File::get($component))->toBe($call['env']['APP_CONTENT']);

        // A later icon updates the logo OneDrop wrote.
        File::put($component, str_replace('?v=', '?v=old', $call['env']['APP_CONTENT']));
        $runLogoScript();
        expect(File::get($component))->toBe($call['env']['APP_CONTENT']);

        $ownLogo = 'export default function AppLogoIcon() { return <svg viewBox="0 0 10 10"><circle r="5" /></svg>; }';
        File::put($component, $ownLogo);
        $runLogoScript();
        expect(File::get($component))->toBe($ownLogo);
    } finally {
        File::deleteDirectory($root);
    }
})->group('PRJ-007');

test('a project on a Claude subscription gets its icon drawn through Claude Code', function () {
    $user = User::factory()->has(AgentConnection::factory()->claudeLogin())->create();
    $project = Project::factory()->for($user)->create([
        'agent_harness' => 'claude_code', 'agent_provider' => 'claude', 'agent_model' => 'claude-sonnet-5',
    ]);
    Sandbox::factory()->for($project)->create(['external_id' => 'sbx-2', 'status' => SandboxStatus::Running]);
    $provider = iconSandbox();

    UpdateProjectIcon::dispatchSync($project);

    $call = collect($provider->executed)->firstWhere(fn ($call) => str_contains($call['command'][2] ?? '', 'claude -p'));
    expect($project->refresh()->icon_mime)->toBe('image/svg+xml')
        ->and($provider->written['public/favicon.svg'])->toContain('rx="14"')
        ->and($call['command'][2])->toContain('--tools ""')->toContain('-u ANTHROPIC_API_KEY')
        ->and($call['env'])->toMatchArray(['APP_MODEL' => 'claude-sonnet-5'])
        ->and($call['env']['APP_PROMPT'])->toContain('Design a favicon');
})->group('PRJ-007');

test('a drawn icon is put back when the app loses its favicon, without asking the AI again', function () {
    iconSandbox();
    UpdateProjectIcon::dispatchSync($this->project);
    $icon = Storage::disk(ProjectIcons::disk())->get($this->project->refresh()->icon_path);

    $provider = iconSandbox();
    UpdateProjectIcon::dispatchSync($this->project->refresh());

    expect($provider->written['public/favicon.svg'])->toBe($icon)
        ->and(collect($provider->executed)->contains(fn ($call) => str_contains($call['command'][2] ?? '', 'opencode run')))->toBeFalse();
})->group('PRJ-007');

test('the project keeps its initial when the AI can\'t draw an icon', function (string $answer, SandboxStatus $status) {
    iconSandbox(answer: $answer);
    $this->sandbox->update(['status' => $status]);
    ProjectIcons::markDrawing($this->project);

    UpdateProjectIcon::dispatchSync($this->project);

    expect($this->project->refresh()->icon_path)->toBeNull()
        ->and(ProjectIcons::drawing([$this->project->id]))->toBe([]);
})->with([
    'not an svg' => ['I can\'t draw that.', SandboxStatus::Running],
    'sandbox paused' => [DRAWN_ICON, SandboxStatus::Paused],
])->group('PRJ-007');

test('uploading an image installs it in the app and shows it in the sidebar', function () {
    $provider = iconSandbox();

    $this->actingAs($this->user)
        ->post(route('projects.icon.update', $this->project), ['icon' => UploadedFile::fake()->image('logo.png', 32, 32)], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonStructure(['icon_url']);

    $installed = $provider->written['public/favicon.svg'];
    expect($installed)->toContain('<image href="data:image/png;base64,')->toContain('viewBox="0 0 32 32"')
        ->and(Storage::disk(ProjectIcons::disk())->get($this->project->refresh()->icon_path))->toBe($installed);
})->group('PRJ-007');

test('uploaded SVGs are cleaned before they\'re installed', function () {
    $provider = iconSandbox();
    $svg = UploadedFile::fake()->createWithContent('icon.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><circle r="4"/><foreignObject><div>x</div></foreignObject></svg>');

    $this->actingAs($this->user)
        ->post(route('projects.icon.update', $this->project), ['icon' => $svg], ['Accept' => 'application/json'])
        ->assertOk();

    expect($provider->written['public/favicon.svg'])->toContain('<circle')->not->toContain('onload')->not->toContain('foreignObject');
})->group('PRJ-007');

test('only images can be uploaded as the icon', function () {
    iconSandbox();

    $this->actingAs($this->user)
        ->post(route('projects.icon.update', $this->project), ['icon' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
        ->assertUnprocessable();

    expect($this->project->refresh()->icon_path)->toBeNull();
})->group('PRJ-007');

test('asking for a new icon draws one in the background', function () {
    Queue::fake();

    $this->actingAs($this->user)
        ->postJson(route('projects.icon.draw', $this->project))
        ->assertAccepted();

    Queue::assertPushed(UpdateProjectIcon::class, fn (UpdateProjectIcon $job) => $job->redraw);

    $this->sandbox->update(['status' => SandboxStatus::Paused]);

    $this->actingAs($this->user)
        ->postJson(route('projects.icon.draw', $this->project))
        ->assertConflict();
})->group('PRJ-007');

test('other users can\'t see or change a project\'s icon', function () {
    iconSandbox(['public/favicon.svg' => '<svg xmlns="http://www.w3.org/2000/svg"/>']);
    UpdateProjectIcon::dispatchSync($this->project);
    Queue::fake();
    $other = User::factory()->has(AgentConnection::factory())->create();

    $this->actingAs($other)->get(ProjectIcons::url($this->project->refresh()))->assertForbidden();
    $this->actingAs($other)->postJson(route('projects.icon.draw', $this->project))->assertForbidden();
    $this->actingAs($other)
        ->post(route('projects.icon.update', $this->project), ['icon' => UploadedFile::fake()->image('logo.png')], ['Accept' => 'application/json'])
        ->assertForbidden();
})->group('PRJ-007');

test('deleting a project deletes its icon', function () {
    iconSandbox(['public/favicon.svg' => '<svg xmlns="http://www.w3.org/2000/svg"/>']);
    UpdateProjectIcon::dispatchSync($this->project);
    $path = $this->project->refresh()->icon_path;
    Queue::fake();

    app(DeleteProject::class)->handle($this->project);

    Storage::disk(ProjectIcons::disk())->assertMissing($path);
})->group('PRJ-007');
