import {
    asText,
    displayText,
    isEmpty,
    parseDate,
    toDateString,
    valueKind,
} from './format';
import type { ValueContext, ValueKind } from './format';
import type {
    CellValue,
    Field,
    FilterCondition,
    FilterOperator,
    Filters,
    SortRule,
    SummaryFunction,
    TableRecord,
} from './types';

/** Filter operators for each kind of value, in the order they're offered. */
export const OPERATORS: Record<ValueKind, FilterOperator[]> = {
    text: ['contains', 'notContains', 'is', 'isNot', 'isEmpty', 'isNotEmpty'],
    number: ['eq', 'neq', 'lt', 'lte', 'gt', 'gte', 'isEmpty', 'isNotEmpty'],
    date: [
        'is',
        'isBefore',
        'isAfter',
        'isOnOrBefore',
        'isOnOrAfter',
        'isWithin',
        'isEmpty',
        'isNotEmpty',
    ],
    boolean: ['is'],
    choice: ['is', 'isNot', 'isAnyOf', 'isNoneOf', 'isEmpty', 'isNotEmpty'],
    choices: ['hasAnyOf', 'hasAllOf', 'hasNoneOf', 'isEmpty', 'isNotEmpty'],
    user: [
        'is',
        'isNot',
        'isAnyOf',
        'isNoneOf',
        'isMe',
        'isEmpty',
        'isNotEmpty',
    ],
    link: ['contains', 'notContains', 'isEmpty', 'isNotEmpty'],
    attachment: ['isEmpty', 'isNotEmpty'],
    list: ['contains', 'notContains', 'isEmpty', 'isNotEmpty'],
};

export const OPERATOR_LABELS: Record<FilterOperator, string> = {
    contains: 'contains',
    notContains: 'does not contain',
    is: 'is',
    isNot: 'is not',
    isEmpty: 'is empty',
    isNotEmpty: 'is not empty',
    eq: '=',
    neq: '≠',
    lt: '<',
    lte: '≤',
    gt: '>',
    gte: '≥',
    isBefore: 'is before',
    isAfter: 'is after',
    isOnOrBefore: 'is on or before',
    isOnOrAfter: 'is on or after',
    isWithin: 'is within',
    isAnyOf: 'is any of',
    isNoneOf: 'is none of',
    hasAnyOf: 'has any of',
    hasAllOf: 'has all of',
    hasNoneOf: 'has none of',
    isMe: 'is me',
};

/** Operators that don't take a value. */
export const VALUELESS: FilterOperator[] = ['isEmpty', 'isNotEmpty', 'isMe'];

/** Relative dates a date condition can compare with, besides an exact "YYYY-MM-DD". */
export const RELATIVE_DATES: Record<string, string> = {
    today: 'today',
    tomorrow: 'tomorrow',
    yesterday: 'yesterday',
    oneWeekAgo: 'one week ago',
    oneWeekFromNow: 'one week from now',
    oneMonthAgo: 'one month ago',
    oneMonthFromNow: 'one month from now',
};

/** Ranges "is within" takes. */
export const DATE_RANGES: Record<string, string> = {
    pastWeek: 'the past week',
    pastMonth: 'the past month',
    pastYear: 'the past year',
    nextWeek: 'the next week',
    nextMonth: 'the next month',
    nextYear: 'the next year',
};

export function operatorsFor(field: Field): FilterOperator[] {
    return OPERATORS[valueKind(field)];
}

function startOfDay(date: Date): Date {
    return new Date(date.getFullYear(), date.getMonth(), date.getDate());
}

function addDays(date: Date, days: number): Date {
    const copy = new Date(date);

    copy.setDate(copy.getDate() + days);

    return copy;
}

function addMonths(date: Date, months: number): Date {
    const copy = new Date(date);

    copy.setMonth(copy.getMonth() + months);

    return copy;
}

/** The day a date condition's value means, at midnight. */
export function resolveDate(value: CellValue | undefined): Date | null {
    if (typeof value !== 'string' || value === '') {
        return null;
    }

    const today = startOfDay(new Date());

    switch (value) {
        case 'today':
            return today;
        case 'tomorrow':
            return addDays(today, 1);
        case 'yesterday':
            return addDays(today, -1);
        case 'oneWeekAgo':
            return addDays(today, -7);
        case 'oneWeekFromNow':
            return addDays(today, 7);
        case 'oneMonthAgo':
            return addMonths(today, -1);
        case 'oneMonthFromNow':
            return addMonths(today, 1);
        default: {
            const date = parseDate(value);

            return date ? startOfDay(date) : null;
        }
    }
}

