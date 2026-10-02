<?php

namespace App\Tables\Formula;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use Throwable;

/**
 * Coercion and comparison rules shared by operators and functions.
 *
 * @internal
 */
final class Value
{
    /**
     * Blank values: null, empty text and empty lists.
     */
    public static function isBlank(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    /**
     * False, 0, '', null and [] are false; everything else is true.
     */
    public static function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value != 0;
        }

        return ! self::isBlank($value);
    }

    /**
     * A one-item list stands for its item.
     */
    public static function unwrap(mixed $value): mixed
    {
        while (is_array($value) && count($value) === 1) {
            $value = reset($value);
        }

        return $value;
    }

    public static function toNumber(mixed $value): int|float
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if ($value === null || $value === '' || $value === []) {
            return 0;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            if (is_numeric($trimmed)) {
                return $trimmed + 0;
            }

            throw new FormulaError("Can't use text in a calculation");
        }

        if (is_array($value)) {
            if (count($value) === 1) {
                return self::toNumber(reset($value));
            }

            throw new FormulaError("Can't use a list of values in a calculation");
        }

        if ($value instanceof DateTimeInterface) {
            throw new FormulaError("Can't use a date in a calculation (use DATEADD or DATETIME_DIFF)");
        }

        throw new FormulaError("Can't use this value in a calculation");
    }

    /**
     * A whole number, truncated toward zero.
     */
    public static function toInteger(mixed $value): int
    {
        $number = self::toNumber($value);

        if (is_float($number)) {
            if (! is_finite($number) || abs($number) >= PHP_INT_MAX) {
                throw new FormulaError('Number is too big');
            }

            return (int) $number;
        }

        return $number;
    }

    public static function toText(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if ($value === null) {
            return '';
        }

        if (is_int($value) || is_float($value)) {
            return self::formatNumber($value);
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value)) {
            return implode(', ', array_map(self::toText(...), self::flatten($value)));
        }

        if ($value instanceof DateTimeInterface) {
            $date = self::inTimezone($value);

            return $date->format('H:i:s.u') === '00:00:00.000000'
                ? $date->format('Y-m-d')
                : $date->format('Y-m-d\TH:i:sP');
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        return '';
    }

    /**
     * Numbers as people write them: no trailing zeros, no float noise (0.1 + 0.2 is "0.3"), no exponents.
     */
    public static function formatNumber(int|float $number): string
    {
        if (is_int($number)) {
            return (string) $number;
        }

        if (! is_finite($number)) {
            return is_nan($number) ? 'NaN' : ($number > 0 ? 'Infinity' : '-Infinity');
        }

        if ($number == 0) {
            return '0';
        }

        $magnitude = (int) floor(log10(abs($number)));
        $decimals = 14 - $magnitude;

        if ($decimals <= 0) {
            return number_format(round($number, $decimals), 0, '.', '');
        }

        if ($decimals > 40) {
            return (string) $number;
        }

        $text = number_format($number, $decimals, '.', '');
        $text = rtrim(rtrim($text, '0'), '.');

        return $text === '-0' ? '0' : $text;
    }

    /**
     * Arrays flattened to one level; anything else becomes a one-item list.
     *
     * @return list<mixed>
     */
    public static function flatten(mixed $value): array
    {
        if (! is_array($value)) {
            return [$value];
        }

        $flat = [];

        array_walk_recursive($value, function (mixed $item) use (&$flat): void {
            $flat[] = $item;
        });

        return $flat;
    }

    /**
     * Flattened values of all arguments.
     *
     * @param  array<int, mixed>  $arguments
     * @return list<mixed>
     */
    public static function flattenAll(array $arguments): array
    {
        $flat = [];

        foreach ($arguments as $argument) {
            foreach (self::flatten($argument) as $item) {
                $flat[] = $item;
            }
        }

        return $flat;
    }

    /**
     * A date in the app timezone, or null for a blank value. Throws for anything that isn't a date.
     */
    public static function toDate(mixed $value): ?CarbonImmutable
    {
        $value = self::unwrap($value);

        if (self::isBlank($value)) {
            return null;
        }

        $date = self::tryDate($value);

        if ($date === null) {
            throw new FormulaError('Not a date: "'.mb_strimwidth(self::toText($value), 0, 40, '…').'"');
        }

        return $date;
    }

    /**
     * A date in the app timezone, or null when the value isn't one (or isn't text that reads as one).
     */
    public static function tryDate(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return self::inTimezone($value);
        }

        if (! is_string($value)) {
            return null;
        }

        $text = trim($value);

        if ($text === '' || is_numeric($text) || preg_match('/\d/', $text) !== 1 && ! preg_match('/^(today|tomorrow|yesterday|now)$/i', $text)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($text, self::timezone());
        } catch (Throwable) {
            return null;
        }
    }

    public static function inTimezone(DateTimeInterface $date): CarbonImmutable
    {
        return CarbonImmutable::instance($date)->setTimezone(self::timezone());
    }

    /**
     * The app's timezone (config('app.timezone') when a Laravel app is running).
     */
    public static function timezone(): string
    {
        if (function_exists('config') && function_exists('app')) {
            try {
                if (app()->bound('config')) {
                    $timezone = config('app.timezone');

                    if (is_string($timezone) && $timezone !== '') {
                        return $timezone;
                    }
                }
            } catch (Throwable) {
                // No application container; fall back to PHP's default timezone.
            }
        }

        return date_default_timezone_get();
    }

    /**
     * Blank equals blank; otherwise the same rules as compare().
     */
    public static function equals(mixed $left, mixed $right): bool
    {
        $left = self::unwrap($left);
        $right = self::unwrap($right);
        $leftBlank = self::isBlank($left);
        $rightBlank = self::isBlank($right);

        if ($leftBlank || $rightBlank) {
            return $leftBlank && $rightBlank;
        }

        return self::compare($left, $right) === 0;
    }

    /**
     * -1, 0 or 1; null when the two can't be ordered (a date against text that isn't a date).
     * Dates compare by time, numbers numerically (numeric text too, when the other side is a number), text case-sensitively.
     */
    public static function compare(mixed $left, mixed $right): ?int
    {
        $left = self::unwrap($left);
        $right = self::unwrap($right);

        if (self::isBlank($left) && self::isBlank($right)) {
            return 0;
        }

        if ($left instanceof DateTimeInterface || $right instanceof DateTimeInterface) {
            $leftDate = self::tryDate($left);
            $rightDate = self::tryDate($right);

            if ($leftDate === null || $rightDate === null) {
                return null;
            }

            return self::microseconds($leftDate) <=> self::microseconds($rightDate);
        }

        if (self::isNumeric($left) && self::isNumeric($right)
            && (is_int($left) || is_float($left) || is_bool($left) || is_int($right) || is_float($right) || is_bool($right))) {
            $leftNumber = self::toNumber($left);
            $rightNumber = self::toNumber($right);

            if (is_float($leftNumber) || is_float($rightNumber)) {
                $tolerance = 1e-12 * max(1, abs($leftNumber), abs($rightNumber));

                if (abs($leftNumber - $rightNumber) <= $tolerance) {
                    return 0;
                }
            }

            return $leftNumber <=> $rightNumber;
        }

        return strcmp(self::toText($left), self::toText($right)) <=> 0;
    }

    /**
     * Microseconds since the Unix epoch.
     */
    public static function microseconds(DateTimeInterface $date): int
    {
        return (int) $date->format('U') * 1_000_000 + (int) $date->format('u');
    }

    /**
     * The value a formula hands back: dates immutable, no NaN or infinity.
     */
    public static function result(mixed $value): mixed
    {
        if (is_float($value) && ! is_finite($value)) {
            throw new FormulaError(is_nan($value) ? 'Result is not a number' : 'Number is too big');
        }

        if ($value instanceof DateTimeInterface && ! $value instanceof DateTimeImmutable) {
            return DateTimeImmutable::createFromInterface($value);
        }

        if (is_array($value)) {
            return array_map(self::result(...), array_values($value));
        }

        return $value;
    }

    private static function isNumeric(mixed $value): bool
    {
        return is_int($value) || is_float($value) || is_bool($value) || $value === null || $value === ''
            || (is_string($value) && is_numeric(trim($value)));
    }
}
