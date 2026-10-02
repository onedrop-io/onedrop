import { Check, File, Star } from 'lucide-react';
import { cn } from '@/lib/utils';
import { CHOICE_CLASSES } from './colors';
import {
    asText,
    displayText,
    displayType,
    formatNumber,
    isImage,
} from './format';
import type { ValueContext } from './format';
import type {
    Attachment,
    CellValue,
    Field,
    FieldOptions,
    FieldType,
    TableUser,
} from './types';

export function ChoicePill({
    field,
    name,
    options,
}: {
    field?: Field;
    name: string;
    options?: FieldOptions;
}) {
    const choice = (options ?? field?.options)?.choices?.find(
        (candidate) => candidate.name === name,
    );

    return (
        <span
            className={cn(
                'inline-flex max-w-full shrink-0 items-center truncate rounded-full px-2 py-0.5 text-xs leading-4 font-medium',
                CHOICE_CLASSES[choice?.color ?? 'gray'],
            )}
        >
            {name}
        </span>
    );
}

export function UserChip({
    user,
    compact = false,
}: {
    user: TableUser | undefined;
    compact?: boolean;
}) {
    if (!user) {
        return null;
    }

    const initials = user.name
        .split(/\s+/)
        .map((part) => part[0])
        .join('')
        .slice(0, 2)
        .toUpperCase();

    return (
        <span className="inline-flex min-w-0 items-center gap-1.5">
            {user.avatar ? (
                <img
                    src={user.avatar}
                    alt=""
                    className="size-5 shrink-0 rounded-full object-cover"
                />
            ) : (
                <span className="flex size-5 shrink-0 items-center justify-center rounded-full bg-neutral-200 text-[10px] font-semibold text-neutral-700 dark:bg-neutral-700 dark:text-neutral-200">
                    {initials}
                </span>
            )}
            {!compact && <span className="truncate">{user.name}</span>}
        </span>
    );
}

export function LinkChip({
    title,
    onClick,
}: {
    title: string;
    onClick?: () => void;
}) {
    return (
        <span
            className={cn(
                'inline-flex max-w-full shrink-0 items-center truncate rounded-md border border-blue-200 bg-blue-50 px-1.5 py-0.5 text-xs leading-4 text-blue-900 dark:border-blue-900 dark:bg-blue-950 dark:text-blue-100',
                onClick &&
                    'cursor-pointer hover:bg-blue-100 dark:hover:bg-blue-900',
            )}
            onClick={onClick}
        >
            {title}
        </span>
    );
}

export function AttachmentThumb({
    file,
    size = 'sm',
}: {
    file: Attachment;
    size?: 'sm' | 'lg';
}) {
    const box = size === 'sm' ? 'h-6 w-6' : 'h-20 w-20';

    return isImage(file) ? (
        <img
            src={file.url}
            alt={file.name}
            title={file.name}
            loading="lazy"
            className={cn(
                box,
                'shrink-0 rounded border border-neutral-200 object-cover dark:border-neutral-700',
            )}
        />
    ) : (
        <span
            title={file.name}
            className={cn(
                box,
                'flex shrink-0 items-center justify-center rounded border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-neutral-700 dark:bg-neutral-900',
            )}
        >
            <File className={size === 'sm' ? 'size-3.5' : 'size-6'} />
        </span>
    );
}

export function Stars({
    value,
    max,
    onChange,
}: {
    value: number;
    max: number;
    onChange?: (value: number) => void;
}) {
    return (
        <span className="inline-flex items-center gap-0.5">
            {Array.from({ length: max }, (_, index) => (
                <Star
                    key={index}
                    className={cn(
                        'size-3.5',
                        index < value
                            ? 'fill-amber-400 text-amber-400'
                            : 'text-neutral-300 dark:text-neutral-600',
                        onChange && 'cursor-pointer',
                    )}
                    onClick={
                        onChange
                            ? (event) => {
                                  event.stopPropagation();
                                  onChange(index + 1 === value ? 0 : index + 1);
                              }
                            : undefined
                    }
                />
            ))}
        </span>
    );
}

export function CheckboxMark({ checked }: { checked: boolean }) {
    return (
        <span
            className={cn(
                'inline-flex size-4 items-center justify-center rounded border',
                checked
                    ? 'border-green-600 bg-green-600 text-white'
                    : 'border-neutral-300 dark:border-neutral-600',
            )}
        >
            {checked && <Check className="size-3" strokeWidth={3} />}
        </span>
    );
}

const NUMERIC: FieldType[] = ['number', 'currency', 'percent'];

