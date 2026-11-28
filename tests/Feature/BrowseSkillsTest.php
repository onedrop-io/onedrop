<?php

use App\Enums\SkillSource;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Skill;
use App\Models\User;
use App\Sandbox\SkillDocument;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->project = Project::factory()->for($this->user)->create();

    $this->fakeDirectory = fn (array $extra = []) => Http::fake([
        ...$extra,
        'skills.sh/api/search*' => Http::response(['skills' => [
            ['id' => 'anthropics/skills/pdf', 'source' => 'anthropics/skills', 'skillId' => 'pdf', 'name' => 'pdf', 'installs' => 239605],
            ['id' => 'docs.stripe.com/stripe-docs', 'source' => 'docs.stripe.com', 'skillId' => 'stripe-docs', 'name' => 'stripe-docs', 'installs' => 100],
            ['id' => 'acme/tools/../x', 'source' => 'acme/tools', 'skillId' => '..', 'name' => 'bad', 'installs' => 5],
            ['id' => 'acme/tools/release-notes', 'source' => 'acme/tools', 'skillId' => 'release-notes', 'name' => 'release-notes', 'installs' => 12],
        ]]),
        'skills.sh/api/download/anthropics/skills/pdf' => Http::response(['files' => [
            ['path' => 'SKILL.md', 'contents' => SkillDocument::compose('pdf', 'Fills PDFs.', '# Fill a PDF')],
            ['path' => 'scripts/fill.py', 'contents' => 'print(1)'],
        ]]),
    ]);
});

test('searching skills.sh lists GitHub skills and says which ones the user already has', function () {
    ($this->fakeDirectory)();
    Skill::factory()->for($this->user)->create(['name' => 'release-notes', 'organization_id' => $this->project->organization_id]);

    $response = $this->actingAs($this->user)
        ->getJson(route('projects.skills.browse', [$this->project, 'q' => 'pdf']))
        ->assertOk();

    expect($response->json('skills'))->toBe([
        ['id' => 'anthropics/skills/pdf', 'name' => 'pdf', 'source' => 'anthropics/skills', 'installs' => 239605, 'url' => 'https://skills.sh/anthropics/skills/pdf', 'added' => false],
        ['id' => 'acme/tools/release-notes', 'name' => 'release-notes', 'source' => 'acme/tools', 'installs' => 12, 'url' => 'https://skills.sh/acme/tools/release-notes', 'added' => true],
    ]);
    Http::assertSent(fn ($request) => $request->url() === 'https://skills.sh/api/search?q=pdf');
})->group('SKILL-005');

test('a one-character search returns nothing without asking skills.sh', function () {
    Http::fake();

    $this->actingAs($this->user)
        ->getJson(route('projects.skills.browse', [$this->project, 'q' => 'p']))
        ->assertOk()
        ->assertExactJson(['skills' => []]);

    Http::assertNothingSent();
})->group('SKILL-005');

test('a skill can be read before it is added', function () {
    ($this->fakeDirectory)();

    $this->actingAs($this->user)
        ->getJson(route('projects.skills.browse.show', [$this->project, 'id' => 'anthropics/skills/pdf']))
        ->assertOk()
        ->assertJsonPath('skill.name', 'pdf')
        ->assertJsonPath('skill.description', 'Fills PDFs.')
        ->assertJsonPath('skill.content', fn (string $content) => str_contains($content, '# Fill a PDF'))
        ->assertJsonPath('skill.files', ['scripts/fill.py'])
        ->assertJsonPath('skill.url', 'https://skills.sh/anthropics/skills/pdf')
        ->assertJsonPath('skill.added', false);
})->group('SKILL-005');

