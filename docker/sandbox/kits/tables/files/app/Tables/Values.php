<?php

namespace App\Tables;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Throwable;

/**
 * Reading, parsing and formatting single values. Nothing here needs the database.
 */
final class Values
{
    /**
     * Types whose cell value is text.
     *
     * @var list<string>
     */
    public const TEXT_TYPES = ['text', 'longText', 'url', 'email', 'phone'];

    /**
     * Types whose cell value is a number.
     *
     * @var list<string>
     */
    public const NUMBER_TYPES = ['number', 'currency', 'percent', 'rating'];

    /**
     * Types whose cell value is a list.
     *
     * @var list<string>
     */
    public const LIST_TYPES = ['multiSelect', 'link', 'attachment', 'lookup'];

    /**
     * The value a field has when nothing's in it.
     */
    public static function empty(Field $field): mixed
    {
        return match (true) {
            $field->type === 'checkbox' => false,
            $field->type === 'count' => 0,
            in_array($field->type, self::LIST_TYPES, true) => [],
            default => null,
        };
    }

    /**
     * A stored value (a column or a custom_fields entry) as the cell value the browser gets.
     * Links and attachment urls are handled by the Computation.
     */
    public static function fromStored(Field $field, mixed $raw): mixed
    {
        $type = $field->type;

        if (in_array($type, self::TEXT_TYPES, true) || $type === 'select') {
            return $raw === null || $raw === '' || is_array($raw) ? null : (string) $raw;
        }

        if (in_array($type, self::NUMBER_TYPES, true)) {
            return self::number($raw);
        }

        return match ($type) {
            'checkbox' => in_array($raw, [true, 1, '1', 'true', 't'], true),
            'date' => self::storedDate($raw, (bool) $field->option('includeTime', false)),
            'createdAt', 'updatedAt' => self::storedDate($raw, true),
            'multiSelect' => array_values(array_map('strval', array_filter(self::list($raw), fn (mixed $item) => is_scalar($item) && $item !== ''))),
            'user' => is_numeric($raw) ? (int) $raw : null,
            'attachment' => array_values(array_filter(self::list($raw), fn (mixed $item) => is_array($item) && isset($item['key']))),
            default => $raw,
        };
    }

    /**
     * A json value that may still be encoded, as an array.
     *
     * @return array<mixed>
     */
    public static function list(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        return is_array($raw) ? $raw : [];
    }

    /**
     * A number from the database (Postgres sends decimals as strings), as an int when it's whole.
     */
    public static function number(mixed $raw): int|float|null
    {
        if (is_int($raw)) {
            return $raw;
        }

        if (is_bool($raw) || ! is_numeric($raw)) {
            return null;
        }

        $number = (float) $raw;

        if (! is_finite($number)) {
            return null;
        }

        return floor($number) === $number && abs($number) < 1e15 ? (int) $number : $number;
    }

    /**
     * A number from text people type or paste: "$1,200", "(30)", "12.5%", "1 000".
     * Percentages become fractions when $percent is set.
     */
    public static function parseNumber(mixed $input, bool $percent = false): int|float|null
    {
        if (is_int($input) || is_float($input)) {
            return self::number($input);
        }

        if (! is_string($input)) {
            return null;
        }

        $text = trim($input);
        $negative = false;

        if (preg_match('/^\((.*)\)$/', $text, $matches)) {
            [$text, $negative] = [$matches[1], true];
        }

        $hasPercent = str_contains($text, '%');
        $text = preg_replace('/[\s,\x{00A0}%]|[^\d.eE+\-]/u', '', $text) ?? '';

        if ($text === '' || ! is_numeric($text)) {
            return null;
        }

        $number = (float) $text;

        if ($negative) {
            $number = -$number;
        }

        if ($percent && $hasPercent) {
            $number /= 100;
        }

        return self::number(round($number, 10));
    }

    /**
     * Yes or no from what people type: true, "yes", "x", "checked", "1"... Null when it's neither.
     */
    public static function parseBool(mixed $input): ?bool
    {
        if (is_bool($input)) {
            return $input;
        }

        if ($input === null || $input === '' || $input === 0 || $input === 1) {
            return (bool) $input;
        }

        if (is_float($input)) {
            return $input != 0;
        }

        if (! is_string($input)) {
            return null;
        }

        $text = strtolower(trim($input));

        return match (true) {
            in_array($text, ['true', 'yes', 'y', 'x', '✓', '✔', 'checked', 'on', '1'], true) => true,
            in_array($text, ['false', 'no', 'n', 'unchecked', 'off', '0', ''], true) => false,
            default => null,
        };
    }

