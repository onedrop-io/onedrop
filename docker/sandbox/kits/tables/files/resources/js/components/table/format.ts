import type {
    Attachment,
    CellValue,
    Field,
    FieldOptions,
    FieldType,
    LinkedTable,
    ResultFormat,
    TableUser,
} from './types';

/** What showing, copying and parsing values needs besides the field. */
export interface ValueContext {
    usersById: Map<number, TableUser>;
    linked: Record<string, LinkedTable>;
    /** Titles of linked records, by table key then record id */
    titles: Map<string, Map<number, string>>;
}

/** How a value behaves when it's sorted, filtered, grouped or summarized. */
export type ValueKind =
    | 'text'
    | 'number'
    | 'date'
    | 'boolean'
    | 'choice'
    | 'choices'
    | 'user'
    | 'link'
    | 'attachment'
    | 'list';

const COMPUTED: FieldType[] = [
    'formula',
    'lookup',
    'rollup',
    'count',
    'createdAt',
    'updatedAt',
];

export function isComputed(field: Field): boolean {
    return COMPUTED.includes(field.type) || field.options.inverse === true;
}

export function isEditable(field: Field): boolean {
    return !field.readOnly && !isComputed(field);
}

/** A plain value as text; lists and objects are ''. */
export function asText(value: CellValue | undefined): string {
    return typeof value === 'string' ||
        typeof value === 'number' ||
        typeof value === 'boolean'
        ? String(value)
        : '';
}

export function isEmpty(value: CellValue | undefined): boolean {
    return (
        value === null ||
        value === undefined ||
        value === '' ||
        (Array.isArray(value) && value.length === 0)
    );
}

const FORMAT_TYPES: Record<ResultFormat, FieldType | null> = {
    auto: null,
    text: 'text',
    number: 'number',
    currency: 'currency',
    percent: 'percent',
    date: 'date',
};

/**
 * The type a value is shown as: a lookup shows the looked-up field's type, a formula or rollup its format,
 * a count a number.
 */
export function displayType(field: Field): {
    type: FieldType;
    options: FieldOptions;
} {
    if (field.type === 'lookup' && field.options.result) {
        return field.options.result;
    }

    if (field.type === 'count') {
        return { type: 'number', options: { precision: 0 } };
    }

    if (field.type === 'formula' || field.type === 'rollup') {
        const type = FORMAT_TYPES[field.options.format ?? 'auto'];

        if (type) {
            return { type, options: field.options };
        }
    }

    if (field.type === 'createdAt' || field.type === 'updatedAt') {
        return {
            type: 'date',
            options: { includeTime: field.options.includeTime ?? true },
        };
    }

    return { type: field.type, options: field.options };
}

export function valueKind(field: Field): ValueKind {
    const { type } = displayType(field);

    if (field.type === 'lookup') {
        return 'list';
    }

    switch (type) {
        case 'number':
        case 'currency':
        case 'percent':
        case 'rating':
            return 'number';
        case 'date':
        case 'createdAt':
        case 'updatedAt':
            return 'date';
        case 'checkbox':
            return 'boolean';
        case 'select':
            return 'choice';
        case 'multiSelect':
            return 'choices';
        case 'user':
            return 'user';
        case 'link':
            return 'link';
        case 'attachment':
            return 'attachment';
        default:
            return 'text';
    }
}

/** A "YYYY-MM-DD" date as a local date (not UTC midnight), or a full ISO date and time. */
export function parseDate(value: string): Date | null {
    const dateOnly = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);

    if (dateOnly) {
        return new Date(
            Number(dateOnly[1]),
            Number(dateOnly[2]) - 1,
            Number(dateOnly[3]),
        );
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? null : date;
}

export function toDateString(date: Date): string {
    const pad = (part: number) => String(part).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

const dateFormat = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' });
const dateTimeFormat = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
});

export function formatDate(value: string, includeTime = false): string {
    const date = parseDate(value);

    if (!date) {
        return value;
    }

    return includeTime && value.length > 10
        ? dateTimeFormat.format(date)
        : dateFormat.format(date);
}

