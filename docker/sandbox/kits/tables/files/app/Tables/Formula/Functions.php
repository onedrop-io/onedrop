<?php

namespace App\Tables\Formula;

use Closure;

/**
 * Every formula function: argument counts, the PHP that runs it, and the help shown in the editor.
 *
 * @internal
 *
 * @phpstan-type Definition array{min: int, max: ?int, lazy: bool, handler: array{0: class-string, 1: string}, signature: string, description: string, category: string}
 */
final class Functions
{
    /** @var array<string, Definition>|null */
    private static ?array $definitions = null;

    /** @var array<string, Closure> */
    private static array $handlers = [];

    /**
     * @return array<string, Definition>
     */
    public static function definitions(): array
    {
        return self::$definitions ??= self::build();
    }

    /**
     * @return Definition|null
     */
    public static function find(string $name): ?array
    {
        return self::definitions()[$name] ?? null;
    }

    public static function handler(string $name): Closure
    {
        return self::$handlers[$name] ??= Closure::fromCallable(self::definitions()[$name]['handler']);
    }

    public static function checkArgumentCount(string $name, int $count): void
    {
        $definition = self::definitions()[$name];
        $min = $definition['min'];
        $max = $definition['max'];

        if ($count >= $min && ($max === null || $count <= $max)) {
            return;
        }

        $plural = fn (int $n): string => $n === 1 ? '1 argument' : $n.' arguments';

        throw new FormulaError(match (true) {
            $max === 0 => $name.' takes no arguments',
            $max === null => $name.' needs at least '.$plural($min),
            $min === $max => $name.' needs '.$plural($min),
            $max === $min + 1 => $name.' needs '.$min.' or '.$plural($max),
            default => $name.' needs '.$min.' to '.$plural($max),
        });
    }