    /**
     * A date from common formats: "2026-10-02", ISO 8601 with an offset, "10/2/2026", "Oct 2, 2026".
     */
    public static function parseDate(mixed $input): ?CarbonImmutable
    {
        if ($input instanceof DateTimeInterface) {
            return CarbonImmutable::instance($input);
        }

        if (! is_string($input)) {
            return null;
        }

        $text = trim($input);

        if ($text === '' || ! preg_match('/\d/', $text) || preg_match('/^[\d.\s]+$/', $text)) {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text)) {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $text, config('app.timezone'));

            return $date && $date->format('Y-m-d') === $text ? $date : null;
        }

        if (preg_match('/^(\d{1,2})[\/.](\d{1,2})[\/.](\d{2}|\d{4})$/', $text, $matches)) {
            [, $month, $day, $year] = $matches;
            $year = strlen($year) === 2 ? 2000 + (int) $year : (int) $year;

            return checkdate((int) $month, (int) $day, $year)
                ? CarbonImmutable::create($year, (int) $month, (int) $day, 0, 0, 0, config('app.timezone'))
                : null;
        }

        try {
            return CarbonImmutable::parse($text, config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A stored date or time as the browser gets it: "YYYY-MM-DD", or ISO 8601 with time.
     */
    public static function storedDate(mixed $raw, bool $includeTime): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (! $includeTime && is_string($raw) && preg_match('/^(\d{4}-\d{2}-\d{2})/', $raw, $matches)) {
            return $matches[1];
        }

        $date = $raw instanceof DateTimeInterface ? CarbonImmutable::instance($raw) : self::parseDate((string) $raw);

        return $date === null ? null : self::formatDate($date, $includeTime);
    }

    public static function formatDate(DateTimeInterface $date, bool $includeTime): string
    {
        $date = CarbonImmutable::instance($date);

        return $includeTime ? $date->toIso8601String() : $date->format('Y-m-d');
    }

    /**
     * A cell value's date ("YYYY-MM-DD" or ISO 8601) as a date for formulas.
     */
    public static function toDate(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value, config('app.timezone')) ?: null;
        }

        try {
            return CarbonImmutable::parse($value)->setTimezone(config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A formula's result as a cell value: dates as ISO strings, floats rounded to 10 decimals.
     */
    public static function formulaCell(mixed $result, string $format): mixed
    {
        if ($result instanceof DateTimeInterface) {
            $date = CarbonImmutable::instance($result);

            return $format === 'date' && $date->format('H:i:s') === '00:00:00'
                ? $date->format('Y-m-d')
                : $date->toIso8601String();
        }

        if (is_float($result)) {
            return is_finite($result) ? self::number(round($result, 10)) : null;
        }

        if (is_array($result)) {
            return array_values(array_map(fn (mixed $item) => self::formulaCell($item, $format), $result));
        }

        return $result;
    }

    /**
     * A number as text: "1,200.50", "$1,200", "50%".
     */
    public static function formatNumber(int|float $number, string $format, ?int $precision = null, ?string $symbol = null): string
    {
        return match ($format) {
            'currency' => ($number < 0 ? '-' : '').($symbol ?? '$').number_format(abs($number), $precision ?? 2),
            'percent' => self::plainNumber($number * 100, $precision).'%',
            default => self::plainNumber($number, $precision),
        };
    }

    private static function plainNumber(int|float $number, ?int $precision): string
    {
        if ($precision !== null) {
            return number_format($number, $precision, '.', '');
        }

        $number = round($number, 10);

        return is_float($number) && floor($number) !== $number
            ? rtrim(rtrim(number_format($number, 10, '.', ''), '0'), '.')
            : (string) (int) $number;
    }

    /**
     * Whether a value counts as filled in.
     */
    public static function isFilled(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== [] && $value !== false;
    }

    /**
     * A list, flattened one level for nested lists (but not for attachments, which are objects).
     *
     * @param  array<mixed>  $values
     * @return list<mixed>
     */
    public static function flatten(array $values): array
    {
        $flat = [];

        foreach ($values as $value) {
            if (is_array($value) && array_is_list($value)) {
                array_push($flat, ...self::flatten($value));
            } elseif (self::isFilled($value) || $value === false || $value === 0) {
                $flat[] = $value;
            }
        }

        return $flat;
    }
}
