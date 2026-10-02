<?php

namespace Tests\Unit\Tables;

use App\Tables\Formula\Formula;
use App\Tables\Formula\FormulaError;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('TABLE-004')]
class FormulaTest extends TestCase
{
    private string $timezone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        CarbonImmutable::setTestNow('2026-10-02 15:30:45');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        date_default_timezone_set($this->timezone);

        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private static function fields(): array
    {
        return [
            'num' => 5,
            'price' => 19.99,
            'numericText' => '7',
            'name' => 'Ada Lovelace',
            'empty' => null,
            'blankText' => '',
            'tags' => ['red', 'blue', 'red'],
            'single' => [3],
            'nested' => [[1, 2], [3, null]],
            'date' => new DateTimeImmutable('2026-01-31 00:00:00'),
            'meeting' => new DateTimeImmutable('2026-10-02 09:15:30'),
            'flag' => true,
            '@id' => 42,
            '@createdAt' => new DateTimeImmutable('2026-09-01 08:00:00'),
            '@updatedAt' => new DateTimeImmutable('2026-09-30 17:45:00'),
        ];
    }

    private function evaluate(string $formula, ?array $fields = null): mixed
    {
        $fields ??= self::fields();

        return Formula::compile($formula)->evaluate(fn (string $key): mixed => $fields[$key] ?? null);
    }

    private function assertFormulaError(string $formula, string $message): void
    {
        try {
            $this->evaluate($formula);
        } catch (FormulaError $error) {
            $this->assertSame($message, $error->getMessage());

            return;
        }

        $this->fail("Expected [{$formula}] to fail with: {$message}");
    }

    #[DataProvider('values')]
    public function test_it_evaluates_formulas(string $formula, mixed $expected): void
    {
        $this->assertSame($expected, $this->evaluate($formula));
    }

