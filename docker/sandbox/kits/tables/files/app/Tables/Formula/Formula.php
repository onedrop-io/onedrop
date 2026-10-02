<?php

namespace App\Tables\Formula;

use Closure;

/**
 * Airtable-style formulas. Stored formulas reference fields by key ("{value} * {cf_12}");
 * people write and read them with field names ("{Deal value} * 2").
 */
final class Formula
{
    public const MaxLength = 10_000;

    /**
     * Parses and checks a formula whose field references are keys in braces.
     *
     * @throws FormulaError for syntax errors, unknown functions and wrong argument counts
     */
    public static function compile(string $source): CompiledFormula
    {
        if (! mb_check_encoding($source, 'UTF-8')) {
            throw new FormulaError('Formula has invalid characters');
        }

        if (mb_strlen($source) > self::MaxLength) {
            throw new FormulaError('Formula is too long (max '.number_format(self::MaxLength).' characters)');
        }

        if (trim($source) === '') {
            throw new FormulaError('Formula is empty');
        }

        $parser = new Parser($source, (new Lexer($source))->tokenize());
        $root = $parser->parse();

        return new CompiledFormula($root, $parser->references());
    }

    /**
     * Turns field names into keys: "{Deal value} * 2" becomes "{value} * 2". Text in quotes is left alone.
     *
     * @param  array<string, string>  $keysByName
     *
     * @throws FormulaError for an unknown field name
     */
    public static function namesToKeys(string $source, array $keysByName): string
    {
        $lowercase = [];

        foreach ($keysByName as $name => $key) {
            $lowercase[mb_strtolower((string) $name)] ??= (string) $key;
        }

        return self::mapReferences($source, function (string $name) use ($keysByName, $lowercase): string {
            $trimmed = trim($name);

            foreach ([$name, $trimmed] as $candidate) {
                if (array_key_exists($candidate, $keysByName)) {
                    return (string) $keysByName[$candidate];
                }
            }

            return $lowercase[mb_strtolower($trimmed)] ?? throw new FormulaError('Unknown field {'.$trimmed.'}');
        });
    }

    /**
     * Turns field keys back into names for display. Keys that no longer exist become {Deleted field}.
     *
     * @param  array<string, string>  $namesByKey
     */
    public static function keysToNames(string $source, array $namesByKey): string
    {
        return self::mapReferences($source, fn (string $key): string => array_key_exists($key, $namesByKey)
            ? (string) $namesByKey[$key]
            : 'Deleted field');
    }

    /**
     * Keys referenced in a formula, without compiling it.
     *
     * @return list<string>
     */
    public static function references(string $source): array
    {
        $keys = [];

        self::mapReferences($source, function (string $key) use (&$keys): string {
            $keys[$key] = true;

            return $key;
        });

        return array_map('strval', array_keys($keys));
    }

    /**
     * Every function, for the formula editor's autocomplete.
     *
     * @return list<array{name: string, signature: string, description: string, category: string}>
     */
    public static function functions(): array
    {
        $functions = [];

        foreach (Functions::definitions() as $name => $definition) {
            $functions[] = [
                'name' => $name,
                'signature' => $definition['signature'],
                'description' => $definition['description'],
                'category' => $definition['category'],
            ];
        }

        return $functions;
    }

    /**
     * Rewrites the contents of each {…} outside quoted text.
     *
     * @param  Closure(string): string  $replace
     */
    private static function mapReferences(string $source, Closure $replace): string
    {
        $result = '';
        $length = strlen($source);
        $i = 0;

        while ($i < $length) {
            $char = $source[$i];

            if ($char === '"' || $char === "'") {
                $end = $i + 1;

                while ($end < $length && $source[$end] !== $char) {
                    $end += $source[$end] === '\\' ? 2 : 1;
                }

                $end = min($end + 1, $length);
                $result .= substr($source, $i, $end - $i);
                $i = $end;

                continue;
            }

            if ($char === '{') {
                $close = strpos($source, '}', $i + 1);

                if ($close === false) {
                    return $result.substr($source, $i);
                }

                $result .= '{'.$replace(substr($source, $i + 1, $close - $i - 1)).'}';
                $i = $close + 1;

                continue;
            }

            $result .= $char;
            $i++;
        }

        return $result;
    }
}
