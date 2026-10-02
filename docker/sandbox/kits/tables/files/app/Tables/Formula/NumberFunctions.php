<?php

namespace App\Tables\Formula;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * @internal
 */
final class NumberFunctions
{
    public static function round(mixed $number, mixed $places = 0): int|float
    {
        return self::whole(round(Value::toNumber($number), Value::toInteger($places)));
    }

    public static function roundUp(mixed $number, mixed $places = 0): int|float
    {
        return self::roundToward(Value::toNumber($number), Value::toInteger($places), away: true);
    }

    public static function roundDown(mixed $number, mixed $places = 0): int|float
    {
        return self::roundToward(Value::toNumber($number), Value::toInteger($places), away: false);
    }

    public static function ceiling(mixed $number, mixed $significance = 1): int|float
    {
        return self::toMultiple(Value::toNumber($number), Value::toNumber($significance), up: true);
    }

    public static function floor(mixed $number, mixed $significance = 1): int|float
    {
        return self::toMultiple(Value::toNumber($number), Value::toNumber($significance), up: false);
    }

    public static function int(mixed $number): int|float
    {
        return self::whole(floor(Value::toNumber($number)));
    }

    public static function abs(mixed $number): int|float
    {
        return abs(Value::toNumber($number));
    }

    public static function mod(mixed $number, mixed $divisor): int|float
    {
        $number = Value::toNumber($number);
        $divisor = Value::toNumber($divisor);

        if ($divisor == 0) {
            throw new FormulaError("Can't divide by zero");
        }

        if (is_int($number) && is_int($divisor)) {
            return $divisor === -1 ? 0 : $number % $divisor;
        }

        return fmod($number, $divisor);
    }

    public static function power(mixed $base, mixed $exponent): int|float
    {
        $base = Value::toNumber($base);
        $exponent = Value::toNumber($exponent);

        if ($base == 0 && $exponent < 0) {
            throw new FormulaError("Can't divide by zero");
        }

        return self::finite($base ** $exponent);
    }

    public static function sqrt(mixed $number): int|float
    {
        $number = Value::toNumber($number);

        if ($number < 0) {
            throw new FormulaError("Can't take the square root of a negative number");
        }

        return self::whole(sqrt($number));
    }

    public static function exp(mixed $power): float
    {
        return self::finite(exp(Value::toNumber($power)));
    }

    public static function log(mixed $number, mixed $base = 10): int|float
    {
        $number = Value::toNumber($number);
        $base = Value::toNumber($base);

        if ($number <= 0) {
            throw new FormulaError('LOG needs a number greater than 0');
        }

        if ($base <= 0 || $base == 1) {
            throw new FormulaError('LOG needs a base greater than 0, other than 1');
        }

        $result = $base == 10 ? log10($number) : log($number) / log($base);

        if (abs($result - round($result)) < 1e-12) {
            $result = round($result);
        }

        return self::whole($result);
    }

    public static function even(mixed $number): int|float
    {
        $number = Value::toNumber($number);
        $sign = $number < 0 ? -1 : 1;

        return self::whole($sign * ceil(round(abs($number) / 2, 9)) * 2);
    }

    public static function odd(mixed $number): int|float
    {
        $number = Value::toNumber($number);
        $sign = $number < 0 ? -1 : 1;
        $whole = ceil(round(abs($number), 9));

        if (fmod($whole, 2) == 0) {
            $whole++;
        }

        return self::whole($sign * $whole);
    }

    public static function sum(mixed ...$values): int|float
    {
        $total = 0;

        foreach (self::numbers($values) as $number) {
            $total += $number;
        }

        return $total;
    }

    public static function average(mixed ...$values): int|float|null
    {
        $numbers = self::numbers($values);

        return $numbers === [] ? null : array_sum($numbers) / count($numbers);
    }

    public static function min(mixed ...$values): int|float|CarbonImmutable|null
    {
        return self::extreme($values, lowest: true);
    }