function dateRange(range: string): [Date, Date] | null {
    const today = startOfDay(new Date());
    const end = addDays(today, 1);

    switch (range) {
        case 'pastWeek':
            return [addDays(today, -7), end];
        case 'pastMonth':
            return [addMonths(today, -1), end];
        case 'pastYear':
            return [addMonths(today, -12), end];
        case 'nextWeek':
            return [today, addDays(today, 8)];
        case 'nextMonth':
            return [today, addDays(addMonths(today, 1), 1)];
        case 'nextYear':
            return [today, addDays(addMonths(today, 12), 1)];
        default:
            return null;
    }
}

/** Whether a condition has what it needs to apply; incomplete ones are ignored, like Airtable does. */
export function isComplete(condition: FilterCondition): boolean {
    if (VALUELESS.includes(condition.operator)) {
        return true;
    }

    if (condition.operator === 'is' && typeof condition.value === 'boolean') {
        return true;
    }

    return !isEmpty(condition.value ?? null);
}

function asList(value: CellValue | undefined): CellValue[] {
    if (value === undefined || value === null) {
        return [];
    }

    return Array.isArray(value) ? (value as CellValue[]) : [value];
}

function matches(
    field: Field,
    record: TableRecord,
    condition: FilterCondition,
    context: ValueContext,
    me: number | null,
): boolean {
    const value = record.values[field.key];
    const kind = valueKind(field);
    const target = condition.value;

    switch (condition.operator) {
        case 'isEmpty':
            return kind === 'boolean' ? value !== true : isEmpty(value);
        case 'isNotEmpty':
            return kind === 'boolean' ? value === true : !isEmpty(value);
        case 'isMe':
            return me !== null && value === me;
        default:
            break;
    }

    if (kind === 'boolean') {
        return Boolean(value) === Boolean(target);
    }

    if (kind === 'number') {
        const number = typeof value === 'number' ? value : null;
        const wanted = typeof target === 'number' ? target : Number(target);

        if (number === null || Number.isNaN(wanted)) {
            return condition.operator === 'neq';
        }

        switch (condition.operator) {
            case 'eq':
                return number === wanted;
            case 'neq':
                return number !== wanted;
            case 'lt':
                return number < wanted;
            case 'lte':
                return number <= wanted;
            case 'gt':
                return number > wanted;
            case 'gte':
                return number >= wanted;
            default:
                return true;
        }
    }

    if (kind === 'date') {
        const date = typeof value === 'string' ? parseDate(value) : null;

        if (!date) {
            return false;
        }

        const day = startOfDay(date).getTime();

        if (condition.operator === 'isWithin') {
            const range = dateRange(asText(target));

            return range !== null && date >= range[0] && date < range[1];
        }

        const wanted = resolveDate(target)?.getTime();

        if (wanted === undefined) {
            return true;
        }

        switch (condition.operator) {
            case 'is':
                return day === wanted;
            case 'isBefore':
                return day < wanted;
            case 'isAfter':
                return day > wanted;
            case 'isOnOrBefore':
                return day <= wanted;
            case 'isOnOrAfter':
                return day >= wanted;
            default:
                return true;
        }
    }

    if (kind === 'choice' || kind === 'user') {
        const wanted = asList(target);

        switch (condition.operator) {
            case 'is':
                return value === wanted[0];
            case 'isNot':
                return value !== wanted[0];
            case 'isAnyOf':
                return wanted.includes(value as CellValue);
            case 'isNoneOf':
                return !wanted.includes(value as CellValue);
            default:
                return true;
        }
    }

    if (kind === 'choices') {
        const have = asList(value);
        const wanted = asList(target);

        switch (condition.operator) {
            case 'hasAnyOf':
                return wanted.some((item) => have.includes(item));
            case 'hasAllOf':
                return wanted.every((item) => have.includes(item));
            case 'hasNoneOf':
                return !wanted.some((item) => have.includes(item));
            default:
                return true;
        }
    }

    const text = displayText(field, value, context).toLowerCase();
    const wanted = asText(target).toLowerCase();

    switch (condition.operator) {
        case 'contains':
            return text.includes(wanted);
        case 'notContains':
            return !text.includes(wanted);
        case 'is':
            return text === wanted;
        case 'isNot':
            return text !== wanted;
        default:
            return true;
    }
}

