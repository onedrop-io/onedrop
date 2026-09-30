<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/onedrop-skills-tool-'.uniqid();
    $this->workspace = "{$this->root}/workspace";
    $this->home = "{$this->root}/home";
    File::ensureDirectoryExists($this->workspace);
    File::ensureDirectoryExists($this->home);

    $this->tool = function (array $request) {
        $result = Process::env([
            'APP_WORKSPACE' => $this->workspace,
            'APP_SKILLS_HOME' => $this->home,
            'APP_SKILLS_REQUEST' => json_encode($request),
        ])->run(['php', dirname(__DIR__, 2).'/docker/sandbox/skills.php']);

        return json_decode($result->output(), true);
    };
    $this->skill = function (string $dir, string $name, array $files = []) {
        File::ensureDirectoryExists("{$dir}/{$name}");
        File::put("{$dir}/{$name}/SKILL.md", "---\nname: {$name}\ndescription: The {$name} skill.\n---\n\nDo {$name}.\n");

        foreach ($files as $path => $content) {
            File::ensureDirectoryExists(dirname("{$dir}/{$name}/{$path}"));
            File::put("{$dir}/{$name}/{$path}", $content);
        }
    };
    // Uploads the bundle the way SandboxSkills does, then syncs.
    $this->syncWith = function (string $agent, array $skills) {
        $bundle = json_encode(['skills' => $skills]);
        $path = "{$this->root}/bundle.b64";
        File::put($path, base64_encode($bundle));

        return ($this->tool)(['op' => 'sync', 'agent' => $agent, 'hash' => sha1($bundle), 'bundle' => $path]);
    };
    $this->installed = fn (string $dir) => collect(File::directories("{$this->home}/{$dir}"))->map(fn ($path) => basename($path))->sort()->values()->all();
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

test('it lists the project skills from every folder, first folder winning a name', function () {
    ($this->skill)("{$this->workspace}/.agents/skills", 'deploy');
    ($this->skill)("{$this->workspace}/.claude/skills", 'deploy');
    ($this->skill)("{$this->workspace}/.claude/skills", 'review');
    ($this->skill)("{$this->workspace}/.opencode/skills", 'lint');
    File::ensureDirectoryExists("{$this->workspace}/.agents/skills/no-skill-md");

    $skills = ($this->tool)(['op' => 'list'])['data']['skills'];

    expect(collect($skills)->pluck('path')->all())->toBe(['.agents/skills/deploy', '.claude/skills/review', '.opencode/skills/lint'])
        ->and($skills[0]['head'])->toContain('description: The deploy skill.');
})->group('SKILL-003');

test('it exports a project skill without symlinks, and refuses paths that aren\'t skills', function () {
    ($this->skill)("{$this->workspace}/.agents/skills", 'deploy', ['scripts/go.sh' => 'echo go']);
    File::put("{$this->home}/.onedrop-env", 'SECRET=1');
    symlink("{$this->home}/.onedrop-env", "{$this->workspace}/.agents/skills/deploy/env");

    $files = collect(($this->tool)(['op' => 'export', 'path' => '.agents/skills/deploy'])['data']['files'])->pluck('path')->all();

    expect($files)->toBe(['SKILL.md', 'scripts/go.sh'])
        ->and(($this->tool)(['op' => 'export', 'path' => '../home']))->toBe(['ok' => false, 'error' => "That skill isn't in the project anymore."]);
})->group('SKILL-003');

test('a changed hash asks for the bundle, and the bundle goes where Claude Code looks', function () {
    expect(($this->tool)(['op' => 'sync', 'agent' => 'claude_code', 'hash' => 'new'])['data'])->toBe(['current' => false]);

    $result = ($this->syncWith)('claude_code', [
        ['name' => 'notes', 'content' => "---\nname: notes\n---\n", 'files' => [['path' => 'ref/a.md', 'data' => base64_encode('A')], ['path' => '../escape', 'data' => '']]],
    ]);

    expect($result['data'])->toBe(['current' => true, 'installed' => ['notes']])
        ->and(File::get("{$this->home}/.claude/skills/notes/ref/a.md"))->toBe('A')
        ->and(File::exists("{$this->home}/.claude/skills/escape"))->toBeFalse()
        ->and(File::exists("{$this->home}/.agents/skills/notes"))->toBeFalse();
})->group('SKILL-004');

test('Claude Code and Codex get the project skills from the folders they don\'t read, and OpenCode needs none', function () {
    ($this->skill)("{$this->workspace}/.agents/skills", 'deploy');
    ($this->skill)("{$this->workspace}/.claude/skills", 'review');

    ($this->syncWith)('claude_code', []);
    expect(($this->installed)('.claude/skills'))->toBe(['deploy']);

    ($this->syncWith)('codex', []);
    expect(($this->installed)('.agents/skills'))->toBe(['review'])
        ->and(($this->installed)('.claude/skills'))->toBe([]);

    ($this->syncWith)('opencode', []);
    expect(($this->installed)('.agents/skills'))->toBe([]);
})->group('SKILL-004');

test('a project skill wins over a library one, turned-off skills go, and hand-made skills stay', function () {
    ($this->skill)("{$this->workspace}/.claude/skills", 'review');
    ($this->skill)("{$this->home}/.claude/skills", 'mine');
    $library = fn (string $name) => ['name' => $name, 'content' => "---\nname: {$name}\n---\nlibrary\n", 'files' => []];

    $result = ($this->syncWith)('claude_code', [$library('review'), $library('notes'), $library('mine')]);

    expect($result['data']['installed'])->toBe(['notes'])
        ->and(File::get("{$this->home}/.claude/skills/mine/SKILL.md"))->toContain('Do mine.');

    ($this->syncWith)('claude_code', []);

    expect(($this->installed)('.claude/skills'))->toBe(['mine']);
})->group('SKILL-004');

test('an unchanged hash reuses the library and still follows the project\'s skills', function () {
    $bundle = ($this->syncWith)('codex', [['name' => 'notes', 'content' => 'x', 'files' => []]]);
    $hash = sha1(json_encode(['skills' => [['name' => 'notes', 'content' => 'x', 'files' => []]]]));
    ($this->skill)("{$this->workspace}/.claude/skills", 'review');

    $again = ($this->tool)(['op' => 'sync', 'agent' => 'codex', 'hash' => $hash]);

    expect($bundle['data']['current'])->toBeTrue()
        ->and($again['data'])->toBe(['current' => true, 'installed' => ['review', 'notes']]);
})->group('SKILL-004');