export function formatNumber(
    value: number,
    type: FieldType,
    options: FieldOptions,
): string {
    if (type === 'percent') {
        return new Intl.NumberFormat(undefined, {
            style: 'percent',
            minimumFractionDigits: options.precision ?? 0,
            maximumFractionDigits: options.precision ?? 0,
        }).format(value);
    }

    const precision =
        options.precision ?? (type === 'currency' ? 2 : undefined);
    const text = new Intl.NumberFormat(undefined, {
        minimumFractionDigits: precision,
        maximumFractionDigits: precision ?? 4,
    }).format(value);

    if (type === 'currency') {
        const symbol = options.symbol ?? '$';

        return value < 0 ? `-${symbol}${text.slice(1)}` : `${symbol}${text}`;
    }

    return text;
}

function formatScalar(
    value: CellValue,
    type: FieldType,
    options: FieldOptions,
): string {
    if (value === null || value === undefined) {
        return '';
    }

    if (typeof value === 'boolean') {
        return value ? 'checked' : '';
    }

    if (typeof value === 'number') {
        if (['number', 'currency', 'percent', 'rating'].includes(type)) {
            return formatNumber(value, type, options);
        }

        return String(value);
    }

    if (typeof value === 'string') {
        if (type === 'date' && parseDate(value)) {
            return formatDate(value, options.includeTime);
        }

        return value;
    }

    return '';
}

/**
 * A value as text: what a cell reads as, what's copied, exported and searched.
 */
export function displayText(
    field: Field,
    value: CellValue | undefined,
    context: ValueContext,
): string {
    if (value === undefined || value === null) {
        return '';
    }

    const { type, options } = displayType(field);

    switch (field.type) {
        case 'checkbox':
            return value ? 'checked' : '';
        case 'user':
            return typeof value === 'number'
                ? (context.usersById.get(value)?.name ?? '')
                : '';
        case 'link': {
            const titles = context.titles.get(field.options.table ?? '');

            return (value as number[])
                .map((id) => titles?.get(id) ?? 'Untitled')
                .join(', ');
        }
        case 'attachment':
            return (value as Attachment[]).map((file) => file.name).join(', ');
        case 'multiSelect':
            return (value as string[]).join(', ');
        default:
            break;
    }

    if (Array.isArray(value)) {
        return value
            .map((item) =>
                typeof item === 'object' && item !== null && 'name' in item
                    ? (item as Attachment).name
                    : formatScalar(item as CellValue, type, options),
            )
            .filter((text) => text !== '')
            .join(', ');
    }

    return formatScalar(value, type, options);
}

/** Text that reads as checked when pasted or imported into a checkbox. */
const TRUTHY = ['true', 'yes', 'y', 'x', '1', 'checked', 'on', '✓', '✔'];

export type ParseResult = { ok: true; value: CellValue } | { ok: false };

/**
 * Text typed, pasted or imported into a field, as its value. Fails for text that can't fit, like a word in a
 * number field, so a paste can skip that cell instead of refusing the whole paste.
 * `allowNewChoices`: a new select option may be added (the server adds it to the field).
 */
export function parseText(
    field: Field,
    text: string,
    context: ValueContext,
    allowNewChoices = false,
): ParseResult {
    const trimmed = text.trim();

    if (trimmed === '') {
        if (field.type === 'checkbox') {
            return { ok: true, value: false };
        }

        return {
            ok: true,
            value: ['multiSelect', 'link', 'attachment'].includes(field.type)
                ? []
                : null,
        };
    }

    switch (field.type) {
        case 'text':
        case 'longText':
        case 'url':
        case 'email':
        case 'phone':
            return {
                ok: true,
                value: field.type === 'longText' ? text : trimmed,
            };
        case 'number':
        case 'currency':
        case 'rating': {
            const number = parseNumber(trimmed);

            if (number === null) {
                return { ok: false };
            }

            if (field.type === 'rating') {
                const max = field.options.max ?? 5;

                return {
                    ok: true,
                    value: Math.max(0, Math.min(max, Math.round(number))),
                };
            }

            return { ok: true, value: number };
        }
        case 'percent': {
            const number = parseNumber(trimmed.replace('%', ''));

            return number === null
                ? { ok: false }
                : { ok: true, value: number / 100 };
        }
        case 'checkbox':
            return {
                ok: true,
                value: TRUTHY.includes(trimmed.toLowerCase()),
            };
        case 'date': {
            const date = parseLooseDate(trimmed);

            if (!date) {
                return { ok: false };
            }

            return {
                ok: true,
                value: field.options.includeTime
                    ? date.toISOString()
                    : toDateString(date),
            };
        }
        case 'select': {
            const choice = matchChoice(field, trimmed);

            if (choice) {
                return { ok: true, value: choice };
            }

            return allowNewChoices && !field.builtIn
                ? { ok: true, value: trimmed }
                : { ok: false };
        }
        case 'multiSelect': {
            const names = splitList(trimmed);
            const values: string[] = [];

            for (const name of names) {
                const choice = matchChoice(field, name);

                if (choice) {
                    values.push(choice);
                } else if (allowNewChoices && !field.builtIn) {
                    values.push(name);
                }
            }

            return { ok: true, value: [...new Set(values)] };
        }
        case 'user': {
            const needle = trimmed.toLowerCase();
            const user = [...context.usersById.values()].find(
                (person) =>
                    person.email.toLowerCase() === needle ||
                    person.name.toLowerCase() === needle,
            );

            return user ? { ok: true, value: user.id } : { ok: false };
        }
        case 'link': {
            const titles = context.titles.get(field.options.table ?? '');

            if (!titles) {
                return { ok: false };
            }

            const ids: number[] = [];

            for (const title of splitList(trimmed)) {
                const needle = title.toLowerCase();

                for (const [id, candidate] of titles) {
                    if (candidate.toLowerCase() === needle) {
                        ids.push(id);
                        break;
                    }
                }
            }

            const unique = [...new Set(ids)];

            return {
                ok: true,
                value: field.options.multiple ? unique : unique.slice(0, 1),
            };
        }
        default:
            return { ok: false };
    }
}

