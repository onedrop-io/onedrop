<?php

namespace App\Tables\Formula;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Date functions. Dates are read and returned in the app timezone. Blank dates give blank results.
 *
 * @internal
 */
final class DateFunctions
{
    private const MicrosecondsPer = [
        'milliseconds' => 1_000,
        'seconds' => 1_000_000,
        'minutes' => 60_000_000,
        'hours' => 3_600_000_000,
        'days' => 86_400_000_000,
        'weeks' => 604_800_000_000,
    ];

    private const UnitAliases = [
        'y' => 'years', 'year' => 'years', 'years' => 'years', 'yr' => 'years', 'yrs' => 'years',
        'q' => 'quarters', 'quarter' => 'quarters', 'quarters' => 'quarters',
        'month' => 'months', 'months' => 'months', 'mo' => 'months', 'mos' => 'months',
        'w' => 'weeks', 'week' => 'weeks', 'weeks' => 'weeks', 'wk' => 'weeks', 'wks' => 'weeks',
        'd' => 'days', 'day' => 'days', 'days' => 'days',
        'h' => 'hours', 'hour' => 'hours', 'hours' => 'hours', 'hr' => 'hours', 'hrs' => 'hours',
        'minute' => 'minutes', 'minutes' => 'minutes', 'min' => 'minutes', 'mins' => 'minutes',
        's' => 'seconds', 'second' => 'seconds', 'seconds' => 'seconds', 'sec' => 'seconds', 'secs' => 'seconds',
        'ms' => 'milliseconds', 'millisecond' => 'milliseconds', 'milliseconds' => 'milliseconds',
    ];

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(Value::timezone())->startOfDay();
    }

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(Value::timezone());
    }

    public static function year(mixed $date): ?int
    {
        return self::part($date, 'Y');
    }

    public static function month(mixed $date): ?int
    {
        return self::part($date, 'n');
    }

    public static function day(mixed $date): ?int
    {
        return self::part($date, 'j');
    }

    public static function hour(mixed $date): ?int
    {
        return self::part($date, 'G');
    }

    public static function minute(mixed $date): ?int
    {
        return self::part($date, 'i');
    }

    public static function second(mixed $date): ?int
    {
        return self::part($date, 's');
    }

    /**
     * 0 = Sunday … 6 = Saturday, or 0 = Monday when the start day is "Monday".
     */
    public static function weekday(mixed $date, mixed $startDay = 'Sunday'): ?int
    {
        $date = Value::toDate($date);

        if ($date === null) {
            return null;
        }

        return ((int) $date->format('w') - self::weekStart($startDay) + 7) % 7;
    }

    public static function weeknum(mixed $date, mixed $startDay = 'Sunday'): ?int
    {
        $date = Value::toDate($date);

        return $date === null ? null : DateFormat::weekOfYear($date, self::weekStart($startDay));
    }

    public static function dateAdd(mixed $date, mixed $count, mixed $unit): ?CarbonImmutable
    {
        $date = Value::toDate($date);
        $count = Value::toNumber($count);
        $unit = self::unit($unit);

        if ($date === null) {
            return null;
        }

        $whole = (int) $count;

        return match ($unit) {
            'years' => $date->addYearsNoOverflow($whole),
            'quarters' => $date->addMonthsNoOverflow($whole * 3),
            'months' => $date->addMonthsNoOverflow($whole),
            'weeks' => $date->addDays($whole * 7)->addMicroseconds((int) round(($count - $whole) * self::MicrosecondsPer['weeks'])),
            'days' => $date->addDays($whole)->addMicroseconds((int) round(($count - $whole) * self::MicrosecondsPer['days'])),
            default => $date->addMicroseconds((int) round($count * self::MicrosecondsPer[$unit])),
        };
    }

    /**
     * date1 − date2 in whole units, truncated toward zero. Months, quarters and years go by the calendar.
     */
    public static function datetimeDiff(mixed $first, mixed $second, mixed $unit = 'seconds'): ?int
    {
        $first = Value::toDate($first);
        $second = Value::toDate($second);
        $unit = self::unit($unit);

        if ($first === null || $second === null) {
            return null;
        }

        return match ($unit) {
            'years' => intdiv(self::monthsBetween($first, $second), 12),
            'quarters' => intdiv(self::monthsBetween($first, $second), 3),
            'months' => self::monthsBetween($first, $second),
            'days', 'weeks' => intdiv(
                Value::microseconds($first) - Value::microseconds($second) + ($first->getOffset() - $second->getOffset()) * 1_000_000,
                self::MicrosecondsPer[$unit],
            ),
            default => intdiv(Value::microseconds($first) - Value::microseconds($second), self::MicrosecondsPer[$unit]),
        };
    }

    public static function datetimeFormat(mixed $date, mixed $format = null): ?string
    {
        $date = Value::toDate($date);

        if ($date === null) {
            return null;
        }

        if (Value::isBlank($format)) {
            return $date->utc()->format('Y-m-d\TH:i:s.v\Z');
        }

        return DateFormat::format($date, Value::toText($format));
    }

    public static function datetimeParse(mixed $text, mixed $format = null): ?CarbonImmutable
    {
        $value = Value::unwrap($text);

        if (Value::isBlank($value)) {
            return null;
        }

        if (Value::isBlank($format) || $value instanceof DateTimeImmutable) {
            return Value::toDate($value);
        }

        return DateFormat::parse(Value::toText($value), Value::toText($format), Value::timezone());
    }

    public static function datestr(mixed $date): ?string
    {
        return Value::toDate($date)?->format('Y-m-d');
    }

    public static function isBefore(mixed $first, mixed $second): bool
    {
        [$first, $second] = [Value::toDate($first), Value::toDate($second)];

        return $first !== null && $second !== null && Value::microseconds($first) < Value::microseconds($second);
    }

    public static function isAfter(mixed $first, mixed $second): bool
    {
        [$first, $second] = [Value::toDate($first), Value::toDate($second)];

        return $first !== null && $second !== null && Value::microseconds($first) > Value::microseconds($second);
    }

    public static function isSame(mixed $first, mixed $second, mixed $unit = null): bool
    {
        [$first, $second] = [Value::toDate($first), Value::toDate($second)];

        if ($first === null || $second === null) {
            return false;
        }

        if (Value::isBlank($unit)) {
            return Value::microseconds($first) === Value::microseconds($second);
        }

        $key = match (self::unit($unit)) {
            'years' => fn (CarbonImmutable $date): string => $date->format('Y'),
            'quarters' => fn (CarbonImmutable $date): string => $date->format('Y').'Q'.$date->quarter,
            'months' => fn (CarbonImmutable $date): string => $date->format('Y-m'),
            'weeks' => fn (CarbonImmutable $date): string => $date->subDays((int) $date->format('w'))->format('Y-m-d'),
            'days' => fn (CarbonImmutable $date): string => $date->format('Y-m-d'),
            'hours' => fn (CarbonImmutable $date): string => $date->format('Y-m-d H'),
            'minutes' => fn (CarbonImmutable $date): string => $date->format('Y-m-d H:i'),
            'seconds' => fn (CarbonImmutable $date): string => $date->format('Y-m-d H:i:s'),
            'milliseconds' => fn (CarbonImmutable $date): string => $date->format('Y-m-d H:i:s.v'),
        };

        return $key($first) === $key($second);
    }

    /**
     * The date a number of working days (Monday–Friday, not holidays) after (or before) the date.
     */
    public static function workday(mixed $date, mixed $days, mixed $holidays = null): ?CarbonImmutable
    {
        $date = Value::toDate($date);
        $days = Value::toInteger($days);
        $holidays = self::holidays($holidays);

        if ($date === null) {
            return null;
        }

        if (abs($days) > 1_000_000) {
            throw new FormulaError('WORKDAY can move at most 1,000,000 days');
        }

        $day = self::dayNumber($date);
        $step = $days < 0 ? -1 : 1;
        $remaining = abs($days);

        if ($holidays === [] && $remaining > 0) {
            // Weekend starts count from the nearest weekday behind them, so 5 working days is always a week.
            while (self::isWeekend($day)) {
                $day -= $step;
            }

            $day += intdiv($remaining, 5) * 7 * $step;
            $remaining %= 5;
        }

        while ($remaining > 0) {
            $day += $step;

            if (! self::isWeekend($day) && ! isset($holidays[$day])) {
                $remaining--;
            }
        }

        return self::fromDayNumber($day);
    }

    /**
     * Working days from the start to the end date, counting both; negative when the end is first.
     */
    public static function workdayDiff(mixed $start, mixed $end, mixed $holidays = null): ?int
    {
        $start = Value::toDate($start);
        $end = Value::toDate($end);
        $holidays = self::holidays($holidays);

        if ($start === null || $end === null) {
            return null;
        }

        $from = self::dayNumber($start);
        $to = self::dayNumber($end);
        $sign = 1;

        if ($to < $from) {
            [$from, $to, $sign] = [$to, $from, -1];
        }

        $total = $to - $from + 1;
        $count = intdiv($total, 7) * 5;

        for ($day = $from + intdiv($total, 7) * 7; $day <= $to; $day++) {
            if (! self::isWeekend($day)) {
                $count++;
            }
        }

        foreach (array_keys($holidays) as $holiday) {
            if ($holiday >= $from && $holiday <= $to && ! self::isWeekend($holiday)) {
                $count--;
            }
        }

        return $sign * $count;
    }

    public static function recordId(Scope $scope): mixed
    {
        return $scope->value('@id');
    }

    public static function createdTime(Scope $scope): ?CarbonImmutable
    {
        return Value::toDate($scope->value('@createdAt'));
    }

    public static function lastModifiedTime(Scope $scope): ?CarbonImmutable
    {
        return Value::toDate($scope->value('@updatedAt'));
    }

    /**
     * The canonical unit name. One-letter "M" is months and "m" is minutes; everything else ignores case.
     */
    public static function unit(mixed $unit): string
    {
        $text = trim(Value::toText($unit));

        if ($text === 'M') {
            return 'months';
        }

        if ($text === 'm') {
            return 'minutes';
        }

        return self::UnitAliases[strtolower($text)]
            ?? throw new FormulaError('Unknown unit "'.$text.'" (use years, quarters, months, weeks, days, hours, minutes, seconds or milliseconds)');
    }

    private static function part(mixed $date, string $format): ?int
    {
        $date = Value::toDate($date);

        return $date === null ? null : (int) $date->format($format);
    }

    private static function weekStart(mixed $startDay): int
    {
        $day = strtolower(trim(Value::toText($startDay)));

        return match ($day) {
            '', 'sunday' => 0,
            'monday' => 1,
            default => throw new FormulaError('The start day must be "Sunday" or "Monday"'),
        };
    }

    /**
     * months(first) − months(second), counting only full months.
     */
    private static function monthsBetween(CarbonImmutable $first, CarbonImmutable $second): int
    {
        $sign = 1;

        if (Value::microseconds($first) < Value::microseconds($second)) {
            [$first, $second, $sign] = [$second, $first, -1];
        }

        $months = ((int) $first->format('Y') - (int) $second->format('Y')) * 12 + (int) $first->format('n') - (int) $second->format('n');

        if ($months > 0 && Value::microseconds($second->addMonthsNoOverflow($months)) > Value::microseconds($first)) {
            $months--;
        }

        return $sign * $months;
    }

    /**
     * @return array<int, true> Day numbers of the holidays.
     */
    private static function holidays(mixed $holidays): array
    {
        if (Value::isBlank($holidays)) {
            return [];
        }

        $values = is_array($holidays) ? Value::flatten($holidays) : explode(',', Value::toText($holidays));
        $days = [];

        foreach ($values as $value) {
            $date = Value::toDate(is_string($value) ? trim($value) : $value);

            if ($date !== null) {
                $days[self::dayNumber($date)] = true;
            }
        }

        return $days;
    }

    /**
     * Days since 1970-01-01 for the date's calendar day.
     */
    private static function dayNumber(CarbonImmutable $date): int
    {
        return intdiv((new DateTimeImmutable($date->format('Y-m-d'), new DateTimeZone('UTC')))->getTimestamp(), 86_400);
    }

    private static function fromDayNumber(int $day): CarbonImmutable
    {
        $date = (new DateTimeImmutable('@'.($day * 86_400)))->format('Y-m-d');

        return CarbonImmutable::parse($date, Value::timezone())->startOfDay();
    }

    private static function isWeekend(int $day): bool
    {
        $weekday = (($day + 4) % 7 + 7) % 7;

        return $weekday === 0 || $weekday === 6;
    }
}