    /**
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function values(): array
    {
        return [
            // Literals
            'integer' => ['12', 12],
            'decimal' => ['1.5', 1.5],
            'leading dot' => ['.5', 0.5],
            'double quoted text' => ['"hi"', 'hi'],
            'single quoted text' => ["'hi'", 'hi'],
            'escapes' => ['"say \"hi\"\n\\\\ it\'s"', "say \"hi\"\n\\ it's"],
            'escaped single quote' => ["'it\\'s'", "it's"],
            'bare TRUE' => ['TRUE', true],
            'bare false' => ['false', false],

            // Operators and precedence
            'multiplication first' => ['1 + 2 * 3', 7],
            'parentheses' => ['(1 + 2) * 3', 9],
            'left to right' => ['10 - 4 - 3', 3],
            'division' => ['7 / 2', 3.5],
            'exact division stays whole' => ['6 / 3', 2],
            'unary minus' => ['-2 * 3', -6],
            'unary minus on a field' => ['-{num} + 1', -4],
            'double negation' => ['- -2', 2],
            'unary plus' => ['+2', 2],
            'join binds looser than plus' => ['1 + 2 & 3', '33'],
            'comparison binds loosest' => ['1 + 1 = 2', true],
            'comparison of joins' => ['"a" & "b" = "ab"', true],

            // Coercion
            'blank is zero in arithmetic' => ['{empty} + 1', 1],
            'empty text is zero' => ['{blankText} * 3', 0],
            'booleans are 1 and 0' => ['TRUE() + TRUE()', 2],
            'numeric text is a number' => ['{numericText} * 2', 14],
            'one-item list is its item' => ['{single} * 2', 6],
            'float noise hidden in text' => ['(0.1 + 0.2) & ""', '0.3'],
            'no trailing zeros' => ['1.50 & ""', '1.5'],
            'whole float shows no decimals' => ['(1.5 * 2) & ""', '3'],
            'large number in full' => ['(10000000 * 10000000) & ""', '100000000000000'],
            'small number without exponent' => ['(1 / 10000000) & ""', '0.0000001'],
            'booleans as text' => ['TRUE() & FALSE()', '10'],
            'blank as text' => ['"[" & {empty} & "]"', '[]'],
            'list as text' => ['{tags} & ""', 'red, blue, red'],
            'midnight date as text' => ['{date} & ""', '2026-01-31'],
            'date with time as text' => ['{meeting} & ""', '2026-10-02T09:15:30+00:00'],

            // Comparisons
            'numbers' => ['2 < 10', true],
            'numeric text against a number' => ['{numericText} = 7', true],
            'numeric text ordered as a number' => ['"10" > 9', true],
            'text is case-sensitive' => ['"a" = "A"', false],
            'text order' => ['"apple" < "banana"', true],
            'not equal' => ['1 != 2', true],
            'not equal alias' => ['1 <> 1', false],
            'less or equal' => ['2 <= 2', true],
            'greater or equal' => ['1 >= 2', false],
            'blank equals blank' => ['{empty} = ""', true],
            'blank equals BLANK()' => ['{blankText} = BLANK()', true],
            'blank list equals blank' => ['ARRAYCOMPACT({empty}) = BLANK()', true],
            'blank is not zero' => ['{empty} = 0', false],
            'value is not blank' => ['{name} = BLANK()', false],
            'blank not equal' => ['{num} != BLANK()', true],
            'dates by time' => ['{date} < {meeting}', true],
            'date against date text' => ['{date} = "2026-01-31"', true],
            'date after date text' => ['{meeting} > "2026-10-01"', true],
            'date against non-date text' => ['{date} = "soon"', false],
            'floats compare without noise' => ['0.1 + 0.2 = 0.3', true],

            // Logic
            'IF true' => ['IF({num} > 3, "big", "small")', 'big'],
            'IF false' => ['IF({num} > 9, "big", "small")', 'small'],
            'IF without else' => ['IF(0, "yes")', null],
            'IF truthy text' => ['IF({name}, 1, 2)', 1],
            'IF empty list is false' => ['IF(ARRAYCOMPACT(""), 1, 2)', 2],
            'IF skips the error branch' => ['IF(TRUE(), "ok", ERROR("never"))', 'ok'],
            'IF skips divide by zero' => ['IF(FALSE(), 1 / 0, "fine")', 'fine'],
            'SWITCH match' => ['SWITCH({num}, 1, "one", 5, "five", "other")', 'five'],
            'SWITCH default' => ['SWITCH(9, 1, "one", "other")', 'other'],
            'SWITCH no default' => ['SWITCH(9, 1, "one")', null],
            'SWITCH skips other results' => ['SWITCH(1, 1, "one", 2, ERROR("x"))', 'one'],
            'AND' => ['AND(1, "x", TRUE())', true],
            'AND false' => ['AND(1, 0)', false],
            'AND short-circuits' => ['AND(FALSE(), ERROR("x"))', false],
            'OR' => ['OR(0, "", 1)', true],
            'OR false' => ['OR(0, BLANK())', false],
            'OR short-circuits' => ['OR(TRUE(), ERROR("x"))', true],
            'XOR odd' => ['XOR(1, 0, 0)', true],
            'XOR even' => ['XOR(1, 1)', false],
            'NOT' => ['NOT(0)', true],
            'NOT text' => ['NOT("x")', false],
            'BLANK' => ['BLANK()', null],
            'ISERROR on error' => ['ISERROR(ERROR("x"))', true],
            'ISERROR on divide by zero' => ['ISERROR(1 / 0)', true],
            'ISERROR on value' => ['ISERROR(1)', false],
            'TRUE()' => ['TRUE()', true],
            'FALSE()' => ['FALSE()', false],
            'function names ignore case' => ['if(true, "y", "n")', 'y'],

            // Numbers
            'ROUND' => ['ROUND(3.14159, 2)', 3.14],
            'ROUND half up' => ['ROUND(2.5)', 3],
            'ROUND half away from zero' => ['ROUND(-2.5)', -3],
            'ROUND negative places' => ['ROUND(1250, -2)', 1300],
            'ROUND 1.005' => ['ROUND(1.005, 2)', 1.01],
            'ROUNDUP' => ['ROUNDUP(1.1)', 2],
            'ROUNDUP away from zero' => ['ROUNDUP(-1.1)', -2],
            'ROUNDUP places' => ['ROUNDUP(3.14159, 3)', 3.142],
            'ROUNDUP exact stays' => ['ROUNDUP(1.1, 1)', 1.1],
            'ROUNDUP negative places' => ['ROUNDUP(1201, -2)', 1300],
            'ROUNDDOWN' => ['ROUNDDOWN(1.9)', 1],
            'ROUNDDOWN toward zero' => ['ROUNDDOWN(-1.9)', -1],
            'ROUNDDOWN places' => ['ROUNDDOWN(3.14159, 2)', 3.14],
            'ROUNDDOWN negative places' => ['ROUNDDOWN(1299, -2)', 1200],
            'CEILING' => ['CEILING(4.2)', 5],
            'CEILING significance' => ['CEILING(23, 5)', 25],
            'CEILING decimal significance' => ['CEILING(2.31, 0.1)', 2.4],
            'FLOOR' => ['FLOOR(4.8)', 4],
            'FLOOR significance' => ['FLOOR(23, 5)', 20],
            'INT' => ['INT(4.8)', 4],
            'INT negative floors' => ['INT(-4.2)', -5],
            'ABS' => ['ABS(-3.5)', 3.5],
            'MOD' => ['MOD(10, 3)', 1],
            'MOD keeps the sign of the number' => ['MOD(-7, 3)', -1],
            'MOD decimals' => ['MOD(5.5, 2)', 1.5],
            'POWER' => ['POWER(2, 10)', 1024],
            'POWER fraction' => ['POWER(9, 0.5)', 3.0],
            'SQRT' => ['SQRT(16)', 4],
            'EXP' => ['ROUND(EXP(1), 5)', 2.71828],
            'LOG base 10' => ['LOG(1000)', 3],
            'LOG base 2' => ['LOG(8, 2)', 3],
            'EVEN' => ['EVEN(1.5)', 2],
            'EVEN negative' => ['EVEN(-3)', -4],
            'EVEN of even' => ['EVEN(2)', 2],
            'ODD' => ['ODD(2)', 3],
            'ODD of zero' => ['ODD(0)', 1],
            'ODD negative' => ['ODD(-1.5)', -3],
            'SUM' => ['SUM(1, 2, 3)', 6],
            'SUM flattens and ignores blanks' => ['SUM({nested}, {empty}, 4)', 10],
            'SUM of nothing' => ['SUM({empty})', 0],
            'AVERAGE' => ['AVERAGE(1, 2, {nested})', 1.8],
            'AVERAGE of nothing' => ['AVERAGE({empty})', null],
            'MIN' => ['MIN(3, {nested}, 7)', 1],
            'MAX' => ['MAX(3, {nested}, 7)', 7],
            'MIN of nothing' => ['MIN({empty})', null],
            'MAX of nothing' => ['MAX(BLANK())', null],
            'COUNT counts numbers' => ['COUNT(1, "2", {nested}, TRUE())', 4],
            'COUNTA counts non-empty' => ['COUNTA(1, "", {empty}, "x", {tags})', 5],
            'COUNTALL counts everything' => ['COUNTALL(1, "", {empty}, {nested})', 7],
            'VALUE currency' => ['VALUE("$1,200.50")', 1200.5],
            'VALUE percent' => ['VALUE("50%")', 0.5],
            'VALUE negative with spaces' => ['VALUE(" -12 ")', -12],
            'VALUE of text' => ['VALUE("abc")', null],
            'VALUE of number' => ['VALUE(5)', 5],

            // Text
            'CONCATENATE' => ['CONCATENATE("a", 1, TRUE(), {empty}, "b")', 'a11b'],
            'LEN' => ['LEN("héllo")', 5],
            'LEN of number' => ['LEN(1.5)', 3],
            'LOWER' => ['LOWER("ÀBC")', 'àbc'],
            'UPPER' => ['UPPER("àbc")', 'ÀBC'],
            'TRIM ends only' => ['TRIM("  a  b  ")', 'a  b'],
            'LEFT' => ['LEFT({name}, 3)', 'Ada'],
            'LEFT multibyte' => ['LEFT("ñandú", 2)', 'ña'],
            'LEFT more than length' => ['LEFT("ab", 5)', 'ab'],
            'RIGHT' => ['RIGHT({name}, 8)', 'Lovelace'],
            'RIGHT zero' => ['RIGHT("abc", 0)', ''],
            'MID' => ['MID({name}, 5, 4)', 'Love'],
            'FIND' => ['FIND("love", "I love love")', 3],
            'FIND from start' => ['FIND("love", "I love love", 4)', 8],
            'FIND is case-sensitive' => ['FIND("LOVE", "I love")', 0],
            'FIND not found' => ['FIND("x", "abc")', 0],
            'FIND multibyte' => ['FIND("ü", "Müller")', 2],
            'SEARCH ignores case' => ['SEARCH("LOVE", "I love")', 3],
            'SEARCH not found' => ['SEARCH("x", "abc")', null],
            'SUBSTITUTE all' => ['SUBSTITUTE("a-b-c", "-", "+")', 'a+b+c'],
            'SUBSTITUTE nth' => ['SUBSTITUTE("a-b-c-d", "-", "+", 2)', 'a-b+c-d'],
            'SUBSTITUTE missing nth' => ['SUBSTITUTE("a-b", "-", "+", 5)', 'a-b'],
            'SUBSTITUTE multibyte' => ['SUBSTITUTE("über über", "ü", "u", 2)', 'über uber'],
            'REPLACE' => ['REPLACE("Hello world", 7, 5, "there")', 'Hello there'],
            'REPLACE insert' => ['REPLACE("abc", 2, 0, "X")', 'aXbc'],
            'REPT' => ['REPT("ab", 3)', 'ababab'],
            'REPT zero' => ['REPT("ab", 0)', ''],
            'T of text' => ['T("x")', 'x'],
            'T of number' => ['T(5)', ''],
            'ENCODE_URL_COMPONENT' => ['ENCODE_URL_COMPONENT("a b&c=d/é!")', 'a%20b%26c%3Dd%2F%C3%A9!'],
            'REGEX_MATCH' => ['REGEX_MATCH("order-123", "\\\\d+")', true],
            'REGEX_MATCH no match' => ['REGEX_MATCH("abc", "^\\\\d")', false],
            'REGEX_MATCH unicode' => ['REGEX_MATCH("café", "^caf.$")', true],
            'REGEX_MATCH with delimiter in pattern' => ['REGEX_MATCH("a~b", "a~b")', true],
            'REGEX_MATCH with escaped delimiter' => ['REGEX_MATCH("a~b", "a\\\\~b")', true],
            'REGEX_EXTRACT' => ['REGEX_EXTRACT("order-123-x", "[0-9]+")', '123'],
            'REGEX_EXTRACT no match' => ['REGEX_EXTRACT("abc", "[0-9]+")', null],
            'REGEX_REPLACE' => ['REGEX_REPLACE("a1b22c", "[0-9]+", "#")', 'a#b#c'],
            'REGEX_REPLACE groups' => ['REGEX_REPLACE("Lovelace, Ada", "(\\\\w+), (\\\\w+)", "$2 $1")', 'Ada Lovelace'],

            // Dates
            'YEAR' => ['YEAR({meeting})', 2026],
            'MONTH' => ['MONTH({meeting})', 10],
            'DAY' => ['DAY({meeting})', 2],
            'HOUR' => ['HOUR({meeting})', 9],
            'MINUTE' => ['MINUTE({meeting})', 15],
            'SECOND' => ['SECOND({meeting})', 30],
            'date parts of text' => ['YEAR("2024-02-29")', 2024],
            'date part of blank' => ['YEAR({empty})', null],
            'WEEKDAY Friday' => ['WEEKDAY({meeting})', 5],
            'WEEKDAY Sunday' => ['WEEKDAY("2026-10-04")', 0],
            'WEEKDAY Monday start' => ['WEEKDAY("2026-10-04", "Monday")', 6],
            'WEEKNUM January 1st' => ['WEEKNUM("2026-01-01")', 1],
            'WEEKNUM first Sunday' => ['WEEKNUM("2026-01-04")', 2],
            'WEEKNUM' => ['WEEKNUM({meeting})', 40],
            'DATETIME_DIFF seconds by default' => ['DATETIME_DIFF("2026-10-02 10:00", "2026-10-02 09:00")', 3600],
            'DATETIME_DIFF days' => ['DATETIME_DIFF("2026-10-10", "2026-10-02", "days")', 8],
            'DATETIME_DIFF negative' => ['DATETIME_DIFF("2026-10-02", "2026-10-10", "d")', -8],
            'DATETIME_DIFF truncates' => ['DATETIME_DIFF("2026-10-02 23:59", "2026-10-02 00:00", "hours")', 23],
            'DATETIME_DIFF truncates negative' => ['DATETIME_DIFF("2026-10-02 00:00", "2026-10-02 23:59", "hours")', -23],
            'DATETIME_DIFF weeks' => ['DATETIME_DIFF("2026-10-20", "2026-10-02", "weeks")', 2],
            'DATETIME_DIFF minutes' => ['DATETIME_DIFF("2026-10-02 10:30", "2026-10-02 10:00", "m")', 30],
            'DATETIME_DIFF milliseconds' => ['DATETIME_DIFF("2026-10-02 10:00:01", "2026-10-02 10:00:00", "ms")', 1000],
            'DATETIME_DIFF months' => ['DATETIME_DIFF("2026-03-15", "2026-01-15", "months")', 2],
            'DATETIME_DIFF months not yet full' => ['DATETIME_DIFF("2026-03-14", "2026-01-15", "M")', 1],
            'DATETIME_DIFF months from month end' => ['DATETIME_DIFF("2026-02-28", {date}, "months")', 1],
            'DATETIME_DIFF months negative' => ['DATETIME_DIFF("2026-01-15", "2026-03-14", "months")', -1],
            'DATETIME_DIFF quarters' => ['DATETIME_DIFF("2026-10-01", "2026-01-01", "quarters")', 3],
            'DATETIME_DIFF years' => ['DATETIME_DIFF("2026-06-01", "2020-06-02", "years")', 5],
            'DATETIME_DIFF years exact' => ['DATETIME_DIFF("2026-06-02", "2020-06-02", "y")', 6],
            'DATETIME_DIFF years negative' => ['DATETIME_DIFF("2020-06-02", "2026-06-01", "years")', -5],
            'DATETIME_DIFF of blank' => ['DATETIME_DIFF({empty}, TODAY(), "days")', null],
            'DATETIME_FORMAT' => ['DATETIME_FORMAT({meeting}, "YYYY-MM-DD HH:mm:ss")', '2026-10-02 09:15:30'],
            'DATETIME_FORMAT names' => ['DATETIME_FORMAT({meeting}, "dddd, MMMM Do YYYY")', 'Friday, October 2nd 2026'],
            'DATETIME_FORMAT short' => ['DATETIME_FORMAT({meeting}, "ddd MMM D, YY h:mm a")', 'Fri Oct 2, 26 9:15 am'],
            'DATETIME_FORMAT twelve hour' => ['DATETIME_FORMAT("2026-10-02 21:05", "hh:mm A")', '09:05 PM'],
            'DATETIME_FORMAT single digits' => ['DATETIME_FORMAT("2026-03-04 05:06:07", "M/D H:m:s d")', '3/4 5:6:7 3'],
            'DATETIME_FORMAT literal' => ['DATETIME_FORMAT({meeting}, "[Week] w [of] YYYY")', 'Week 40 of 2026'],
            'DATETIME_FORMAT offset' => ['DATETIME_FORMAT({meeting}, "Z")', '+00:00'],
            'DATETIME_FORMAT default' => ['DATETIME_FORMAT({meeting})', '2026-10-02T09:15:30.000Z'],
            'DATESTR' => ['DATESTR({meeting})', '2026-10-02'],
            'IS_BEFORE' => ['IS_BEFORE({date}, {meeting})', true],
            'IS_AFTER' => ['IS_AFTER({date}, {meeting})', false],
            'IS_AFTER text dates' => ['IS_AFTER("2026-10-03", "2026-10-02")', true],
            'IS_SAME exact' => ['IS_SAME({meeting}, "2026-10-02 09:15:30")', true],
            'IS_SAME exact differs' => ['IS_SAME({meeting}, "2026-10-02")', false],
            'IS_SAME day' => ['IS_SAME({meeting}, "2026-10-02 23:00", "day")', true],
            'IS_SAME month' => ['IS_SAME({meeting}, "2026-10-31", "month")', true],
            'IS_SAME year differs' => ['IS_SAME({meeting}, "2025-10-02", "year")', false],
            'IS_SAME week' => ['IS_SAME("2026-09-27", "2026-10-03", "week")', true],
            'IS_SAME next week' => ['IS_SAME("2026-10-03", "2026-10-04", "week")', false],
            'IS_SAME hour' => ['IS_SAME({meeting}, "2026-10-02 09:59", "hour")', true],
            'IS_SAME minute differs' => ['IS_SAME({meeting}, "2026-10-02 09:16", "minute")', false],
            'IS_SAME second' => ['IS_SAME({meeting}, "2026-10-02 09:15:30", "second")', true],
            'WORKDAY_DIFF' => ['WORKDAY_DIFF("2026-10-01", "2026-10-06")', 4],
            'WORKDAY_DIFF same day' => ['WORKDAY_DIFF("2026-10-02", "2026-10-02")', 1],
            'WORKDAY_DIFF weekend only' => ['WORKDAY_DIFF("2026-10-03", "2026-10-04")', 0],
            'WORKDAY_DIFF backwards' => ['WORKDAY_DIFF("2026-10-06", "2026-10-01")', -4],
            'WORKDAY_DIFF long' => ['WORKDAY_DIFF("2026-01-01", "2026-12-31")', 261],
            'WORKDAY_DIFF holidays' => ['WORKDAY_DIFF("2026-10-01", "2026-10-06", "2026-10-02, 2026-10-03")', 3],
            'RECORD_ID' => ['RECORD_ID()', 42],
            'RECORD_ID in text' => ['"rec" & RECORD_ID()', 'rec42'],

            // Arrays
            'ARRAYJOIN' => ['ARRAYJOIN({tags})', 'red, blue, red'],
            'ARRAYJOIN separator' => ['ARRAYJOIN({tags}, " | ")', 'red | blue | red'],
            'ARRAYJOIN single value' => ['ARRAYJOIN("x", "-")', 'x'],
            'ARRAYUNIQUE' => ['ARRAYUNIQUE({tags})', ['red', 'blue']],
            'ARRAYUNIQUE keeps types apart' => ['ARRAYJOIN(ARRAYUNIQUE({nested}))', '1, 2, 3, '],
            'ARRAYCOMPACT' => ['ARRAYCOMPACT({nested})', [1, 2, 3]],
            'ARRAYFLATTEN' => ['ARRAYFLATTEN({nested})', [1, 2, 3, null]],
            'list passes through' => ['{tags}', ['red', 'blue', 'red']],
            'LEN of list' => ['LEN({tags})', 14],
        ];
    }

    #[DataProvider('dates')]
    public function test_it_evaluates_date_formulas(string $formula, string $expected): void
    {
        $result = $this->evaluate($formula);

        $this->assertInstanceOf(DateTimeImmutable::class, $result);
        $this->assertSame($expected, $result->format('Y-m-d H:i:s'));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function dates(): array
    {
        return [
            'TODAY' => ['TODAY()', '2026-10-02 00:00:00'],
            'NOW' => ['NOW()', '2026-10-02 15:30:45'],
            'DATEADD days' => ['DATEADD({meeting}, 10, "days")', '2026-10-12 09:15:30'],
            'DATEADD negative' => ['DATEADD({meeting}, -2, "d")', '2026-09-30 09:15:30'],
            'DATEADD weeks' => ['DATEADD({meeting}, 2, "weeks")', '2026-10-16 09:15:30'],
            'DATEADD months' => ['DATEADD({meeting}, 1, "M")', '2026-11-02 09:15:30'],
            'DATEADD month end' => ['DATEADD({date}, 1, "month")', '2026-02-28 00:00:00'],
            'DATEADD month end leap year' => ['DATEADD("2028-01-31", 1, "months")', '2028-02-29 00:00:00'],
            'DATEADD leap day plus a year' => ['DATEADD("2028-02-29", 1, "year")', '2029-02-28 00:00:00'],
            'DATEADD quarters' => ['DATEADD({date}, 1, "Q")', '2026-04-30 00:00:00'],
            'DATEADD years' => ['DATEADD({meeting}, 2, "y")', '2028-10-02 09:15:30'],
            'DATEADD hours' => ['DATEADD({meeting}, 15, "h")', '2026-10-03 00:15:30'],
            'DATEADD minutes' => ['DATEADD({meeting}, 45, "m")', '2026-10-02 10:00:30'],
            'DATEADD seconds' => ['DATEADD({meeting}, 30, "seconds")', '2026-10-02 09:16:00'],
            'DATEADD milliseconds' => ['DATEADD({meeting}, 1500, "ms")', '2026-10-02 09:15:31'],
            'DATEADD text date' => ['DATEADD("2026-12-31", 1, "day")', '2027-01-01 00:00:00'],
            'DATETIME_PARSE ISO' => ['DATETIME_PARSE("2026-10-02T14:30:00")', '2026-10-02 14:30:00'],
            'DATETIME_PARSE natural' => ['DATETIME_PARSE("October 2, 2026 2:30pm")', '2026-10-02 14:30:00'],
            'DATETIME_PARSE format' => ['DATETIME_PARSE("02/10/2026 14:30", "DD/MM/YYYY HH:mm")', '2026-10-02 14:30:00'],
            'DATETIME_PARSE date-only format' => ['DATETIME_PARSE("2 Oct 26", "D MMM YY")', '2026-10-02 00:00:00'],
            'DATETIME_PARSE twelve hour format' => ['DATETIME_PARSE("10.2.2026 9:05 PM", "M.D.YYYY h:mm A")', '2026-10-02 21:05:00'],
            'DATETIME_PARSE literal' => ['DATETIME_PARSE("Day 02 of 10, 2026", "[Day] DD [of] MM, YYYY")', '2026-10-02 00:00:00'],
            'WORKDAY' => ['WORKDAY("2026-10-02", 1)', '2026-10-05 00:00:00'],
            'WORKDAY two weeks' => ['WORKDAY("2026-10-01", 10)', '2026-10-15 00:00:00'],
            'WORKDAY from a weekend' => ['WORKDAY("2026-10-03", 5)', '2026-10-09 00:00:00'],
            'WORKDAY backwards' => ['WORKDAY("2026-10-05", -1)', '2026-10-02 00:00:00'],
            'WORKDAY backwards from a weekend' => ['WORKDAY("2026-10-04", -5)', '2026-09-28 00:00:00'],
            'WORKDAY zero' => ['WORKDAY("2026-10-02", 0)', '2026-10-02 00:00:00'],
            'WORKDAY holidays' => ['WORKDAY("2026-10-02", 2, "2026-10-05")', '2026-10-07 00:00:00'],
            'CREATED_TIME' => ['CREATED_TIME()', '2026-09-01 08:00:00'],
            'LAST_MODIFIED_TIME' => ['LAST_MODIFIED_TIME()', '2026-09-30 17:45:00'],
            'MIN of dates' => ['MIN({date}, {meeting})', '2026-01-31 00:00:00'],
            'MAX of dates' => ['MAX({date}, {meeting})', '2026-10-02 09:15:30'],
            'IF returns a date' => ['IF(TRUE(), {meeting})', '2026-10-02 09:15:30'],
        ];
    }

    #[DataProvider('compileErrors')]
    public function test_it_rejects_invalid_formulas(string $formula, string $message): void
    {
        try {
            Formula::compile($formula);
        } catch (FormulaError $error) {
            $this->assertSame($message, $error->getMessage());

            return;
        }

        $this->fail("Expected [{$formula}] not to compile");
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function compileErrors(): array
    {
        return [
            'empty' => ['   ', 'Formula is empty'],
            'unexpected parenthesis' => ['1 + 2) * 3', 'Unexpected ")" at character 6'],
            'character position counts characters' => ['"é" & )', 'Unexpected ")" at character 7'],
            'trailing operator' => ['1 +', 'Unexpected end of formula'],
            'missing close parenthesis' => ['(1 + 2', 'Unexpected end of formula'],
            'two values in a row' => ['1 2', 'Unexpected "2" at character 3'],
            'unknown character' => ['1 # 2', 'Unexpected "#" at character 3'],
            'percent is not an operator' => ['10 % 3', 'Unexpected "%" at character 4'],
            'double equals' => ['1 == 1', 'Unexpected "=" at character 4'],
            'unknown function' => ['FOO(1)', 'Unknown function FOO'],
            'unknown function any case' => ['foo_bar()', 'Unknown function FOO_BAR'],
            'bare name' => ['price * 2', 'Unknown name "price" at character 1. Put field names in braces, like {price}'],
            'IF arguments' => ['IF(1)', 'IF needs 2 or 3 arguments'],
            'too many IF arguments' => ['IF(1, 2, 3, 4)', 'IF needs 2 or 3 arguments'],
            'NOT arguments' => ['NOT()', 'NOT needs 1 argument'],
            'MID arguments' => ['MID("a", 1)', 'MID needs 3 arguments'],
            'SUM arguments' => ['SUM()', 'SUM needs at least 1 argument'],
            'SWITCH arguments' => ['SWITCH(1, 2)', 'SWITCH needs at least 3 arguments'],
            'TODAY arguments' => ['TODAY(1)', 'TODAY takes no arguments'],
            'nested argument errors' => ['IF(TRUE(), LEFT("a"), 1)', 'LEFT needs 2 arguments'],
            'empty argument' => ['IF(1,,2)', 'Unexpected "," at character 6'],
            'unclosed brace' => ['{price * 2', 'Missing "}" for the field at character 1'],
            'empty field' => ['{} + 1', 'Empty field reference at character 1'],
            'unclosed string' => ['"abc & 1', 'Missing closing quote for the text at character 1'],
            'unclosed single quote' => ["1 & 'abc", 'Missing closing quote for the text at character 5'],
            'too deep' => [str_repeat('(', 101).'1'.str_repeat(')', 101), 'Formula is nested too deeply (max 100 levels)'],
            'too deep calls' => [str_repeat('ABS(', 101).'1'.str_repeat(')', 101), 'Formula is nested too deeply (max 100 levels)'],
            'too long' => [str_repeat('1+', 5000).'1', 'Formula is too long (max 10,000 characters)'],
        ];
    }

    public function test_it_allows_deep_nesting_up_to_the_limit(): void
    {
        $formula = str_repeat('(', 100).'1'.str_repeat(')', 100);

        $this->assertSame(1, $this->evaluate($formula));
    }

    #[DataProvider('runtimeErrors')]
    public function test_it_reports_runtime_errors(string $formula, string $message): void
    {
        $this->assertFormulaError($formula, $message);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function runtimeErrors(): array
    {
        return [
            'divide by zero' => ['1 / 0', "Can't divide by zero"],
            'divide by blank' => ['{num} / {empty}', "Can't divide by zero"],
            'MOD by zero' => ['MOD(5, 0)', "Can't divide by zero"],
            'ERROR message' => ['ERROR("Missing price")', 'Missing price'],
            'ERROR default' => ['ERROR()', 'Error'],
            'ERROR in the branch taken' => ['IF(FALSE(), 1, ERROR("x"))', 'x'],
            'text in arithmetic' => ['{name} * 2', "Can't use text in a calculation"],
            'list in arithmetic' => ['{tags} + 1', "Can't use a list of values in a calculation"],
            'date in arithmetic' => ['{date} + 1', "Can't use a date in a calculation (use DATEADD or DATETIME_DIFF)"],
            'date minus date' => ['{meeting} - {date}', 'Use DATETIME_DIFF to subtract dates'],
            'SQRT of a negative' => ['SQRT(-1)', "Can't take the square root of a negative number"],
            'LOG of zero' => ['LOG(0)', 'LOG needs a number greater than 0'],
            'invalid pattern' => ['REGEX_MATCH("abc", "(")', 'Invalid pattern'],
            'empty pattern' => ['REGEX_MATCH("abc", "")', 'Invalid pattern'],
            'catastrophic pattern' => ['REGEX_MATCH(REPT("a", 30) & "b", "^(a+)+$")', 'Pattern is too complex for this text'],
            'REPT too long' => ['REPT("abc", 50000)', 'REPT would make text longer than 100,000 characters'],
            'negative count' => ['LEFT("abc", -1)', 'LEFT needs a count of 0 or more'],
            'bad start' => ['MID("abc", 0, 1)', 'MID needs a start position of 1 or more'],
            'not a date' => ['YEAR("someday")', 'Not a date: "someday"'],
            'number is not a date' => ['DATEADD(5, 1, "days")', 'Not a date: "5"'],
            'unknown unit' => ['DATEADD({date}, 1, "fortnights")', 'Unknown unit "fortnights" (use years, quarters, months, weeks, days, hours, minutes, seconds or milliseconds)'],
            'unparsable date' => ['DATETIME_PARSE("next blursday")', 'Not a date: "next blursday"'],
            'wrong format' => ['DATETIME_PARSE("2026-10-02", "DD/MM/YYYY")', 'Can\'t read "2026-10-02" as a date in the format "DD/MM/YYYY"'],
            'overflowing date' => ['DATETIME_PARSE("31/02/2026", "DD/MM/YYYY")', 'Can\'t read "31/02/2026" as a date in the format "DD/MM/YYYY"'],
            'too big' => ['POWER(10, 400)', 'Number is too big'],
        ];
    }

    public function test_iserror_catches_runtime_errors(): void
    {
        $this->assertTrue($this->evaluate('ISERROR(REGEX_MATCH("a", "("))'));
        $this->assertSame('fallback', $this->evaluate('IF(ISERROR({name} * 2), "fallback", {name} * 2)'));
    }

    public function test_it_compiles_once_and_evaluates_per_record(): void
    {
        $formula = Formula::compile('IF({qty} > 0, {qty} * {price}, "none")');

        $this->assertSame(30, $formula->evaluate(fn (string $key): mixed => ['qty' => 3, 'price' => 10][$key]));
        $this->assertSame('none', $formula->evaluate(fn (string $key): mixed => ['qty' => 0, 'price' => 10][$key]));
        $this->assertSame(['qty', 'price'], $formula->references());
    }

    public function test_it_only_reads_fields_on_the_branch_taken(): void
    {
        $read = [];
        $formula = Formula::compile('IF({a}, {b}, {c})');

        $formula->evaluate(function (string $key) use (&$read): mixed {
            $read[] = $key;

            return 1;
        });

        $this->assertSame(['a', 'b'], $read);
    }

    public function test_it_accepts_any_callable(): void
    {
        $fields = new class
        {
            public function __invoke(string $key): int
            {
                return 2;
            }
        };

        $this->assertSame(4, Formula::compile('{x} * {y}')->evaluate($fields));
    }

    public function test_mutable_dates_come_back_immutable(): void
    {
        $result = $this->evaluate('{when}', ['when' => new \DateTime('2026-10-02 12:00:00')]);

        $this->assertInstanceOf(DateTimeImmutable::class, $result);
        $this->assertSame('2026-10-02 12:00:00', $result->format('Y-m-d H:i:s'));
    }

    public function test_dates_use_the_default_timezone_without_an_app(): void
    {
        date_default_timezone_set('America/New_York');
        CarbonImmutable::setTestNow(new DateTimeImmutable('2026-10-02 02:00:00', new \DateTimeZone('UTC')));

        $today = $this->evaluate('TODAY()');
        $utcMidnight = new DateTimeImmutable('2026-10-02 00:00:00', new \DateTimeZone('UTC'));

        $this->assertInstanceOf(DateTimeInterface::class, $today);
        $this->assertSame('2026-10-01 00:00:00 America/New_York', $today->format('Y-m-d H:i:s e'));
        $this->assertSame('2026-10-01T20:00:00-04:00', $this->evaluate('{d} & ""', ['d' => $utcMidnight]));
        $this->assertSame(1, $this->evaluate('DAY({d})', ['d' => $utcMidnight]));
    }

    public function test_it_lists_every_function_for_autocomplete(): void
    {
        $functions = Formula::functions();
        $names = array_column($functions, 'name');

        foreach (['IF', 'SWITCH', 'ROUND', 'VALUE', 'REGEX_REPLACE', 'DATEADD', 'DATETIME_DIFF', 'WORKDAY_DIFF', 'RECORD_ID', 'ARRAYFLATTEN'] as $name) {
            $this->assertContains($name, $names);
        }

        $this->assertCount(count(array_unique($names)), $names);

        foreach ($functions as $function) {
            $this->assertSame(['name', 'signature', 'description', 'category'], array_keys($function));
            $this->assertStringStartsWith($function['name'].'(', $function['signature']);
            $this->assertNotSame('', $function['description']);
        }

        $dateAdd = $functions[array_search('DATEADD', $names, true)];
        $this->assertSame('DATEADD(date, count, unit)', $dateAdd['signature']);
        $this->assertSame('Date', $dateAdd['category']);
    }

    public function test_every_listed_function_compiles(): void
    {
        foreach (Formula::functions() as $function) {
            $compiles = false;

            for ($count = 0; $count <= 4 && ! $compiles; $count++) {
                try {
                    Formula::compile($function['name'].'('.implode(', ', array_fill(0, $count, '1')).')');
                    $compiles = true;
                } catch (FormulaError) {
                    // Try the next argument count.
                }
            }

            $this->assertTrue($compiles, $function['name'].' should compile');
        }
    }

    public function test_names_to_keys(): void
    {
        $keys = ['Deal value' => 'value', 'Owner' => 'cf_12', 'owner' => 'cf_99'];

        $this->assertSame('{value} * 2', Formula::namesToKeys('{Deal value} * 2', $keys));
        $this->assertSame('{cf_12} & {cf_99}', Formula::namesToKeys('{Owner} & {owner}', $keys), 'exact match wins');
        $this->assertSame('{value}', Formula::namesToKeys('{DEAL VALUE}', $keys), 'then case-insensitive');
        $this->assertSame('{value}', Formula::namesToKeys('{ Deal value }', $keys));
        $this->assertSame(
            '{value} & "{Deal value}" & \'{Owner}\' & "say \"{Owner}\""',
            Formula::namesToKeys('{Deal value} & "{Deal value}" & \'{Owner}\' & "say \"{Owner}\""', $keys),
        );
    }

    public function test_names_to_keys_rejects_unknown_names(): void
    {
        $this->expectException(FormulaError::class);
        $this->expectExceptionMessage('Unknown field {Foo}');

        Formula::namesToKeys('{Deal value} + {Foo}', ['Deal value' => 'value']);
    }

    public function test_keys_to_names(): void
    {
        $names = ['value' => 'Deal value', 'cf_12' => 'Owner'];

        $this->assertSame('{Deal value} * 2', Formula::keysToNames('{value} * 2', $names));
        $this->assertSame('{Owner} & {Deleted field}', Formula::keysToNames('{cf_12} & {cf_7}', $names));
        $this->assertSame('{Deal value} & "{value}" & \'{cf_12}\'', Formula::keysToNames('{value} & "{value}" & \'{cf_12}\'', $names));
    }

    public function test_names_and_keys_round_trip(): void
    {
        $source = 'IF({Deal value} > 100, "big {deal}", {Owner})';
        $keys = Formula::namesToKeys($source, ['Deal value' => 'value', 'Owner' => 'cf_12']);

        $this->assertSame('IF({value} > 100, "big {deal}", {cf_12})', $keys);
        $this->assertSame($source, Formula::keysToNames($keys, ['value' => 'Deal value', 'cf_12' => 'Owner']));
    }

    public function test_references(): void
    {
        $this->assertSame(['value', 'cf_12'], Formula::references('{value} * {cf_12} + {value} & "{not_a_field}"'));
        $this->assertSame([], Formula::references('1 + 2'));
        $this->assertSame(['a', 'b'], Formula::compile('{a} + {b} * {a}')->references());
        $this->assertSame(['12'], Formula::references('{12}'));
    }
}
