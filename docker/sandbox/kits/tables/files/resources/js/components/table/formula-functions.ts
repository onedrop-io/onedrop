/**
 * The formula functions, with the help shown while typing a formula. The server (app/Tables/Formula/Functions.php)
 * has the same list and runs them; keep both in step.
 */

export type FormulaCategory =
    | 'Logic'
    | 'Number'
    | 'Text'
    | 'Date'
    | 'Record'
    | 'Array';

export interface FormulaFunction {
    name: string;
    signature: string;
    description: string;
    category: FormulaCategory;
}

function group(
    category: FormulaCategory,
    functions: [name: string, signature: string, description: string][],
): FormulaFunction[] {
    return functions.map(([name, signature, description]) => ({
        name,
        signature,
        description,
        category,
    }));
}

export const FORMULA_FUNCTIONS: FormulaFunction[] = [
    ...group('Logic', [
        [
            'IF',
            'IF(condition, value if true, value if false)',
            'Returns one value if a condition is true and another if it is false.',
        ],
        [
            'SWITCH',
            'SWITCH(expression, pattern, result, …, default)',
            'Compares a value to a list of patterns and returns the result for the first match.',
        ],
        ['AND', 'AND(condition, …)', 'True if every argument is true.'],
        ['OR', 'OR(condition, …)', 'True if any argument is true.'],
        [
            'XOR',
            'XOR(condition, …)',
            'True if an odd number of arguments are true.',
        ],
        ['NOT', 'NOT(condition)', 'Turns true into false and false into true.'],
        ['BLANK', 'BLANK()', 'Returns an empty value.'],
        [
            'ERROR',
            'ERROR(message)',
            'Stops with an error, showing the message.',
        ],
        [
            'ISERROR',
            'ISERROR(expression)',
            'True if the expression causes an error.',
        ],
        ['TRUE', 'TRUE()', 'The logical value true.'],
        ['FALSE', 'FALSE()', 'The logical value false.'],
    ]),
    ...group('Number', [
        [
            'ROUND',
            'ROUND(number, places)',
            'Rounds to a number of decimal places, halves away from zero.',
        ],
        [
            'ROUNDUP',
            'ROUNDUP(number, places)',
            'Rounds away from zero to a number of decimal places.',
        ],
        [
            'ROUNDDOWN',
            'ROUNDDOWN(number, places)',
            'Rounds toward zero to a number of decimal places.',
        ],
        [
            'CEILING',
            'CEILING(number, significance)',
            'Rounds up to the nearest multiple of significance (default 1).',
        ],
        [
            'FLOOR',
            'FLOOR(number, significance)',
            'Rounds down to the nearest multiple of significance (default 1).',
        ],
        ['INT', 'INT(number)', 'Rounds down to the nearest whole number.'],
        ['ABS', 'ABS(number)', 'The number without its sign.'],
        ['MOD', 'MOD(number, divisor)', 'The remainder after dividing.'],
        ['POWER', 'POWER(base, power)', 'Raises a number to a power.'],
        ['SQRT', 'SQRT(number)', 'The square root of a number.'],
        ['EXP', 'EXP(power)', 'e raised to a power.'],
        [
            'LOG',
            'LOG(number, base)',
            'The logarithm of a number (base 10 unless given).',
        ],
        [
            'EVEN',
            'EVEN(number)',
            'Rounds away from zero to the nearest even number.',
        ],
        [
            'ODD',
            'ODD(number)',
            'Rounds away from zero to the nearest odd number.',
        ],
        ['SUM', 'SUM(number, …)', 'Adds up the numbers.'],
        ['AVERAGE', 'AVERAGE(number, …)', 'The average of the numbers.'],
        ['MIN', 'MIN(number, …)', 'The smallest number (or earliest date).'],
        ['MAX', 'MAX(number, …)', 'The largest number (or latest date).'],
        ['COUNT', 'COUNT(value, …)', 'How many of the values are numbers.'],
        ['COUNTA', 'COUNTA(value, …)', 'How many of the values are not empty.'],
        [
            'COUNTALL',
            'COUNTALL(value, …)',
            'How many values there are, empty ones included.',
        ],
        [
            'VALUE',
            'VALUE(text)',
            'Reads a number from text, like "$1,200.50" or "50%".',
        ],
    ]),
    ...group('Text', [
        [
            'CONCATENATE',
            'CONCATENATE(text, …)',
            'Joins the values into one piece of text.',
        ],
        ['LEN', 'LEN(text)', 'The number of characters in the text.'],
        ['LOWER', 'LOWER(text)', 'The text in lowercase.'],
        ['UPPER', 'UPPER(text)', 'The text in uppercase.'],
        [
            'TRIM',
            'TRIM(text)',
            'Removes spaces from the start and end of the text.',
        ],
        ['LEFT', 'LEFT(text, count)', 'The first characters of the text.'],
        ['RIGHT', 'RIGHT(text, count)', 'The last characters of the text.'],
        [
            'MID',
            'MID(text, start, count)',
            'Characters from the middle of the text, starting at position start (1 is the first).',
        ],
        [
            'FIND',
            'FIND(search for, text, start)',
            'Where the search text first appears (1 is the first character), or 0. Case-sensitive.',
        ],
        [
            'SEARCH',
            'SEARCH(search for, text, start)',
            "Where the search text first appears, ignoring case, or empty if it isn't there.",
        ],
        [
            'SUBSTITUTE',
            'SUBSTITUTE(text, old, new, occurrence)',
            'Replaces old text with new text, everywhere or only the given occurrence.',
        ],
        [
            'REPLACE',
            'REPLACE(text, start, count, new)',
            'Replaces count characters starting at position start with new text.',
        ],
        ['REPT', 'REPT(text, times)', 'Repeats the text a number of times.'],
        ['T', 'T(value)', 'The value if it is text, otherwise empty.'],
        [
            'ENCODE_URL_COMPONENT',
            'ENCODE_URL_COMPONENT(text)',
            'Encodes the text for use in a URL.',
        ],
        [
            'REGEX_MATCH',
            'REGEX_MATCH(text, pattern)',
            'True if the text matches the regular expression.',
        ],
        [
            'REGEX_EXTRACT',
            'REGEX_EXTRACT(text, pattern)',
            'The first part of the text that matches the regular expression.',
        ],
        [
            'REGEX_REPLACE',
            'REGEX_REPLACE(text, pattern, replacement)',
            'Replaces every match of the regular expression.',
        ],
    ]),
    ...group('Date', [
        ['TODAY', 'TODAY()', "Today's date, at midnight."],
        ['NOW', 'NOW()', 'The current date and time.'],
        ['YEAR', 'YEAR(date)', 'The year of a date.'],
        ['MONTH', 'MONTH(date)', 'The month of a date, 1 to 12.'],
        ['DAY', 'DAY(date)', 'The day of the month, 1 to 31.'],
        ['HOUR', 'HOUR(date)', 'The hour of a date, 0 to 23.'],
        ['MINUTE', 'MINUTE(date)', 'The minute of a date, 0 to 59.'],
        ['SECOND', 'SECOND(date)', 'The second of a date, 0 to 59.'],
        [
            'WEEKDAY',
            'WEEKDAY(date, start day)',
            'The day of the week, 0 (Sunday) to 6 (Saturday).',
        ],
        [
            'WEEKNUM',
            'WEEKNUM(date, start day)',
            'The week of the year, with weeks starting on Sunday.',
        ],
        [
            'DATEADD',
            'DATEADD(date, count, unit)',
            'Adds a number of units (days, months…) to a date.',
        ],
        [
            'DATETIME_DIFF',
            'DATETIME_DIFF(date1, date2, unit)',
            'The time from date2 to date1 in whole units (seconds unless given).',
        ],
        [
            'DATETIME_FORMAT',
            'DATETIME_FORMAT(date, format)',
            'Shows a date as text, like DATETIME_FORMAT(date, "MMMM D, YYYY").',
        ],
        [
            'DATETIME_PARSE',
            'DATETIME_PARSE(text, format)',
            'Reads a date from text, optionally in a given format.',
        ],
        ['DATESTR', 'DATESTR(date)', 'The date as YYYY-MM-DD text.'],
        [
            'IS_BEFORE',
            'IS_BEFORE(date1, date2)',
            'True if date1 is before date2.',
        ],
        ['IS_AFTER', 'IS_AFTER(date1, date2)', 'True if date1 is after date2.'],
        [
            'IS_SAME',
            'IS_SAME(date1, date2, unit)',
            'True if the dates are the same, optionally only down to a unit like "day".',
        ],
        [
            'WORKDAY',
            'WORKDAY(date, days, holidays)',
            'The date a number of working days (Monday to Friday) away.',
        ],
        [
            'WORKDAY_DIFF',
            'WORKDAY_DIFF(start date, end date, holidays)',
            'The number of working days between two dates, counting both.',
        ],
    ]),
    ...group('Record', [
        ['RECORD_ID', 'RECORD_ID()', 'The ID of the record.'],
        ['CREATED_TIME', 'CREATED_TIME()', 'When the record was created.'],
        [
            'LAST_MODIFIED_TIME',
            'LAST_MODIFIED_TIME()',
            'When the record was last changed.',
        ],
    ]),
    ...group('Array', [
        [
            'ARRAYJOIN',
            'ARRAYJOIN(values, separator)',
            'Joins a list of values into text (", " between them unless given).',
        ],
        ['ARRAYUNIQUE', 'ARRAYUNIQUE(values)', 'The list without duplicates.'],
        [
            'ARRAYCOMPACT',
            'ARRAYCOMPACT(values)',
            'The list without empty values.',
        ],
        [
            'ARRAYFLATTEN',
            'ARRAYFLATTEN(values)',
            'Turns a list of lists into one list.',
        ],
    ]),
];

export const FORMULA_FUNCTIONS_BY_NAME: Map<string, FormulaFunction> = new Map(
    FORMULA_FUNCTIONS.map((definition) => [definition.name, definition]),
);

/** The operators, as help text. */
export const FORMULA_OPERATORS: { symbol: string; description: string }[] = [
    { symbol: '{Field}', description: "a field's value" },
    { symbol: '+ - * /', description: 'arithmetic' },
    { symbol: '&', description: 'joins text' },
    { symbol: '= !=', description: 'equal, not equal' },
    { symbol: '< <= > >=', description: 'compare' },
    { symbol: '"text"', description: 'text in quotes' },
];

/** The argument names in a signature, e.g. ["number", "places"] for "ROUND(number, places)". */
export function signatureArguments(signature: string): string[] {
    const inside = signature.slice(
        signature.indexOf('(') + 1,
        signature.lastIndexOf(')'),
    );

    return inside === '' ? [] : inside.split(', ');
}