    /**
     * @return array<string, Definition>
     */
    private static function build(): array
    {
        $definitions = [];

        $add = function (string $category, string $class, array $functions) use (&$definitions): void {
            foreach ($functions as $name => [$method, $min, $max, $signature, $description]) {
                $definitions[$name] = [
                    'min' => $min,
                    'max' => $max,
                    'lazy' => in_array($name, ['IF', 'SWITCH', 'AND', 'OR', 'ISERROR', 'RECORD_ID', 'CREATED_TIME', 'LAST_MODIFIED_TIME'], true),
                    'handler' => [$class, $method],
                    'signature' => $signature,
                    'description' => $description,
                    'category' => $category,
                ];
            }
        };

        $add('Logic', LogicFunctions::class, [
            'IF' => ['ifThen', 2, 3, 'IF(condition, value if true, value if false)', 'Returns one value if a condition is true and another if it is false.'],
            'SWITCH' => ['switchCase', 3, null, 'SWITCH(expression, pattern, result, …, default)', 'Compares a value to a list of patterns and returns the result for the first match.'],
            'AND' => ['allOf', 1, null, 'AND(condition, …)', 'True if every argument is true.'],
            'OR' => ['anyOf', 1, null, 'OR(condition, …)', 'True if any argument is true.'],
            'XOR' => ['oddOf', 1, null, 'XOR(condition, …)', 'True if an odd number of arguments are true.'],
            'NOT' => ['not', 1, 1, 'NOT(condition)', 'Turns true into false and false into true.'],
            'BLANK' => ['blank', 0, 0, 'BLANK()', 'Returns an empty value.'],
            'ERROR' => ['error', 0, 1, 'ERROR(message)', 'Stops with an error, showing the message.'],
            'ISERROR' => ['isError', 1, 1, 'ISERROR(expression)', 'True if the expression causes an error.'],
            'TRUE' => ['true', 0, 0, 'TRUE()', 'The logical value true.'],
            'FALSE' => ['false', 0, 0, 'FALSE()', 'The logical value false.'],
        ]);

        $add('Number', NumberFunctions::class, [
            'ROUND' => ['round', 1, 2, 'ROUND(number, places)', 'Rounds to a number of decimal places, halves away from zero.'],
            'ROUNDUP' => ['roundUp', 1, 2, 'ROUNDUP(number, places)', 'Rounds away from zero to a number of decimal places.'],
            'ROUNDDOWN' => ['roundDown', 1, 2, 'ROUNDDOWN(number, places)', 'Rounds toward zero to a number of decimal places.'],
            'CEILING' => ['ceiling', 1, 2, 'CEILING(number, significance)', 'Rounds up to the nearest multiple of significance (default 1).'],
            'FLOOR' => ['floor', 1, 2, 'FLOOR(number, significance)', 'Rounds down to the nearest multiple of significance (default 1).'],
            'INT' => ['int', 1, 1, 'INT(number)', 'Rounds down to the nearest whole number.'],
            'ABS' => ['abs', 1, 1, 'ABS(number)', 'The number without its sign.'],
            'MOD' => ['mod', 2, 2, 'MOD(number, divisor)', 'The remainder after dividing.'],
            'POWER' => ['power', 2, 2, 'POWER(base, power)', 'Raises a number to a power.'],
            'SQRT' => ['sqrt', 1, 1, 'SQRT(number)', 'The square root of a number.'],
            'EXP' => ['exp', 1, 1, 'EXP(power)', 'e raised to a power.'],
            'LOG' => ['log', 1, 2, 'LOG(number, base)', 'The logarithm of a number (base 10 unless given).'],
            'EVEN' => ['even', 1, 1, 'EVEN(number)', 'Rounds away from zero to the nearest even number.'],
            'ODD' => ['odd', 1, 1, 'ODD(number)', 'Rounds away from zero to the nearest odd number.'],
            'SUM' => ['sum', 1, null, 'SUM(number, …)', 'Adds up the numbers.'],
            'AVERAGE' => ['average', 1, null, 'AVERAGE(number, …)', 'The average of the numbers.'],
            'MIN' => ['min', 1, null, 'MIN(number, …)', 'The smallest number (or earliest date).'],
            'MAX' => ['max', 1, null, 'MAX(number, …)', 'The largest number (or latest date).'],
            'COUNT' => ['count', 1, null, 'COUNT(value, …)', 'How many of the values are numbers.'],
            'COUNTA' => ['countNonEmpty', 1, null, 'COUNTA(value, …)', 'How many of the values are not empty.'],
            'COUNTALL' => ['countAll', 1, null, 'COUNTALL(value, …)', 'How many values there are, empty ones included.'],
            'VALUE' => ['value', 1, 1, 'VALUE(text)', 'Reads a number from text, like "$1,200.50" or "50%".'],
        ]);

        $add('Text', TextFunctions::class, [
            'CONCATENATE' => ['concatenate', 1, null, 'CONCATENATE(text, …)', 'Joins the values into one piece of text.'],
            'LEN' => ['len', 1, 1, 'LEN(text)', 'The number of characters in the text.'],
            'LOWER' => ['lower', 1, 1, 'LOWER(text)', 'The text in lowercase.'],
            'UPPER' => ['upper', 1, 1, 'UPPER(text)', 'The text in uppercase.'],
            'TRIM' => ['trim', 1, 1, 'TRIM(text)', 'Removes spaces from the start and end of the text.'],
            'LEFT' => ['left', 2, 2, 'LEFT(text, count)', 'The first characters of the text.'],
            'RIGHT' => ['right', 2, 2, 'RIGHT(text, count)', 'The last characters of the text.'],
            'MID' => ['mid', 3, 3, 'MID(text, start, count)', 'Characters from the middle of the text, starting at position start (1 is the first).'],
            'FIND' => ['find', 2, 3, 'FIND(search for, text, start)', 'Where the search text first appears (1 is the first character), or 0. Case-sensitive.'],
            'SEARCH' => ['search', 2, 3, 'SEARCH(search for, text, start)', 'Where the search text first appears, ignoring case, or empty if it isn\'t there.'],
            'SUBSTITUTE' => ['substitute', 3, 4, 'SUBSTITUTE(text, old, new, occurrence)', 'Replaces old text with new text, everywhere or only the given occurrence.'],
            'REPLACE' => ['replace', 4, 4, 'REPLACE(text, start, count, new)', 'Replaces count characters starting at position start with new text.'],
            'REPT' => ['rept', 2, 2, 'REPT(text, times)', 'Repeats the text a number of times.'],
            'T' => ['t', 1, 1, 'T(value)', 'The value if it is text, otherwise empty.'],
            'ENCODE_URL_COMPONENT' => ['encodeUrlComponent', 1, 1, 'ENCODE_URL_COMPONENT(text)', 'Encodes the text for use in a URL.'],
            'REGEX_MATCH' => ['regexMatch', 2, 2, 'REGEX_MATCH(text, pattern)', 'True if the text matches the regular expression.'],
            'REGEX_EXTRACT' => ['regexExtract', 2, 2, 'REGEX_EXTRACT(text, pattern)', 'The first part of the text that matches the regular expression.'],
            'REGEX_REPLACE' => ['regexReplace', 3, 3, 'REGEX_REPLACE(text, pattern, replacement)', 'Replaces every match of the regular expression.'],
        ]);

        $add('Date', DateFunctions::class, [
            'TODAY' => ['today', 0, 0, 'TODAY()', 'Today\'s date, at midnight.'],
            'NOW' => ['now', 0, 0, 'NOW()', 'The current date and time.'],
            'YEAR' => ['year', 1, 1, 'YEAR(date)', 'The year of a date.'],
            'MONTH' => ['month', 1, 1, 'MONTH(date)', 'The month of a date, 1 to 12.'],
            'DAY' => ['day', 1, 1, 'DAY(date)', 'The day of the month, 1 to 31.'],
            'HOUR' => ['hour', 1, 1, 'HOUR(date)', 'The hour of a date, 0 to 23.'],
            'MINUTE' => ['minute', 1, 1, 'MINUTE(date)', 'The minute of a date, 0 to 59.'],
            'SECOND' => ['second', 1, 1, 'SECOND(date)', 'The second of a date, 0 to 59.'],
            'WEEKDAY' => ['weekday', 1, 2, 'WEEKDAY(date, start day)', 'The day of the week, 0 (Sunday) to 6 (Saturday).'],
            'WEEKNUM' => ['weeknum', 1, 2, 'WEEKNUM(date, start day)', 'The week of the year, with weeks starting on Sunday.'],
            'DATEADD' => ['dateAdd', 3, 3, 'DATEADD(date, count, unit)', 'Adds a number of units (days, months…) to a date.'],
            'DATETIME_DIFF' => ['datetimeDiff', 2, 3, 'DATETIME_DIFF(date1, date2, unit)', 'The time from date2 to date1 in whole units (seconds unless given).'],
            'DATETIME_FORMAT' => ['datetimeFormat', 1, 2, 'DATETIME_FORMAT(date, format)', 'Shows a date as text, like DATETIME_FORMAT(date, "MMMM D, YYYY").'],
            'DATETIME_PARSE' => ['datetimeParse', 1, 2, 'DATETIME_PARSE(text, format)', 'Reads a date from text, optionally in a given format.'],
            'DATESTR' => ['datestr', 1, 1, 'DATESTR(date)', 'The date as YYYY-MM-DD text.'],
            'IS_BEFORE' => ['isBefore', 2, 2, 'IS_BEFORE(date1, date2)', 'True if date1 is before date2.'],
            'IS_AFTER' => ['isAfter', 2, 2, 'IS_AFTER(date1, date2)', 'True if date1 is after date2.'],
            'IS_SAME' => ['isSame', 2, 3, 'IS_SAME(date1, date2, unit)', 'True if the dates are the same, optionally only down to a unit like "day".'],
            'WORKDAY' => ['workday', 2, 3, 'WORKDAY(date, days, holidays)', 'The date a number of working days (Monday to Friday) away.'],
            'WORKDAY_DIFF' => ['workdayDiff', 2, 3, 'WORKDAY_DIFF(start date, end date, holidays)', 'The number of working days between two dates, counting both.'],
        ]);

        $add('Record', DateFunctions::class, [
            'RECORD_ID' => ['recordId', 0, 0, 'RECORD_ID()', 'The ID of the record.'],
            'CREATED_TIME' => ['createdTime', 0, 0, 'CREATED_TIME()', 'When the record was created.'],
            'LAST_MODIFIED_TIME' => ['lastModifiedTime', 0, 0, 'LAST_MODIFIED_TIME()', 'When the record was last changed.'],
        ]);

        $add('Array', ArrayFunctions::class, [
            'ARRAYJOIN' => ['join', 1, 2, 'ARRAYJOIN(values, separator)', 'Joins a list of values into text (", " between them unless given).'],
            'ARRAYUNIQUE' => ['unique', 1, 1, 'ARRAYUNIQUE(values)', 'The list without duplicates.'],
            'ARRAYCOMPACT' => ['compact', 1, 1, 'ARRAYCOMPACT(values)', 'The list without empty values.'],
            'ARRAYFLATTEN' => ['flatten', 1, 1, 'ARRAYFLATTEN(values)', 'Turns a list of lists into one list.'],
        ]);

        return $definitions;
    }
}