export function applyFilters(
    records: TableRecord[],
    filters: Filters,
    fieldsByKey: Map<string, Field>,
    context: ValueContext,
    me: number | null,
): TableRecord[] {
    const conditions = filters.conditions.filter(
        (condition) =>
            fieldsByKey.has(condition.field) && isComplete(condition),
    );

    if (conditions.length === 0) {
        return records;
    }

    return records.filter((record) => {
        const results = conditions.map((condition) =>
            matches(
                fieldsByKey.get(condition.field)!,
                record,
                condition,
                context,
                me,
            ),
        );

        return filters.conjunction === 'and'
            ? results.every(Boolean)
            : results.some(Boolean);
    });
}

export function applySearch(
    records: TableRecord[],
    search: string,
    fields: Field[],
    context: ValueContext,
): TableRecord[] {
    const needle = search.trim().toLowerCase();

    if (needle === '') {
        return records;
    }

    return records.filter((record) =>
        fields.some((field) =>
            displayText(field, record.values[field.key], context)
                .toLowerCase()
                .includes(needle),
        ),
    );
}

const collator = new Intl.Collator(undefined, {
    numeric: true,
    sensitivity: 'base',
});

/** A value turned into something comparable: a number or text. Empty values are null. */
function sortKey(
    field: Field,
    value: CellValue | undefined,
    context: ValueContext,
): number | string | null {
    if (isEmpty(value ?? null) && valueKind(field) !== 'boolean') {
        return null;
    }

    switch (valueKind(field)) {
        case 'number':
            return typeof value === 'number' ? value : null;
        case 'date':
            return typeof value === 'string'
                ? (parseDate(value)?.getTime() ?? null)
                : null;
        case 'boolean':
            return value ? 1 : 0;
        case 'choice': {
            const index = (field.options.choices ?? []).findIndex(
                (choice) => choice.name === value,
            );

            return index === -1 ? 999 : index;
        }
        case 'choices': {
            const first = (value as string[])[0];
            const index = (field.options.choices ?? []).findIndex(
                (choice) => choice.name === first,
            );

            return index === -1 ? 999 : index;
        }
        case 'attachment':
            return (value as unknown[]).length;
        default:
            return displayText(field, value, context);
    }
}

export function compareValues(
    field: Field,
    a: CellValue | undefined,
    b: CellValue | undefined,
    context: ValueContext,
): number {
    const left = sortKey(field, a, context);
    const right = sortKey(field, b, context);

    if (left === null || right === null) {
        // Empty values go last either way.
        return left === right ? 0 : left === null ? 1 : -1;
    }

    if (typeof left === 'number' && typeof right === 'number') {
        return left - right;
    }

    return collator.compare(String(left), String(right));
}

export function applySorts(
    records: TableRecord[],
    sorts: SortRule[],
    fieldsByKey: Map<string, Field>,
    context: ValueContext,
): TableRecord[] {
    const rules = sorts.filter((rule) => fieldsByKey.has(rule.field));

    if (rules.length === 0) {
        return records;
    }

    return [...records].sort((a, b) => {
        for (const rule of rules) {
            const field = fieldsByKey.get(rule.field)!;
            const left = a.values[field.key];
            const right = b.values[field.key];
            const result = compareValues(field, left, right, context);

            if (result !== 0) {
                const bothFilled =
                    !isEmpty(left ?? null) && !isEmpty(right ?? null);

                return rule.direction === 'desc' && bothFilled
                    ? -result
                    : result;
            }
        }

        return a.id - b.id;
    });
}

export interface GroupNode {
    /** Unique path, e.g. "stage:Won/owner:3" */
    id: string;
    field: Field;
    /** A value standing for the group, shown with the field's cell */
    value: CellValue;
    depth: number;
    records: TableRecord[];
    children: GroupNode[] | null;
}

function groupKey(
    field: Field,
    value: CellValue | undefined,
    context: ValueContext,
): string {
    if (isEmpty(value ?? null)) {
        return valueKind(field) === 'boolean' ? 'false' : '';
    }

    switch (valueKind(field)) {
        case 'date':
            return typeof value === 'string'
                ? toDateString(parseDate(value) ?? new Date(value))
                : '';
        case 'boolean':
            return value ? 'true' : 'false';
        case 'user':
        case 'number':
            return asText(value);
        default:
            return displayText(field, value, context);
    }
}

