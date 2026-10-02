<?php

namespace App\Tables\Formula;

use DateTimeInterface;

/**
 * Functions for lists of values (multiple selects, lookups, linked records). A single value counts as a one-item list.
 *
 * @internal
 */
final class ArrayFunctions
{
    public static function join(mixed $values, mixed $separator = ', '): string
    {
        return implode(Value::toText($separator), array_map(Value::toText(...), Value::flatten($values)));
    }

    /**
     * @return list<mixed>
     */
    public static function unique(mixed $values): array
    {
        $seen = [];
        $unique = [];

        foreach (Value::flatten($values) as $value) {
            $key = match (true) {
                $value instanceof DateTimeInterface => 'date:'.Value::microseconds($value),
                is_int($value), is_float($value) => 'number:'.Value::formatNumber($value),
                default => get_debug_type($value).':'.Value::toText($value),
            };

            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $value;
            }
        }

        return $unique;
    }

    /**
     * @return list<mixed>
     */
    public static function compact(mixed $values): array
    {
        return array_values(array_filter(
            Value::flatten($values),
            fn (mixed $value): bool => $value !== null && $value !== '',
        ));
    }

    /**
     * @return list<mixed>
     */
    public static function flatten(mixed $values): array
    {
        return Value::flatten($values);
    }
}
