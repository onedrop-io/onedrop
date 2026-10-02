<?php

namespace App\Tables\Formula;

use Closure;

/**
 * Text functions. Positions are 1-based characters (multibyte-safe).
 *
 * @internal
 */
final class TextFunctions
{
    public const MaxLength = 100_000;

    private const BacktrackLimit = 100_000;

    /** @var array<string, string> */
    private static array $patterns = [];

    public static function concatenate(mixed ...$values): string
    {
        return implode('', array_map(Value::toText(...), $values));
    }

    public static function len(mixed $text): int
    {
        return mb_strlen(Value::toText($text));
    }

    public static function lower(mixed $text): string
    {
        return mb_strtolower(Value::toText($text));
    }

    public static function upper(mixed $text): string
    {
        return mb_strtoupper(Value::toText($text));
    }

    public static function trim(mixed $text): string
    {
        return (string) preg_replace('/^\s+|\s+$/u', '', Value::toText($text));
    }

    public static function left(mixed $text, mixed $count): string
    {
        return mb_substr(Value::toText($text), 0, self::count('LEFT', $count));
    }

    public static function right(mixed $text, mixed $count): string
    {
        $count = self::count('RIGHT', $count);

        return $count === 0 ? '' : mb_substr(Value::toText($text), -$count);
    }

    public static function mid(mixed $text, mixed $start, mixed $count): string
    {
        return mb_substr(Value::toText($text), self::start('MID', $start) - 1, self::count('MID', $count));
    }

    public static function find(mixed $needle, mixed $haystack, mixed $start = 1): int
    {
        return self::position($needle, $haystack, $start, 'FIND', caseSensitive: true) ?? 0;
    }

    public static function search(mixed $needle, mixed $haystack, mixed $start = 1): ?int
    {
        return self::position($needle, $haystack, $start, 'SEARCH', caseSensitive: false);
    }

    public static function substitute(mixed $text, mixed $old, mixed $new, mixed $occurrence = null): string
    {
        $text = Value::toText($text);
        $old = Value::toText($old);
        $new = Value::toText($new);

        if ($old === '') {
            return $text;
        }

        if ($occurrence === null) {
            return str_replace($old, $new, $text);
        }

        $occurrence = Value::toInteger($occurrence);

        if ($occurrence < 1) {
            throw new FormulaError('SUBSTITUTE needs an occurrence of 1 or more');
        }

        $offset = -1;

        for ($i = 0; $i < $occurrence; $i++) {
            $offset = strpos($text, $old, $offset + 1);

            if ($offset === false) {
                return $text;
            }
        }

        return substr($text, 0, $offset).$new.substr($text, $offset + strlen($old));
    }

    public static function replace(mixed $text, mixed $start, mixed $count, mixed $new): string
    {
        $text = Value::toText($text);
        $start = self::start('REPLACE', $start);
        $count = self::count('REPLACE', $count);

        return mb_substr($text, 0, $start - 1).Value::toText($new).mb_substr($text, $start - 1 + $count);
    }

    public static function rept(mixed $text, mixed $times): string
    {
        $text = Value::toText($text);
        $times = self::count('REPT', $times);

        if (mb_strlen($text) * $times > self::MaxLength) {
            throw new FormulaError('REPT would make text longer than '.number_format(self::MaxLength).' characters');
        }

        return str_repeat($text, $times);
    }

    public static function t(mixed $value): string
    {
        $value = Value::unwrap($value);

        return is_string($value) ? $value : '';
    }

    /**
     * Like JavaScript's encodeURIComponent.
     */
    public static function encodeUrlComponent(mixed $text): string
    {
        return strtr(rawurlencode(Value::toText($text)), ['%21' => '!', '%27' => "'", '%28' => '(', '%29' => ')', '%2A' => '*']);
    }

    public static function regexMatch(mixed $text, mixed $pattern): bool
    {
        $regex = self::pattern($pattern);
        $subject = Value::toText($text);

        return self::run(fn (): int|false => preg_match($regex, $subject)) === 1;
    }