/** Records split into nested groups, each level sorted by its rule. */
export function groupRecords(
    records: TableRecord[],
    groups: SortRule[],
    fieldsByKey: Map<string, Field>,
    context: ValueContext,
    depth = 0,
    path = '',
): GroupNode[] {
    const rule = groups[depth];
    const field = rule ? fieldsByKey.get(rule.field) : undefined;

    if (!rule || !field) {
        return [];
    }

    const buckets = new Map<string, TableRecord[]>();

    for (const record of records) {
        const key = groupKey(field, record.values[field.key], context);

        buckets.set(key, [...(buckets.get(key) ?? []), record]);
    }

    const nodes = [...buckets.entries()].map(([key, members]) => {
        const id = `${path}${field.key}:${key}/`;
        const value = members[0].values[field.key] ?? null;
        const nested = groupRecords(
            members,
            groups,
            fieldsByKey,
            context,
            depth + 1,
            id,
        );

        return {
            id,
            field,
            value: valueKind(field) === 'date' && key !== '' ? key : value,
            depth,
            records: members,
            children: nested.length > 0 ? nested : null,
        };
    });

    nodes.sort((a, b) => {
        const result = compareValues(field, a.value, b.value, context);

        return rule.direction === 'desc' &&
            !isEmpty(a.value) &&
            !isEmpty(b.value)
            ? -result
            : result;
    });

    return nodes;
}

export const SUMMARY_LABELS: Record<SummaryFunction, string> = {
    none: 'None',
    filled: 'Filled',
    empty: 'Empty',
    unique: 'Unique',
    percentFilled: 'Percent filled',
    sum: 'Sum',
    average: 'Average',
    min: 'Min',
    max: 'Max',
    earliest: 'Earliest',
    latest: 'Latest',
    checked: 'Checked',
    unchecked: 'Unchecked',
};

export function summariesFor(field: Field): SummaryFunction[] {
    switch (valueKind(field)) {
        case 'number':
            return [
                'none',
                'sum',
                'average',
                'min',
                'max',
                'filled',
                'empty',
                'unique',
                'percentFilled',
            ];
        case 'date':
            return [
                'none',
                'earliest',
                'latest',
                'filled',
                'empty',
                'unique',
                'percentFilled',
            ];
        case 'boolean':
            return ['none', 'checked', 'unchecked', 'percentFilled'];
        default:
            return ['none', 'filled', 'empty', 'unique', 'percentFilled'];
    }
}

/** A column's summary over the records, as a value of the field (sum, min…) or a plain count. */
export function summarize(
    field: Field,
    records: TableRecord[],
    summary: SummaryFunction,
    context: ValueContext,
): { value: CellValue; isCount: boolean } {
    const values = records.map((record) => record.values[field.key] ?? null);
    const filled = values.filter((value) =>
        valueKind(field) === 'boolean' ? value === true : !isEmpty(value),
    );
    const numbers = values.filter(
        (value): value is number => typeof value === 'number',
    );
    const dates = values
        .filter((value): value is string => typeof value === 'string')
        .filter((value) => parseDate(value) !== null)
        .sort((a, b) => parseDate(a)!.getTime() - parseDate(b)!.getTime());

    switch (summary) {
        case 'filled':
        case 'checked':
            return { value: filled.length, isCount: true };
        case 'empty':
        case 'unchecked':
            return { value: values.length - filled.length, isCount: true };
        case 'unique':
            return {
                value: new Set(
                    filled.map((value) => displayText(field, value, context)),
                ).size,
                isCount: true,
            };
        case 'percentFilled':
            return {
                value:
                    values.length === 0
                        ? '0%'
                        : `${Math.round((filled.length / values.length) * 100)}%`,
                isCount: true,
            };
        case 'sum':
            return {
                value: numbers.reduce((total, value) => total + value, 0),
                isCount: false,
            };
        case 'average':
            return {
                value:
                    numbers.length === 0
                        ? null
                        : numbers.reduce((total, value) => total + value, 0) /
                          numbers.length,
                isCount: false,
            };
        case 'min':
            return {
                value: numbers.length === 0 ? null : Math.min(...numbers),
                isCount: false,
            };
        case 'max':
            return {
                value: numbers.length === 0 ? null : Math.max(...numbers),
                isCount: false,
            };
        case 'earliest':
            return { value: dates[0] ?? null, isCount: false };
        case 'latest':
            return { value: dates[dates.length - 1] ?? null, isCount: false };
        default:
            return { value: null, isCount: true };
    }
}