export function parseNumber(text: string): number | null {
    const cleaned = text.replace(/[\s,$€£¥]/g, '');

    if (
        cleaned === '' ||
        !/^[-+]?(\d+\.?\d*|\.\d+)(e[-+]?\d+)?$/i.test(cleaned)
    ) {
        return null;
    }

    return Number(cleaned);
}

function parseLooseDate(text: string): Date | null {
    const iso = parseDate(text);

    if (iso && /^\d{4}-\d{2}-\d{2}/.test(text)) {
        return iso;
    }

    const slashed = /^(\d{1,2})[/.-](\d{1,2})[/.-](\d{2,4})$/.exec(text);

    if (slashed) {
        const year =
            Number(slashed[3]) < 100
                ? 2000 + Number(slashed[3])
                : Number(slashed[3]);

        return new Date(year, Number(slashed[1]) - 1, Number(slashed[2]));
    }

    const parsed = new Date(text);

    return Number.isNaN(parsed.getTime()) ? null : parsed;
}

function matchChoice(field: Field, name: string): string | null {
    const choices = field.options.choices ?? [];

    return (
        choices.find((choice) => choice.name === name)?.name ??
        choices.find(
            (choice) => choice.name.toLowerCase() === name.toLowerCase(),
        )?.name ??
        null
    );
}

/** "a, b, c" or "a\nb" as a list, keeping quoted parts ("Smith, Jane") whole. */
export function splitList(text: string): string[] {
    const parts: string[] = [];
    let current = '';
    let quoted = false;

    for (const character of text) {
        if (character === '"') {
            quoted = !quoted;
        } else if ((character === ',' || character === '\n') && !quoted) {
            parts.push(current.trim());
            current = '';
        } else {
            current += character;
        }
    }

    parts.push(current.trim());

    return parts.filter((part) => part !== '');
}

export function formatBytes(size: number): string {
    if (size < 1024) {
        return `${size} B`;
    }

    if (size < 1024 * 1024) {
        return `${Math.round(size / 1024)} KB`;
    }

    return `${(size / 1024 / 1024).toFixed(1)} MB`;
}

export function isImage(file: Attachment): boolean {
    return /^image\/(png|jpe?g|gif|webp|avif|bmp)$/.test(file.type);
}

/** The labels for each field type, as people pick them. */
export const FIELD_TYPE_LABELS: Record<FieldType, string> = {
    text: 'Single line text',
    longText: 'Long text',
    number: 'Number',
    currency: 'Currency',
    percent: 'Percent',
    checkbox: 'Checkbox',
    date: 'Date',
    select: 'Single select',
    multiSelect: 'Multiple select',
    user: 'Person',
    link: 'Link to another table',
    attachment: 'Attachment',
    rating: 'Rating',
    url: 'URL',
    email: 'Email',
    phone: 'Phone number',
    formula: 'Formula',
    lookup: 'Lookup',
    rollup: 'Rollup',
    count: 'Count',
    createdAt: 'Created time',
    updatedAt: 'Last modified time',
};