function ScalarView({
    type,
    options,
    value,
    context,
}: {
    type: FieldType;
    options: FieldOptions;
    value: CellValue;
    context: ValueContext;
}) {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    if (type === 'select' && typeof value === 'string') {
        return <ChoicePill name={value} options={options} />;
    }

    if (type === 'checkbox') {
        return <CheckboxMark checked={Boolean(value)} />;
    }

    if (type === 'rating' && typeof value === 'number') {
        return <Stars value={value} max={options.max ?? 5} />;
    }

    if (type === 'user' && typeof value === 'number') {
        return <UserChip user={context.usersById.get(value)} />;
    }

    if (typeof value === 'number' && NUMERIC.includes(type)) {
        return (
            <span className="truncate">
                {formatNumber(value, type, options)}
            </span>
        );
    }

    if (typeof value === 'boolean') {
        return <CheckboxMark checked={value} />;
    }

    if (typeof value === 'object') {
        return null;
    }

    const text = displayText(
        {
            key: '',
            name: '',
            type,
            description: null,
            builtIn: false,
            primary: false,
            readOnly: true,
            options,
        },
        value,
        context,
    );

    if (type === 'url') {
        const href = /^https?:\/\//i.test(text) ? text : `https://${text}`;

        return (
            <a
                href={href}
                target="_blank"
                rel="noreferrer"
                className="truncate text-blue-600 underline-offset-2 hover:underline dark:text-blue-400"
                onClick={(event) => event.stopPropagation()}
            >
                {text}
            </a>
        );
    }

    if (type === 'email') {
        return (
            <a
                href={`mailto:${text}`}
                className="truncate text-blue-600 underline-offset-2 hover:underline dark:text-blue-400"
                onClick={(event) => event.stopPropagation()}
            >
                {text}
            </a>
        );
    }

    if (type === 'phone') {
        return (
            <a
                href={`tel:${text.replace(/[^\d+]/g, '')}`}
                className="truncate text-blue-600 underline-offset-2 hover:underline dark:text-blue-400"
                onClick={(event) => event.stopPropagation()}
            >
                {text}
            </a>
        );
    }

    return <span className="truncate">{text}</span>;
}

/**
 * A field's value as it reads in a cell, a card or the record panel.
 * `wrap` lets text and pills run onto more lines (taller rows, cards).
 */
export function CellView({
    field,
    value,
    error,
    context,
    wrap = false,
    onOpenLink,
}: {
    field: Field;
    value: CellValue | undefined;
    error?: string;
    context: ValueContext;
    wrap?: boolean;
    /** Opens a linked record; links show as plain chips without it */
    onOpenLink?: (table: string, id: number) => void;
}) {
    if (error) {
        return (
            <span
                title={error}
                className="cursor-help font-mono text-xs text-red-600 dark:text-red-400"
            >
                #ERROR
            </span>
        );
    }

    if (value === undefined || value === null) {
        return null;
    }

    const { type, options } = displayType(field);
    const lineClass = cn(
        'flex min-w-0 items-center gap-1',
        wrap ? 'flex-wrap' : 'overflow-hidden',
    );

    switch (field.type) {
        case 'multiSelect':
            return (
                <span className={lineClass}>
                    {(value as string[]).map((name) => (
                        <ChoicePill key={name} field={field} name={name} />
                    ))}
                </span>
            );
        case 'link': {
            const table = field.options.table ?? '';
            const titles = context.titles.get(table);

            return (
                <span className={lineClass}>
                    {(value as number[]).map((id) => (
                        <LinkChip
                            key={id}
                            title={titles?.get(id) ?? 'Untitled'}
                            onClick={
                                onOpenLink
                                    ? () => onOpenLink(table, id)
                                    : undefined
                            }
                        />
                    ))}
                </span>
            );
        }
        case 'attachment':
            return (
                <span className={lineClass}>
                    {(value as Attachment[]).map((file) => (
                        <AttachmentThumb key={file.key} file={file} />
                    ))}
                </span>
            );
        case 'longText':
            return (
                <span
                    className={cn(
                        'min-w-0',
                        wrap ? 'line-clamp-4 whitespace-pre-wrap' : 'truncate',
                    )}
                >
                    {asText(value)}
                </span>
            );
        default:
            break;
    }

    if (Array.isArray(value)) {
        if (type === 'attachment') {
            return (
                <span className={lineClass}>
                    {(value as Attachment[]).map((file) => (
                        <AttachmentThumb key={file.key} file={file} />
                    ))}
                </span>
            );
        }

        const pills = type === 'select' || type === 'multiSelect';

        return (
            <span className={lineClass}>
                {(value as CellValue[]).map((item, index) =>
                    pills && typeof item === 'string' ? (
                        <ChoicePill key={index} name={item} options={options} />
                    ) : (
                        <span
                            key={index}
                            className="inline-flex shrink-0 items-center rounded bg-neutral-100 px-1.5 py-0.5 text-xs dark:bg-neutral-800"
                        >
                            <ScalarView
                                type={type === 'multiSelect' ? 'select' : type}
                                options={options}
                                value={item}
                                context={context}
                            />
                        </span>
                    ),
                )}
            </span>
        );
    }

    return (
        <ScalarView
            type={type}
            options={options}
            value={value}
            context={context}
        />
    );
}

/** Whether a field's values line up on the right, like numbers in a spreadsheet. */
export function alignsRight(field: Field): boolean {
    return NUMERIC.includes(displayType(field).type) || field.type === 'count';
}
