<?php

namespace App\Sandbox;

/**
 * A skill folder's files (from GitHub, an upload or the project), checked and turned into a skill's attributes.
 * The folder is the one holding SKILL.md: the files' root, or a single folder that has one.
 */
class SkillPackage
{
    public const MAX_FILES = 50;

    public const MAX_BYTES = 1024 * 1024;

    /**
     * @param  array<string, string>  $files  path in the folder => bytes
     * @return array{name: string, description: string, content: string, files: list<array{path: string, data: string}>}
     *
     * @throws SkillException
     */
    public static function fromFiles(array $files): array
    {
        $files = self::withoutJunk($files);
        $root = self::root(array_keys($files));
        $content = null;
        $others = [];
        $bytes = 0;

        foreach ($files as $path => $data) {
            if ($root !== '' && ! str_starts_with($path, $root.'/')) {
                continue;
            }

            $relative = $root === '' ? $path : substr($path, strlen($root) + 1);

            if (! WorkspaceFiles::isSafePath($relative)) {
                throw new SkillException(__('The skill has a file with a path that isn\'t allowed: :path', ['path' => mb_substr($relative, 0, 120)]));
            }

            $bytes += strlen($data);

            if ($relative === 'SKILL.md') {
                $content = $data;
            } else {
                $others[] = ['path' => $relative, 'data' => base64_encode($data)];
            }
        }

        if ($content === null) {
            throw new SkillException(__('There\'s no SKILL.md in it, so it isn\'t a skill.'));
        }

        if (count($others) + 1 > self::MAX_FILES || $bytes > self::MAX_BYTES) {
            throw new SkillException(__('The skill is too big: skills can have up to :files files and 1 MB.', ['files' => self::MAX_FILES]));
        }

        if (! mb_check_encoding($content, 'UTF-8')) {
            throw new SkillException(__('SKILL.md has to be text (UTF-8).'));
        }

        $fields = SkillDocument::parse($content)['fields'];
        $name = $fields['name'] ?? null;
        $description = $fields['description'] ?? null;

        if ($problem = SkillDocument::problem($name, $description)) {
            throw new SkillException($problem);
        }

        usort($others, fn (array $a, array $b) => strcmp($a['path'], $b['path']));

        return ['name' => $name, 'description' => trim($description), 'content' => $content, 'files' => $others];
    }

    /**
     * The folder holding SKILL.md: the root, or the only folder with one.
     *
     * @param  list<string>  $paths
     *
     * @throws SkillException
     */
    public static function root(array $paths): string
    {
        if (in_array('SKILL.md', $paths, true)) {
            return '';
        }

        $folders = array_values(array_map(
            fn (string $path) => dirname($path),
            array_filter($paths, fn (string $path) => basename($path) === 'SKILL.md'),
        ));

        if (count($folders) > 1) {
            sort($folders);
            $names = implode(', ', array_map('basename', array_slice($folders, 0, 5)));

            throw new SkillException(__('That has :count skills (:names). Pick one of them.', ['count' => count($folders), 'names' => $names]));
        }

        return $folders[0] ?? throw new SkillException(__('There\'s no SKILL.md in it, so it isn\'t a skill.'));
    }

    /**
     * Without macOS zip leftovers.
     *
     * @param  array<string, string>  $files
     * @return array<string, string>
     */
    protected static function withoutJunk(array $files): array
    {
        return array_filter($files, fn (string $path) => ! str_starts_with($path, '__MACOSX/') && basename($path) !== '.DS_Store', ARRAY_FILTER_USE_KEY);
    }
}