    public static function regexExtract(mixed $text, mixed $pattern): ?string
    {
        $regex = self::pattern($pattern);
        $subject = Value::toText($text);
        $match = [];

        $found = self::run(function () use ($regex, $subject, &$match): int|false {
            return preg_match($regex, $subject, $match);
        });

        return $found === 1 ? $match[0] : null;
    }

    public static function regexReplace(mixed $text, mixed $pattern, mixed $replacement): string
    {
        $regex = self::pattern($pattern);
        $subject = Value::toText($text);
        $replacement = Value::toText($replacement);

        return self::run(fn (): ?string => preg_replace($regex, $replacement, $subject));
    }

    /**
     * A user's pattern as a PCRE regex: "~" delimiters (escaped inside the pattern), Unicode mode.
     */
    private static function pattern(mixed $pattern): string
    {
        $pattern = Value::toText($pattern);

        if (isset(self::$patterns[$pattern])) {
            return self::$patterns[$pattern];
        }

        $escaped = '';
        $length = strlen($pattern);

        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];

            if ($char === '\\' && $i + 1 < $length) {
                $escaped .= $char.$pattern[++$i];
            } elseif ($char === '~') {
                $escaped .= '\\~';
            } else {
                $escaped .= $char;
            }
        }

        $regex = '~'.$escaped.'~u';

        // A handler rather than @, so test runners that report suppressed warnings stay quiet too.
        set_error_handler(static fn (): bool => true);

        try {
            $valid = $pattern !== '' && preg_match($regex, '') !== false;
        } finally {
            restore_error_handler();
        }

        if (! $valid) {
            throw new FormulaError('Invalid pattern');
        }

        if (count(self::$patterns) > 500) {
            self::$patterns = [];
        }

        return self::$patterns[$pattern] = $regex;
    }

    /**
     * Runs a preg_* call with a lower backtrack limit, turning PCRE failures into formula errors.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private static function run(Closure $callback): mixed
    {
        $previous = ini_set('pcre.backtrack_limit', (string) self::BacktrackLimit);

        set_error_handler(static fn (): bool => true);

        try {
            $result = $callback();
        } finally {
            restore_error_handler();

            if ($previous !== false) {
                ini_set('pcre.backtrack_limit', $previous);
            }
        }

        if ($result === false || $result === null || preg_last_error() !== PREG_NO_ERROR) {
            throw new FormulaError(match (preg_last_error()) {
                PREG_BACKTRACK_LIMIT_ERROR, PREG_RECURSION_LIMIT_ERROR, PREG_JIT_STACKLIMIT_ERROR => 'Pattern is too complex for this text',
                PREG_BAD_UTF8_ERROR, PREG_BAD_UTF8_OFFSET_ERROR => 'Text has invalid characters',
                default => 'Invalid pattern',
            });
        }

        return $result;
    }

    private static function position(mixed $needle, mixed $haystack, mixed $start, string $function, bool $caseSensitive): ?int
    {
        $needle = Value::toText($needle);
        $haystack = Value::toText($haystack);
        $offset = self::start($function, $start) - 1;

        if ($offset > mb_strlen($haystack)) {
            return null;
        }

        if ($needle === '') {
            return $offset + 1;
        }

        $position = $caseSensitive ? mb_strpos($haystack, $needle, $offset) : mb_stripos($haystack, $needle, $offset);

        return $position === false ? null : $position + 1;
    }

    private static function count(string $function, mixed $count): int
    {
        $count = Value::toInteger($count);

        if ($count < 0) {
            throw new FormulaError($function.' needs a count of 0 or more');
        }

        return $count;
    }

    private static function start(string $function, mixed $start): int
    {
        $start = Value::toInteger($start);

        if ($start < 1) {
            throw new FormulaError($function.' needs a start position of 1 or more');
        }

        return $start;
    }
}