test('adding a skill from skills.sh keeps its files and turns it on in the project', function () {
    ($this->fakeDirectory)();

    $this->actingAs($this->user)
        ->postJson(route('projects.skills.browse.add', $this->project), ['id' => 'anthropics/skills/pdf'])
        ->assertCreated()
        ->assertJsonPath('skills.0.name', 'pdf')
        ->assertJsonPath('skills.0.enabled', true);

    $skill = $this->user->skills()->sole();

    expect($skill)->toMatchArray(['name' => 'pdf', 'description' => 'Fills PDFs.', 'source_url' => 'https://skills.sh/anthropics/skills/pdf'])
        ->and($skill->source)->toBe(SkillSource::SkillsSh)
        ->and($skill->files)->toBe([['path' => 'scripts/fill.py', 'data' => base64_encode('print(1)')]])
        ->and($this->project->skills()->pluck('name')->all())->toBe(['pdf']);
})->group('SKILL-005');

test('adding explains what went wrong', function (array $responses, string $id, string $message) {
    ($this->fakeDirectory)(array_map(fn (array $response) => Http::response(...$response), $responses));

    $this->actingAs($this->user)
        ->postJson(route('projects.skills.browse.add', $this->project), ['id' => $id])
        ->assertUnprocessable()
        ->assertJsonPath('message', fn (string $text) => str_contains($text, $message));

    expect($this->user->skills()->count())->toBe(0);
})->with([
    'not an id' => [[], '../etc/passwd', 'That isn\'t a skill on skills.sh.'],
    'gone' => [['skills.sh/api/download/acme/tools/gone' => [[], 404]], 'acme/tools/gone', 'doesn\'t have that skill anymore'],
    'no SKILL.md' => [['skills.sh/api/download/acme/tools/empty' => [['files' => [['path' => 'README.md', 'contents' => 'hi']]]]], 'acme/tools/empty', 'There\'s no SKILL.md in it'],
    'too big' => [['skills.sh/api/download/acme/tools/huge' => [['files' => [
        ['path' => 'SKILL.md', 'contents' => SkillDocument::compose('huge', 'Big.', 'Body')],
        ['path' => 'blob.txt', 'contents' => str_repeat('x', 1024 * 1024 + 1)],
    ]]]], 'acme/tools/huge', 'The skill is too big'],
    'unsafe path' => [['skills.sh/api/download/acme/tools/sneaky' => [['files' => [
        ['path' => 'SKILL.md', 'contents' => SkillDocument::compose('sneaky', 'Sneaky.', 'Body')],
        ['path' => '../../etc/cron.d/x', 'contents' => 'x'],
    ]]]], 'acme/tools/sneaky', 'path that isn\'t allowed'],
])->group('SKILL-005');

test('adding a skill the user already has says so', function () {
    ($this->fakeDirectory)();
    Skill::factory()->for($this->user)->create(['name' => 'pdf', 'organization_id' => $this->project->organization_id]);

    $this->actingAs($this->user)
        ->postJson(route('projects.skills.browse.add', $this->project), ['id' => 'anthropics/skills/pdf'])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'You already have a skill named "pdf". Rename or delete it first.');
})->group('SKILL-005');

test('search says when skills.sh can\'t be reached', function () {
    Http::fake(['skills.sh/*' => Http::response('oops', 500)]);

    $this->actingAs($this->user)
        ->getJson(route('projects.skills.browse', [$this->project, 'q' => 'pdf']))
        ->assertStatus(502)
        ->assertJsonPath('message', 'skills.sh couldn\'t answer (500). Try again in a moment.');
})->group('SKILL-005');

test('only people who can see the project can browse, and only editors can add', function () {
    ($this->fakeDirectory)();
    $stranger = User::factory()->has(AgentConnection::factory())->create();

    $this->actingAs($stranger)->getJson(route('projects.skills.browse', [$this->project, 'q' => 'pdf']))->assertForbidden();
    $this->actingAs($stranger)->postJson(route('projects.skills.browse.add', $this->project), ['id' => 'anthropics/skills/pdf'])->assertForbidden();
})->group('SKILL-005');