    public static function max(mixed ...$values): int|float|CarbonImmutable|null
    {
        return self::extreme($values, lowest: false);
    }

    public static function count(mixed ...$values): int
    {
        return count(array_filter(Value::flattenAll($values), fn (mixed $value): bool => is_int($value) || is_float($value)));
    }

    public static function countNonEmpty(mixed ...$values): int
    {
        return count(array_filter(Value::flattenAll($values), fn (mixed $value): bool => ! Value::isBlank($value)));
    }

    public static function countAll(mixed ...$values): int
    {
        return count(Value::flattenAll($values));
    }

    /**
     * Reads a number from text, ignoring currency symbols, thousands separators and spaces. "50%" is 0.5.
     */
    public static function value(mixed $text): int|float|null
    {
        $text = Value::unwrap($text);

        if (is_int($text) || is_float($text)) {
            return $text;
        }

        if (is_bool($text)) {
            return $text ? 1 : 0;
        }

        $cleaned = (string) preg_replace('/[\s,\p{Sc}]+/u', '', Value::toText($text));
        $percent = str_ends_with($cleaned, '%');

        if ($percent) {
            $cleaned = substr($cleaned, 0, -1);
        }

        if ($cleaned === '' || ! is_numeric($cleaned)) {
            return null;
        }

        $number = $cleaned + 0;

        return $percent ? $number / 100 : $number;
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<int|float>
     */
    private static function numbers(array $values): array
    {
        $numbers = [];

        foreach (Value::flattenAll($values) as $value) {
            if (! Value::isBlank($value)) {
                $numbers[] = Value::toNumber($value);
            }
        }

        return $numbers;
    }

    /**
     * @param  array<int, mixed>  $values
     */
    private static function extreme(array $values, bool $lowest): int|float|CarbonImmutable|null
    {
        $items = array_values(array_filter(Value::flattenAll($values), fn (mixed $value): bool => ! Value::isBlank($value)));

        if ($items === []) {
            return null;
        }

        if (count(array_filter($items, fn (mixed $value): bool => $value instanceof DateTimeInterface)) === count($items)) {
            usort($items, fn (DateTimeInterface $a, DateTimeInterface $b): int => Value::microseconds($a) <=> Value::microseconds($b));

            return Value::inTimezone($lowest ? $items[0] : $items[count($items) - 1]);
        }

        $numbers = array_map(Value::toNumber(...), $items);

        return $lowest ? min($numbers) : max($numbers);
    }

    private static function roundToward(int|float $number, int $places, bool $away): int|float
    {
        $factor = 10 ** abs($places);
        $absolute = abs($number);
        $scaled = round($places >= 0 ? $absolute * $factor : $absolute / $factor, 9);
        $rounded = $away ? ceil($scaled) : floor($scaled);
        $rounded = $places >= 0 ? round($rounded / $factor, $places) : $rounded * $factor;

        return self::whole($number < 0 ? -$rounded : $rounded);
    }

    private static function toMultiple(int|float $number, int|float $significance, bool $up): int|float
    {
        if ($significance == 0) {
            return 0;
        }

        $steps = round($number / $significance, 9);
        $steps = $up ? ceil($steps) : floor($steps);

        return self::whole(round($steps * $significance, 12));
    }

    /**
     * Whole-number floats become ints, so 3.0 reads as 3.
     */
    private static function whole(int|float $number): int|float
    {
        if (is_float($number) && is_finite($number) && floor($number) == $number && abs($number) < 9.0e15) {
            return (int) $number;
        }

        return $number;
    }

    private static function finite(int|float $number): int|float
    {
        if (is_float($number) && is_nan($number)) {
            throw new FormulaError('Result is not a number');
        }

        if (is_float($number) && is_infinite($number)) {
            throw new FormulaError('Number is too big');
        }

        return $number;
    }
}
