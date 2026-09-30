<?php

use App\Sandbox\SkillDocument;
use App\Sandbox\SkillException;
use App\Sandbox\SkillPackage;
use Tests\TestCase;

uses(TestCase::class);

test('it reads plain, quoted and block frontmatter values and the body', function () {
    $content = <<<'MD'
        ---
        name: pdf-tools
        description: >
          Fills in PDF forms.
          Use when the user has a PDF.
        license: 'Apache 2.0 # not a comment'
        allowed-tools: "Read, Bash(python:*)"
        metadata:
          version: 1
        ---

        # PDF tools

        Steps.
        MD;

    $document = SkillDocument::parse($content);

    expect($document['fields'])->toMatchArray([
        'name' => 'pdf-tools',
        'description' => 'Fills in PDF forms. Use when the user has a PDF.',
        'license' => 'Apache 2.0 # not a comment',
        'allowed-tools' => 'Read, Bash(python:*)',
    ])->and($document['body'])->toBe("# PDF tools\n\nSteps.");
})->group('SKILL-002');

test('a file without frontmatter has no fields', function () {
    expect(SkillDocument::parse("# Just text\n"))->toBe(['fields' => [], 'body' => "# Just text\n"]);
})->group('SKILL-002');

test('composing and editing keep the description exact and other keys as they were', function () {
    $composed = SkillDocument::compose('notes', 'Says "hi": always', 'Body');

    expect(SkillDocument::parse($composed))->toBe(['fields' => ['name' => 'notes', 'description' => 'Says "hi": always'], 'body' => "Body\n"]);

    $original = "---\nname: old\ndescription: |\n  Old one\n  two lines\nlicense: MIT\n---\n\nOld body\n";
    $edited = SkillDocument::withFields($original, 'new-name', 'New one', 'New body');

    expect($edited)->toBe("---\nname: new-name\ndescription: \"New one\"\nlicense: MIT\n---\n\nNew body\n");
})->group('SKILL-001');

test('names and descriptions follow the Agent Skills rules', function (?string $name, ?string $description, bool $valid) {
    expect(SkillDocument::problem($name, $description) === null)->toBe($valid);
})->with([
    ['release-notes', 'Writes notes.', true],
    ['a1', 'x', true],
    ['Release', 'x', false],
    ['-lead', 'x', false],
    ['double--hyphen', 'x', false],
    [str_repeat('a', 65), 'x', false],
    ['ok', '', false],
    ['ok', str_repeat('d', 1025), false],
    [null, 'x', false],
])->group('SKILL-002');

test('a package finds SKILL.md at the root or in its only folder, and keeps the other files', function () {
    $skill = "---\nname: helper\ndescription: Helps.\n---\n\nDo it.\n";

    $root = SkillPackage::fromFiles(['SKILL.md' => $skill, 'scripts/run.sh' => "echo hi\n", '.DS_Store' => 'x']);
    $nested = SkillPackage::fromFiles(['helper/SKILL.md' => $skill, 'helper/ref.md' => 'ref', '__MACOSX/helper/._ref.md' => 'junk']);

    expect($root)->toMatchArray(['name' => 'helper', 'description' => 'Helps.', 'content' => $skill])
        ->and($root['files'])->toBe([['path' => 'scripts/run.sh', 'data' => base64_encode("echo hi\n")]])
        ->and($nested['files'])->toBe([['path' => 'ref.md', 'data' => base64_encode('ref')]]);
})->group('SKILL-002');

test('a package is refused when it has no skill, several, a bad name, or is too big', function (array $files, string $message) {
    expect(fn () => SkillPackage::fromFiles($files))->toThrow(SkillException::class, $message);
})->with([
    'no SKILL.md' => [['README.md' => 'hi'], 'no SKILL.md'],
    'several' => [['a/SKILL.md' => '', 'b/SKILL.md' => ''], 'That has 2 skills (a, b)'],
    'bad name' => [['SKILL.md' => "---\nname: Bad Name\ndescription: x\n---\n"], 'isn\'t a valid skill name'],
    'no description' => [['SKILL.md' => "---\nname: ok\n---\n"], 'needs a description'],
    'too many files' => [['SKILL.md' => "---\nname: ok\ndescription: x\n---\n", ...array_fill_keys(array_map(fn ($i) => "f{$i}.txt", range(1, 50)), 'x')], 'too big'],
    'too many bytes' => [['SKILL.md' => "---\nname: ok\ndescription: x\n---\n", 'big.bin' => str_repeat('x', 1024 * 1024)], 'too big'],
    'unsafe path' => [['SKILL.md' => "---\nname: ok\ndescription: x\n---\n", '../escape' => 'x'], 'isn\'t allowed'],
])->group('SKILL-002');
