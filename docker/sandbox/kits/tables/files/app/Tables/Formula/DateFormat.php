<?php

namespace App\Tables\Formula;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * moment.js-style date format tokens (YYYY-MM-DD, h:mm A, [literal]…) for DATETIME_FORMAT and DATETIME_PARSE.
 *
 * @internal
 */
final class DateFormat
{
    private const Tokens = '/\[([^\]]*)\]|YYYY|YY|Q|MMMM|MMM|MM|M|Do|DDDD|DDD|DD|D|dddd|ddd|dd|d|WW|W|ww|w|HH|H|hh|h|mm|m|ss|s|SSS|SS|S|A|a|ZZ|Z|X|x/';

    /**
     * Tokens DATETIME_PARSE understands, as PHP createFromFormat() characters.
     */
    private const ParseTokens = [
        'YYYY' => 'Y', 'YY' => 'y',
        'MMMM' => 'F', 'MMM' => 'M', 'MM' => 'm', 'M' => 'n',
        'Do' => 'jS', 'DD' => 'd', 'D' => 'j',
        'dddd' => 'l', 'ddd' => 'D',
        'HH' => 'H', 'H' => 'G', 'hh' => 'h', 'h' => 'g',
        'mm' => 'i', 'm' => 'i', 'ss' => 's', 's' => 's', 'SSS' => 'v',
        'A' => 'A', 'a' => 'a', 'ZZ' => 'O', 'Z' => 'P', 'X' => 'U',
    ];

    public static function format(CarbonImmutable $date, string $format): string
    {
        return (string) preg_replace_callback(self::Tokens, function (array $match) use ($date): string {
            if (isset($match[1]) && $match[0][0] === '[') {
                return $match[1];
            }

            return match ($match[0]) {
                'YYYY' => $date->format('Y'),
                'YY' => $date->format('y'),
                'Q' => (string) $date->quarter,
                'MMMM' => $date->format('F'),
                'MMM' => $date->format('M'),
                'MM' => $date->format('m'),
                'M' => $date->format('n'),
                'Do' => $date->format('jS'),
                'DDDD' => str_pad((string) ($date->dayOfYear), 3, '0', STR_PAD_LEFT),
                'DDD' => (string) $date->dayOfYear,
                'DD' => $date->format('d'),
                'D' => $date->format('j'),
                'dddd' => $date->format('l'),
                'ddd' => $date->format('D'),
                'dd' => substr($date->format('D'), 0, 2),
                'd' => $date->format('w'),
                'WW' => $date->format('W'),
                'W' => (string) (int) $date->format('W'),
                'ww' => str_pad((string) self::weekOfYear($date, 0), 2, '0', STR_PAD_LEFT),
                'w' => (string) self::weekOfYear($date, 0),
                'HH' => $date->format('H'),
                'H' => $date->format('G'),
                'hh' => $date->format('h'),
                'h' => $date->format('g'),
                'mm' => $date->format('i'),
                'm' => (string) (int) $date->format('i'),
                'ss' => $date->format('s'),
                's' => (string) (int) $date->format('s'),
                'SSS' => $date->format('v'),
                'SS' => substr($date->format('v'), 0, 2),
                'S' => substr($date->format('v'), 0, 1),
                'A' => $date->format('A'),
                'a' => $date->format('a'),
                'ZZ' => $date->format('O'),
                'Z' => $date->format('P'),
                'X' => $date->format('U'),
                'x' => (string) intdiv(Value::microseconds($date), 1000),
            };
        }, $format);
    }

    public static function parse(string $text, string $format, string $timezone): CarbonImmutable
    {
        $phpFormat = '!';
        $offset = 0;

        preg_match_all(self::Tokens, $format, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($matches as $match) {
            [$token, $position] = $match[0];
            $phpFormat .= self::escape(substr($format, $offset, $position - $offset));
            $offset = $position + strlen($token);

            if ($token[0] === '[') {
                $phpFormat .= self::escape($match[1][0]);

                continue;
            }

            if (! isset(self::ParseTokens[$token])) {
                throw new FormulaError('DATETIME_PARSE can\'t read the "'.$token.'" format');
            }

            $phpFormat .= self::ParseTokens[$token];
        }

        $phpFormat .= self::escape(substr($format, $offset));

        try {
            $date = DateTimeImmutable::createFromFormat($phpFormat, trim($text), new DateTimeZone($timezone));
        } catch (Throwable) {
            $date = false;
        }

        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new FormulaError('Can\'t read "'.mb_strimwidth($text, 0, 40, '…').'" as a date in the format "'.$format.'"');
        }

        return Value::inTimezone($date);
    }

    /**
     * Week of the year, where week 1 holds January 1st. $weekStart: 0 = Sunday, 1 = Monday.
     */
    public static function weekOfYear(CarbonImmutable $date, int $weekStart): int
    {
        $januaryFirst = $date->startOfYear();
        $firstWeekday = ((int) $januaryFirst->format('w') - $weekStart + 7) % 7;

        return intdiv((int) $date->format('z') + $firstWeekday, 7) + 1;
    }

    private static function escape(string $literal): string
    {
        $escaped = '';

        foreach (mb_str_split($literal) as $character) {
            $escaped .= $character === ' ' ? ' ' : '\\'.$character;
        }

        return $escaped;
    }
}
