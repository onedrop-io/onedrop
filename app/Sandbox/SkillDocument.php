<?php

namespace App\Sandbox;

/**
 * A SKILL.md in the open Agent Skills format: YAML frontmatter (at least `name` and `description`), then instructions.
 * Only the frontmatter's top-level scalars are read (symfony/yaml is only a dev dependency here), which covers
 * plain, quoted, and block (`|`, `>`) values.
 */
class SkillDocument
{
    public const NAME_PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    public const MAX_NAME = 64;

    public const MAX_DESCRIPTION = 1024;

    /**
     * The frontmatter's top-level values, and the instructions after it.
     *
     * @return array{fields: array<string, string>, body: string}
     */
    public static function parse(string $content): array
    {
        $content = str_replace("\r\n", "\n", $content);

        if (! preg_match('/\A(?:\xEF\xBB\xBF)?---[ \t]*\n(.*?)\n---[ \t]*(?:\n|\z)(.*)\z/s', $content, $match)) {
            return ['fields' => [], 'body' => $content];
        }

        $fields = [];
        $lines = explode("\n", $match[1]);

        for ($i = 0; $i < count($lines); $i++) {
            if (! preg_match('/^([A-Za-z0-9_-]+):(?:[ \t]+(.*))?$/', $lines[$i], $key)) {
                continue;
            }

            $value = rtrim($key[2] ?? '');
            $more = [];

            while ($i + 1 < count($lines) && ($lines[$i + 1] === '' || preg_match('/^[ \t]/', $lines[$i + 1]))) {
                $more[] = $lines[++$i];
            }

            $fields[$key[1]] = self::scalar($value, $more);
        }

        return ['fields' => $fields, 'body' => ltrim($match[2], "\n")];
    }

    /**
     * A new SKILL.md.
     */
    public static function compose(string $name, string $description, string $body): string
    {
        return "---\nname: {$name}\ndescription: ".self::quote($description)."\n---\n\n".rtrim($body)."\n";
    }

    /**
     * The SKILL.md with a new name, description and instructions, keeping its other frontmatter (license, allowed-tools, ...).
     */
    public static function withFields(string $content, string $name, string $description, string $body): string
    {
        $content = str_replace("\r\n", "\n", $content);

        if (! preg_match('/\A(?:\xEF\xBB\xBF)?---[ \t]*\n(.*?)\n---[ \t]*(?:\n|\z)/s', $content, $match)) {
            return self::compose($name, $description, $body);
        }

        $kept = [];
        $lines = explode("\n", $match[1]);

        for ($i = 0; $i < count($lines); $i++) {
            if (preg_match('/^(name|description):/', $lines[$i])) {
                while ($i + 1 < count($lines) && ($lines[$i + 1] === '' || preg_match('/^[ \t]/', $lines[$i + 1]))) {
                    $i++;
                }

                continue;
            }

            $kept[] = $lines[$i];
        }

        $front = implode("\n", ["name: {$name}", 'description: '.self::quote($description), ...$kept]);

        return "---\n".rtrim($front)."\n---\n\n".rtrim($body)."\n";
    }

    /**
     * What's wrong with a skill's name and description, if anything.
     */
    public static function problem(?string $name, ?string $description): ?string
    {
        if ($name === null || $name === '') {
            return __('The skill needs a name.');
        }

        if (mb_strlen($name) > self::MAX_NAME || ! preg_match(self::NAME_PATTERN, $name)) {
            return __('":name" isn\'t a valid skill name: use lowercase letters, digits and single hyphens, up to 64 characters.', ['name' => mb_substr($name, 0, 80)]);
        }

        if ($description === null || trim($description) === '') {
            return __('The skill needs a description, so the agent knows when to use it.');
        }

        if (mb_strlen($description) > self::MAX_DESCRIPTION) {
            return __('The description is too long (1024 characters at most).');
        }

        return null;
    }

    /**
     * A YAML double-quoted string (JSON's escaping is valid YAML).
     */
    protected static function quote(string $value): string
    {
        return json_encode(trim($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * A frontmatter value, from its first line and any indented lines after it.
     *
     * @param  list<string>  $more
     */
    protected static function scalar(string $value, array $more): string
    {
        if (preg_match('/^([|>])[+-]?\d*$/', $value, $block)) {
            $lines = array_map('trim', $more);

            return trim($block[1] === '|' ? implode("\n", $lines) : preg_replace('/\s*\n\s*/', ' ', implode("\n", $lines)));
        }

        $value = trim($value.' '.implode(' ', array_map('trim', $more)));

        if (str_starts_with($value, '"') && str_ends_with($value, '"') && strlen($value) > 1) {
            $decoded = json_decode($value);

            return is_string($decoded) ? $decoded : substr($value, 1, -1);
        }

        if (str_starts_with($value, "'") && str_ends_with($value, "'") && strlen($value) > 1) {
            return str_replace("''", "'", substr($value, 1, -1));
        }

        return trim((string) preg_replace('/\s+#.*$/', '', $value));
    }
}
