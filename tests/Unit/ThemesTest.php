<?php

/** The theme ids listed in resources/js/lib/themes.ts. */
function listedThemes(): array
{
    preg_match_all("/\{ id: '([a-z0-9-]+)', name: '[^']+', kind: '(light|dark)' \}/", file_get_contents(base_path('resources/js/lib/themes.ts')), $matches);

    return array_combine($matches[1], $matches[2]);
}

test('every listed theme has its colors in themes.css, and every theme there is listed', function () {
    $css = file_get_contents(base_path('resources/css/themes.css'));
    preg_match_all("/\[data-theme='([a-z0-9-]+)'\]/", $css, $matches);

    expect(listedThemes())->toHaveCount(18)
        ->and(array_keys(listedThemes()))->toEqualCanonicalizing(array_unique($matches[1]));
})->group('SET-002');

test('every theme sets its base, syntax and terminal colors', function () {
    $css = file_get_contents(base_path('resources/css/themes.css'));
    $names = ['--t-bg', '--t-fg', '--t-sidebar', '--t-primary', '--t-primary-fg', '--syntax-keyword', '--syntax-string', '--syntax-comment',
        '--syntax-function', '--terminal-background', '--terminal-foreground', '--terminal-red', '--terminal-bright-white'];

    foreach (array_keys(listedThemes()) as $id) {
        preg_match("/\[data-theme='{$id}'\][^{]*\{([^}]*)\}/", $css, $block);

        foreach ($names as $name) {
            expect(str_contains($block[1] ?? '', "{$name}:"))->toBeTrue("{$id} has no {$name}");
        }
    }
})->group('SET-002');

test('there is a light and a dark OneDrop theme, the defaults', function () {
    expect(listedThemes())->toMatchArray(['onedrop-dark' => 'dark', 'onedrop-light' => 'light']);
})->group('SET-002');
