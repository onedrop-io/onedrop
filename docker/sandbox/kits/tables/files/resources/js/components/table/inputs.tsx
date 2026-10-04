import { Check, File, LoaderCircle, Plus, Upload, X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { KeyboardEvent, ReactNode } from 'react';
import { cn } from '@/lib/utils';
import { request, TableRequestError } from './api';
import { CellView, ChoicePill, LinkChip, Stars, UserChip } from './cells';
import {
    displayText,
    formatBytes,
    formatNumber,
    isEditable,
    isEmpty,
    isImage,
    parseDate,
    parseText,
    toDateString,
} from './format';
import { Popover, PopoverContent, PopoverTrigger } from './popover';
import type { Attachment, CellValue, Field } from './types';
import { useTableStore } from './use-table';

export interface ValueInputProps {
    field: Field;
    value: CellValue;
    onChange: (value: CellValue) => void;
    /** Called when a pick is final (single select, person, single link), so a popover can close */
    onDone?: () => void;
    autoFocus?: boolean;
    /** popover: inside the grid's editor popover; form: a row of the record panel */
    variant: 'popover' | 'form';
    /** A formula or rollup's error for this record, shown on read-only fields */
    error?: string;
}

const PICKER_TYPES = ['select', 'multiSelect', 'user', 'link'];

const inputClass =
    'h-8 w-full min-w-0 rounded-md border border-neutral-200 bg-white px-2.5 text-sm text-neutral-900 shadow-xs outline-none transition-colors placeholder:text-neutral-400 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 dark:border-neutral-800 dark:bg-neutral-950 dark:text-neutral-100';

/**
 * The editor for one field's value: a text box, a picker, stars, a date, uploads… by the field's type.
 * Read-only and worked-out fields show their value instead.
 */
export function ValueInput(props: ValueInputProps) {
    const { field, value, variant, error } = props;
    const store = useTableStore();

    if (!isEditable(field) || !store.can.edit) {
        return (
            <div
                className={cn(
                    'flex min-h-8 min-w-0 items-center text-sm',
                    variant === 'form' &&
                        'rounded-md bg-neutral-50 px-2.5 py-1.5 text-neutral-700 dark:bg-neutral-900 dark:text-neutral-300',
                )}
            >
                {isEmpty(value) && !error ? (
                    <span className="text-neutral-400">—</span>
                ) : (
                    <CellView
                        field={field}
                        value={value}
                        error={error}
                        context={store.context}
                        wrap
                    />
                )}
            </div>
        );
    }

    if (variant === 'form' && PICKER_TYPES.includes(field.type)) {
        return <FormPicker {...props} />;
    }

    return <Editor {...props} />;
}

function Editor(props: ValueInputProps) {
    const { field } = props;

    switch (field.type) {
        case 'longText':
            return <LongTextInput {...props} />;
        case 'number':
        case 'currency':
        case 'percent':
            return <NumberInput {...props} />;
        case 'checkbox':
            return <CheckboxInput {...props} />;
        case 'rating':
            return <RatingInput {...props} />;
        case 'date':
            return <DateInput {...props} />;
        case 'select':
            return <SelectInput {...props} />;
        case 'multiSelect':
            return <MultiSelectInput {...props} />;
        case 'user':
            return <UserInput {...props} />;
        case 'link':
            return <LinkInput {...props} />;
        case 'attachment':
            return <AttachmentInput {...props} />;
        default:
            return <TextInput {...props} />;
    }
}

function sameValue(a: CellValue, b: CellValue): boolean {
    return JSON.stringify(a ?? null) === JSON.stringify(b ?? null);
}

/* Text */

function TextInput({
    field,
    value,
    onChange,
    onDone,
    autoFocus,
}: ValueInputProps) {
    const store = useTableStore();
    const [draft, setDraft] = useState<string | null>(null);
    const shown = draft ?? (typeof value === 'string' ? value : '');

    const commit = () => {
        if (draft === null) {
            return;
        }

        const parsed = parseText(field, draft, store.context);

        setDraft(null);

        if (parsed.ok && !sameValue(parsed.value, value)) {
            onChange(parsed.value);
        }
    };

    return (
        <input
            type={
                field.type === 'email'
                    ? 'email'
                    : field.type === 'url'
                      ? 'url'
                      : field.type === 'phone'
                        ? 'tel'
                        : 'text'
            }
            aria-label={field.name}
            className={inputClass}
            value={shown}
            autoFocus={autoFocus}
            onChange={(event) => setDraft(event.target.value)}
            onBlur={commit}
            onKeyDown={(event) => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    commit();
                    onDone?.();
                } else if (event.key === 'Escape' && draft !== null) {
                    event.stopPropagation();
                    setDraft(null);
                }
            }}
        />
    );
}

function LongTextInput({
    field,
    value,
    onChange,
    onDone,
    autoFocus,
}: ValueInputProps) {
    const [draft, setDraft] = useState<string | null>(null);
    const shown = draft ?? (typeof value === 'string' ? value : '');
    const ref = useRef<HTMLTextAreaElement>(null);

    // Grow with the text.
    useEffect(() => {
        const element = ref.current;

        if (element) {
            element.style.height = 'auto';
            element.style.height = `${Math.min(element.scrollHeight + 2, 480)}px`;
        }
    }, [shown]);

    const commit = () => {
        if (draft === null) {
            return;
        }

        const next = draft === '' ? null : draft;

        setDraft(null);

        if (!sameValue(next, value)) {
            onChange(next);
        }
    };

    return (
        <textarea
            ref={ref}
            aria-label={field.name}
            rows={2}
            className={cn(inputClass, 'h-auto resize-none py-1.5 leading-5')}
            value={shown}
            autoFocus={autoFocus}
            onChange={(event) => setDraft(event.target.value)}
            onBlur={commit}
            onKeyDown={(event) => {
                if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) {
                    event.preventDefault();
                    commit();
                    onDone?.();
                } else if (event.key === 'Escape' && draft !== null) {
                    event.stopPropagation();
                    setDraft(null);
                }
            }}
        />
    );
}

/* Numbers */

/** A value as it's typed: percent as 50 for 0.5, without grouping or symbols. */
function editableNumber(field: Field, value: CellValue): string {
    if (typeof value !== 'number') {
        return '';
    }

    const shown = field.type === 'percent' ? value * 100 : value;

    return String(Number(shown.toPrecision(12)));
}

function NumberInput({
    field,
    value,
    onChange,
    onDone,
    autoFocus,
}: ValueInputProps) {
    const store = useTableStore();
    const [draft, setDraft] = useState<string | null>(
        autoFocus ? editableNumber(field, value) : null,
    );
    const [invalid, setInvalid] = useState(false);
    const formatted =
        typeof value === 'number'
            ? formatNumber(value, field.type, field.options)
            : '';

    const commit = (): boolean => {
        if (draft === null) {
            return true;
        }

        const parsed = parseText(field, draft, store.context);

        if (!parsed.ok) {
            setInvalid(true);

            return false;
        }

        setInvalid(false);
        setDraft(null);

        if (!sameValue(parsed.value, value)) {
            onChange(parsed.value);
        }

        return true;
    };

    return (
        <div className="flex min-w-0 flex-col gap-1">
            <input
                type="text"
                inputMode="decimal"
                aria-label={field.name}
                aria-invalid={invalid || undefined}
                className={cn(
                    inputClass,
                    'text-right tabular-nums',
                    invalid &&
                        'border-red-500 focus:border-red-500 focus:ring-red-500/20',
                )}
                value={draft ?? formatted}
                placeholder={field.type === 'percent' ? '0%' : undefined}
                autoFocus={autoFocus}
                onFocus={() => {
                    if (draft === null) {
                        setDraft(editableNumber(field, value));
                    }
                }}
                onChange={(event) => {
                    setDraft(event.target.value);
                    setInvalid(false);
                }}
                onBlur={() => {
                    if (!commit()) {
                        return;
                    }

                    setDraft(null);
                }}
                onKeyDown={(event) => {
                    if (event.key === 'Enter') {
                        event.preventDefault();

                        if (commit()) {
                            event.currentTarget.blur();
                            onDone?.();
                        }
                    } else if (event.key === 'Escape') {
                        event.stopPropagation();
                        setDraft(null);
                        setInvalid(false);
                        event.currentTarget.blur();
                    }
                }}
            />
            {invalid && (
                <p className="text-xs text-red-600 dark:text-red-400">
                    {field.type === 'percent'
                        ? 'Enter a percentage, like 50%.'
                        : field.type === 'currency'
                          ? 'Enter an amount, like 1,200.'
                          : 'Enter a number.'}
                </p>
            )}
        </div>
    );
}

/* Checkbox and rating */

function CheckboxInput({ field, value, onChange }: ValueInputProps) {
    const checked = value === true;

    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            aria-label={field.name}
            onClick={() => onChange(!checked)}
            className={cn(
                'relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40',
                checked ? 'bg-green-600' : 'bg-neutral-300 dark:bg-neutral-700',
            )}
        >
            <span
                className={cn(
                    'inline-block size-4 rounded-full bg-white shadow transition-transform',
                    checked ? 'translate-x-4.5' : 'translate-x-0.5',
                )}
            />
        </button>
    );
}

function RatingInput({ field, value, onChange }: ValueInputProps) {
    const max = field.options.max ?? 5;
    const rating = typeof value === 'number' ? value : 0;

    return (
        <div
            role="slider"
            tabIndex={0}
            aria-label={field.name}
            aria-valuemin={0}
            aria-valuemax={max}
            aria-valuenow={rating}
            className="inline-flex h-8 items-center rounded-md px-1 outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 [&_svg]:size-4.5"
            onKeyDown={(event) => {
                if (event.key === 'ArrowRight' || event.key === 'ArrowUp') {
                    event.preventDefault();
                    onChange(Math.min(max, rating + 1));
                } else if (
                    event.key === 'ArrowLeft' ||
                    event.key === 'ArrowDown'
                ) {
                    event.preventDefault();
                    onChange(Math.max(0, rating - 1) || null);
                } else if (/^[0-9]$/.test(event.key)) {
                    const next = Math.min(max, Number(event.key));

                    onChange(next === 0 ? null : next);
                }
            }}
        >
            <Stars
                value={rating}
                max={max}
                onChange={(next) => onChange(next === 0 ? null : next)}
            />
        </div>
    );
}

/* Dates */

function pad(part: number): string {
    return String(part).padStart(2, '0');
}

/** A stored date as an <input type="date|datetime-local"> value. */
function toInputValue(value: CellValue, includeTime: boolean): string {
    if (typeof value !== 'string' || value === '') {
        return '';
    }

    const date = parseDate(value);

    if (!date) {
        return '';
    }

    if (!includeTime) {
        return toDateString(date);
    }

    return `${toDateString(date)}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

/** An input's value as a stored date: "YYYY-MM-DD", or ISO with the offset when the field has a time. */
function fromInputValue(text: string, includeTime: boolean): string | null {
    if (text === '') {
        return null;
    }

    if (!includeTime) {
        return /^\d{4}-\d{2}-\d{2}$/.test(text) ? text : null;
    }

    const date = new Date(text);

    return Number.isNaN(date.getTime()) ? null : date.toISOString();
}

/** Native inputs report a value while the year is still being typed ("0002-…"); skip those. */
function plausibleYear(text: string): boolean {
    return Number(text.slice(0, 4)) >= 1000;
}

function DateInput({
    field,
    value,
    onChange,
    onDone,
    autoFocus,
    variant,
}: ValueInputProps) {
    const includeTime = field.options.includeTime === true;
    const [draft, setDraft] = useState<string | null>(null);
    const shown = draft ?? toInputValue(value, includeTime);

    const commit = (text: string) => {
        if (text !== '' && !plausibleYear(text)) {
            return;
        }

        const next = fromInputValue(text, includeTime);

        if (text !== '' && next === null) {
            return;
        }

        if (!sameValue(next, value)) {
            onChange(next);
        }
    };

    const today = () => {
        const now = new Date();

        setDraft(null);
        onChange(includeTime ? now.toISOString() : toDateString(now));
        onDone?.();
    };

    return (
        <div className="flex min-w-0 flex-col gap-2">
            <input
                type={includeTime ? 'datetime-local' : 'date'}
                aria-label={field.name}
                className={cn(inputClass, 'tabular-nums')}
                value={shown}
                autoFocus={autoFocus}
                onChange={(event) => {
                    const text = event.target.value;

                    // In a popover the native picker is the editor: take each complete pick.
                    if (variant === 'popover') {
                        setDraft(null);
                        commit(text);
                    } else {
                        setDraft(text);
                    }
                }}
                onBlur={() => {
                    if (draft !== null) {
                        commit(draft);
                        setDraft(null);
                    }
                }}
                onKeyDown={(event) => {
                    if (event.key === 'Enter') {
                        event.preventDefault();

                        if (draft !== null) {
                            commit(draft);
                            setDraft(null);
                        }

                        onDone?.();
                    }
                }}
            />
            {variant === 'popover' && (
                <div className="flex items-center justify-between text-xs">
                    <button
                        type="button"
                        className="rounded px-1.5 py-1 font-medium text-blue-600 hover:bg-blue-50 dark:text-blue-400 dark:hover:bg-blue-950"
                        onClick={today}
                    >
                        Today
                    </button>
                    {!isEmpty(value) && (
                        <button
                            type="button"
                            className="rounded px-1.5 py-1 text-neutral-500 hover:bg-neutral-100 hover:text-neutral-900 dark:hover:bg-neutral-800 dark:hover:text-neutral-100"
                            onClick={() => {
                                onChange(null);
                                onDone?.();
                            }}
                        >
                            Clear
                        </button>
                    )}
                </div>
            )}
        </div>
    );
}

/* Searchable lists */

interface ListItem {
    key: string;
    /** What the search matches */
    label: string;
    content: ReactNode;
    selected: boolean;
}

const MAX_LIST_ITEMS = 200;

function SearchList({
    items,
    onPick,
    placeholder,
    autoFocus,
    emptyText,
    onCreate,
    onClear,
}: {
    items: ListItem[];
    onPick: (key: string) => void;
    placeholder: string;
    autoFocus?: boolean;
    emptyText: string;
    /** Offers to add what's typed when it isn't in the list */
    onCreate?: (name: string) => void;
    /** Offers a "Clear" row when something is chosen */
    onClear?: () => void;
}) {
    const [query, setQuery] = useState('');
    const [active, setActive] = useState(0);
    const listRef = useRef<HTMLDivElement>(null);
    const needle = query.trim().toLowerCase();
    const matches = useMemo(
        () =>
            needle === ''
                ? items
                : items.filter((item) =>
                      item.label.toLowerCase().includes(needle),
                  ),
        [items, needle],
    );
    const shown = matches.slice(0, MAX_LIST_ITEMS);
    const canCreate =
        onCreate !== undefined &&
        needle !== '' &&
        !items.some((item) => item.label.toLowerCase() === needle);
    const count = shown.length + (canCreate ? 1 : 0);
    const current = Math.min(active, Math.max(0, count - 1));

    useEffect(() => {
        listRef.current
            ?.querySelector(`[data-index="${current}"]`)
            ?.scrollIntoView({ block: 'nearest' });
    }, [current]);

    const choose = (index: number) => {
        if (index < shown.length) {
            onPick(shown[index].key);
        } else if (canCreate) {
            onCreate?.(query.trim());
            setQuery('');
        }
    };

    const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setActive(count === 0 ? 0 : (current + 1) % count);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActive(count === 0 ? 0 : (current - 1 + count) % count);
        } else if (event.key === 'Enter') {
            event.preventDefault();

            if (count > 0) {
                choose(current);
            }
        }
    };

    return (
        <div className="flex min-w-0 flex-col gap-1.5">
            <input
                type="text"
                role="combobox"
                aria-expanded="true"
                aria-label={placeholder}
                placeholder={placeholder}
                className={inputClass}
                value={query}
                autoFocus={autoFocus}
                onChange={(event) => {
                    setQuery(event.target.value);
                    setActive(0);
                }}
                onKeyDown={onKeyDown}
            />
            <div
                ref={listRef}
                role="listbox"
                className="-mx-1 max-h-64 overflow-y-auto px-1"
            >
                {shown.map((item, index) => (
                    <div
                        key={item.key}
                        role="option"
                        aria-selected={item.selected}
                        data-index={index}
                        className={cn(
                            'flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm',
                            index === current &&
                                'bg-neutral-100 dark:bg-neutral-800',
                        )}
                        onMouseMove={() => {
                            if (active !== index) {
                                setActive(index);
                            }
                        }}
                        onMouseDown={(event) => event.preventDefault()}
                        onClick={() => choose(index)}
                    >
                        <span className="flex min-w-0 flex-1 items-center">
                            {item.content}
                        </span>
                        {item.selected && (
                            <Check className="size-3.5 shrink-0 text-blue-600 dark:text-blue-400" />
                        )}
                    </div>
                ))}
                {canCreate && (
                    <div
                        role="option"
                        aria-selected={false}
                        data-index={shown.length}
                        className={cn(
                            'flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm text-neutral-700 dark:text-neutral-300',
                            current === shown.length &&
                                'bg-neutral-100 dark:bg-neutral-800',
                        )}
                        onMouseMove={() => setActive(shown.length)}
                        onMouseDown={(event) => event.preventDefault()}
                        onClick={() => choose(shown.length)}
                    >
                        <Plus className="size-3.5 shrink-0" />
                        <span className="truncate">Add “{query.trim()}”</span>
                    </div>
                )}
                {count === 0 && (
                    <p className="px-2 py-3 text-center text-xs text-neutral-500">
                        {needle === '' ? emptyText : 'No matches'}
                    </p>
                )}
                {matches.length > shown.length && (
                    <p className="px-2 py-1.5 text-xs text-neutral-500">
                        {matches.length - shown.length} more. Keep typing to
                        narrow it down.
                    </p>
                )}
            </div>
            {onClear && (
                <button
                    type="button"
                    className="self-start rounded px-1.5 py-1 text-xs text-neutral-500 hover:bg-neutral-100 hover:text-neutral-900 dark:hover:bg-neutral-800 dark:hover:text-neutral-100"
                    onClick={onClear}
                >
                    Clear
                </button>
            )}
        </div>
    );
}

function RemoveButton({
    label,
    onClick,
}: {
    label: string;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            aria-label={label}
            className="rounded-full p-0.5 text-neutral-400 hover:bg-neutral-200 hover:text-neutral-700 dark:hover:bg-neutral-700 dark:hover:text-neutral-200"
            onClick={(event) => {
                event.stopPropagation();
                onClick();
            }}
            onKeyDown={(event) => event.stopPropagation()}
        >
            <X className="size-3" />
        </button>
    );
}

/* Selects */

function useMayAddChoices(field: Field): boolean {
    const store = useTableStore();

    return store.can.manageFields && !field.builtIn;
}

function SelectInput({
    field,
    value,
    onChange,
    onDone,
    autoFocus,
}: ValueInputProps) {
    const mayAdd = useMayAddChoices(field);
    const items = (field.options.choices ?? []).map((choice) => ({
        key: choice.name,
        label: choice.name,
        content: <ChoicePill field={field} name={choice.name} />,
        selected: value === choice.name,
    }));
    const pick = (name: string) => {
        if (name !== value) {
            onChange(name);
        }

        onDone?.();
    };

    return (
        <SearchList
            items={items}
            placeholder="Find an option"
            emptyText="No options yet"
            autoFocus={autoFocus ?? true}
            onPick={pick}
            onCreate={mayAdd ? pick : undefined}
            onClear={
                isEmpty(value)
                    ? undefined
                    : () => {
                          onChange(null);
                          onDone?.();
                      }
            }
        />
    );
}

function MultiSelectInput({
    field,
    value,
    onChange,
    autoFocus,
}: ValueInputProps) {
    const mayAdd = useMayAddChoices(field);
    const chosen = Array.isArray(value) ? (value as string[]) : [];
    const toggle = (name: string) =>
        onChange(
            chosen.includes(name)
                ? chosen.filter((candidate) => candidate !== name)
                : [...chosen, name],
        );
    const items = (field.options.choices ?? []).map((choice) => ({
        key: choice.name,
        label: choice.name,
        content: <ChoicePill field={field} name={choice.name} />,
        selected: chosen.includes(choice.name),
    }));

    return (
        <div className="flex min-w-0 flex-col gap-2">
            {chosen.length > 0 && (
                <div className="flex flex-wrap gap-1">
                    {chosen.map((name) => (
                        <span
                            key={name}
                            className="inline-flex items-center gap-0.5"
                        >
                            <ChoicePill field={field} name={name} />
                            <RemoveButton
                                label={`Remove ${name}`}
                                onClick={() => toggle(name)}
                            />
                        </span>
                    ))}
                </div>
            )}
            <SearchList
                items={items}
                placeholder="Find an option"
                emptyText="No options yet"
                autoFocus={autoFocus ?? true}
                onPick={toggle}
                onCreate={
                    mayAdd
                        ? (name) =>
                              chosen.includes(name)
                                  ? undefined
                                  : onChange([...chosen, name])
                        : undefined
                }
            />
        </div>
    );
}

/* People */

function UserInput({ value, onChange, onDone, autoFocus }: ValueInputProps) {
    const store = useTableStore();
    const items = store.users.map((user) => ({
        key: String(user.id),
        label: `${user.name} ${user.email}`,
        content: <UserChip user={user} />,
        selected: value === user.id,
    }));

    return (
        <SearchList
            items={items}
            placeholder="Find a person"
            emptyText="No people to pick from"
            autoFocus={autoFocus ?? true}
            onPick={(key) => {
                if (Number(key) !== value) {
                    onChange(Number(key));
                }

                onDone?.();
            }}
            onClear={
                isEmpty(value)
                    ? undefined
                    : () => {
                          onChange(null);
                          onDone?.();
                      }
            }
        />
    );
}

/* Links */

/** The records a link field can point to, with their titles. */
function useLinkTargets(field: Field): { id: number; title: string }[] {
    const store = useTableStore();
    const table = field.options.table ?? '';
    const titles = store.context.titles.get(table);
    const linked = store.context.linked[table];

    return useMemo(() => {
        if (titles) {
            return [...titles].map(([id, title]) => ({ id, title }));
        }

        return linked?.records ?? [];
    }, [titles, linked]);
}

function LinkInput({
    field,
    value,
    onChange,
    onDone,
    autoFocus,
}: ValueInputProps) {
    const store = useTableStore();
    const targets = useLinkTargets(field);
    const multiple = field.options.multiple === true;
    const chosen = Array.isArray(value) ? (value as number[]) : [];
    const titles = store.context.titles.get(field.options.table ?? '');
    const items = targets.map((target) => ({
        key: String(target.id),
        label: target.title,
        content: <span className="truncate">{target.title || 'Untitled'}</span>,
        selected: chosen.includes(target.id),
    }));
    const tableName = store.context.linked[field.options.table ?? '']?.name;

    return (
        <div className="flex min-w-0 flex-col gap-2">
            {chosen.length > 0 && (
                <div className="flex flex-wrap gap-1">
                    {chosen.map((id) => (
                        <span
                            key={id}
                            className="inline-flex items-center gap-0.5"
                        >
                            <LinkChip title={titles?.get(id) ?? 'Untitled'} />
                            <RemoveButton
                                label={`Remove ${titles?.get(id) ?? 'Untitled'}`}
                                onClick={() =>
                                    onChange(
                                        chosen.filter(
                                            (candidate) => candidate !== id,
                                        ),
                                    )
                                }
                            />
                        </span>
                    ))}
                </div>
            )}
            <SearchList
                items={items}
                placeholder={
                    tableName
                        ? `Find a record in ${tableName}`
                        : 'Find a record'
                }
                emptyText="No records to link"
                autoFocus={autoFocus ?? true}
                onPick={(key) => {
                    const id = Number(key);

                    if (multiple) {
                        onChange(
                            chosen.includes(id)
                                ? chosen.filter((candidate) => candidate !== id)
                                : [...chosen, id],
                        );

                        return;
                    }

                    if (!(chosen.length === 1 && chosen[0] === id)) {
                        onChange([id]);
                    }

                    onDone?.();
                }}
            />
        </div>
    );
}

/* Attachments */

interface Upload {
    id: number;
    name: string;
    size: number;
    error: string | null;
}

let uploadIds = 0;

function AttachmentInput({ field, value, onChange }: ValueInputProps) {
    const store = useTableStore();
    const files = Array.isArray(value) ? (value as Attachment[]) : [];
    const [uploads, setUploads] = useState<Upload[]>([]);
    const [dragging, setDragging] = useState(false);
    const picker = useRef<HTMLInputElement>(null);
    const latest = useRef(files);

    useEffect(() => {
        latest.current = files;
    });

    const upload = (list: FileList | File[]) => {
        for (const file of Array.from(list)) {
            uploadIds += 1;

            const id = uploadIds;
            const body = new FormData();

            body.append('file', file);
            setUploads((current) => [
                ...current,
                { id, name: file.name, size: file.size, error: null },
            ]);

            request<{ attachment: Attachment }>(
                'POST',
                store.uploadUrl ?? `${store.endpoint}/attachments`,
                body,
            )
                .then((result) => {
                    const next = [...latest.current, result.attachment];

                    latest.current = next;
                    onChange(next);
                    setUploads((current) =>
                        current.filter((candidate) => candidate.id !== id),
                    );
                })
                .catch((failure: unknown) => {
                    const message =
                        failure instanceof TableRequestError
                            ? failure.firstErrors().join(' ')
                            : "Couldn't upload this file.";

                    setUploads((current) =>
                        current.map((candidate) =>
                            candidate.id === id
                                ? { ...candidate, error: message }
                                : candidate,
                        ),
                    );
                });
        }
    };

    return (
        <div
            className={cn(
                'flex min-w-0 flex-col gap-2 rounded-md border border-dashed border-transparent p-0.5 transition-colors',
                dragging &&
                    'border-blue-400 bg-blue-50/60 dark:border-blue-600 dark:bg-blue-950/40',
            )}
            onDragOver={(event) => {
                if (event.dataTransfer.types.includes('Files')) {
                    event.preventDefault();
                    setDragging(true);
                }
            }}
            onDragLeave={(event) => {
                if (
                    !event.currentTarget.contains(event.relatedTarget as Node)
                ) {
                    setDragging(false);
                }
            }}
            onDrop={(event) => {
                if (event.dataTransfer.files.length > 0) {
                    event.preventDefault();
                    setDragging(false);
                    upload(event.dataTransfer.files);
                }
            }}
        >
            {(files.length > 0 || uploads.length > 0) && (
                <ul className="flex flex-col gap-1">
                    {files.map((file) => (
                        <li
                            key={file.key}
                            className="group flex items-center gap-2.5 rounded-md border border-neutral-200 p-1.5 dark:border-neutral-800"
                        >
                            {isImage(file) ? (
                                <img
                                    src={file.url}
                                    alt=""
                                    loading="lazy"
                                    className="size-9 shrink-0 rounded object-cover"
                                />
                            ) : (
                                <span className="flex size-9 shrink-0 items-center justify-center rounded bg-neutral-100 text-neutral-500 dark:bg-neutral-800">
                                    <File className="size-4" />
                                </span>
                            )}
                            <span className="flex min-w-0 flex-1 flex-col">
                                <a
                                    href={file.url}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="truncate text-sm hover:underline"
                                >
                                    {file.name}
                                </a>
                                <span className="text-xs text-neutral-500">
                                    {formatBytes(file.size)}
                                </span>
                            </span>
                            <button
                                type="button"
                                aria-label={`Remove ${file.name}`}
                                className="rounded p-1 text-neutral-400 hover:bg-neutral-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                                onClick={() =>
                                    onChange(
                                        files.filter(
                                            (candidate) =>
                                                candidate.key !== file.key,
                                        ),
                                    )
                                }
                            >
                                <X className="size-3.5" />
                            </button>
                        </li>
                    ))}
                    {uploads.map((item) => (
                        <li
                            key={item.id}
                            className={cn(
                                'flex items-center gap-2.5 rounded-md border p-1.5',
                                item.error
                                    ? 'border-red-200 bg-red-50 dark:border-red-900 dark:bg-red-950/40'
                                    : 'border-neutral-200 dark:border-neutral-800',
                            )}
                        >
                            <span className="flex size-9 shrink-0 items-center justify-center rounded bg-neutral-100 text-neutral-500 dark:bg-neutral-800">
                                {item.error ? (
                                    <File className="size-4 text-red-500" />
                                ) : (
                                    <LoaderCircle className="size-4 animate-spin" />
                                )}
                            </span>
                            <span className="flex min-w-0 flex-1 flex-col gap-1">
                                <span className="truncate text-sm">
                                    {item.name}
                                </span>
                                {item.error ? (
                                    <span className="text-xs text-red-600 dark:text-red-400">
                                        {item.error}
                                    </span>
                                ) : (
                                    <span className="flex items-center gap-2 text-xs text-neutral-500">
                                        <span className="h-1 flex-1 overflow-hidden rounded-full bg-neutral-200 dark:bg-neutral-800">
                                            <span className="block h-full w-1/2 animate-pulse rounded-full bg-blue-500" />
                                        </span>
                                        Uploading {formatBytes(item.size)}
                                    </span>
                                )}
                            </span>
                            {item.error && (
                                <button
                                    type="button"
                                    aria-label={`Dismiss ${item.name}`}
                                    className="rounded p-1 text-neutral-400 hover:bg-red-100 hover:text-neutral-700 dark:hover:bg-red-900"
                                    onClick={() =>
                                        setUploads((current) =>
                                            current.filter(
                                                (candidate) =>
                                                    candidate.id !== item.id,
                                            ),
                                        )
                                    }
                                >
                                    <X className="size-3.5" />
                                </button>
                            )}
                        </li>
                    ))}
                </ul>
            )}
            <button
                type="button"
                className="flex items-center justify-center gap-2 rounded-md border border-dashed border-neutral-300 px-3 py-2.5 text-sm text-neutral-600 transition-colors hover:border-neutral-400 hover:bg-neutral-50 hover:text-neutral-900 dark:border-neutral-700 dark:text-neutral-400 dark:hover:bg-neutral-900 dark:hover:text-neutral-100"
                onClick={() => picker.current?.click()}
            >
                <Upload className="size-4" />
                Upload files
                <span className="text-xs text-neutral-400">
                    or drop them here
                </span>
            </button>
            <input
                ref={picker}
                type="file"
                multiple
                aria-label={`Upload files to ${field.name}`}
                className="hidden"
                onChange={(event) => {
                    if (event.target.files) {
                        upload(event.target.files);
                    }

                    event.target.value = '';
                }}
            />
        </div>
    );
}

/* Pickers in the record panel: the value, opening the picker in a popover */

function FormPicker(props: ValueInputProps) {
    const { field, value, onChange } = props;
    const store = useTableStore();
    const [open, setOpen] = useState(false);
    const titles = store.context.titles.get(field.options.table ?? '');

    let content: ReactNode = null;

    if (field.type === 'select' && typeof value === 'string' && value !== '') {
        content = <ChoicePill field={field} name={value} />;
    } else if (field.type === 'multiSelect' && Array.isArray(value)) {
        const chosen = value as string[];

        content = chosen.map((name) => (
            <span key={name} className="inline-flex items-center gap-0.5">
                <ChoicePill field={field} name={name} />
                <RemoveButton
                    label={`Remove ${name}`}
                    onClick={() =>
                        onChange(
                            chosen.filter((candidate) => candidate !== name),
                        )
                    }
                />
            </span>
        ));
    } else if (field.type === 'user' && typeof value === 'number') {
        content = <UserChip user={store.context.usersById.get(value)} />;
    } else if (field.type === 'link' && Array.isArray(value)) {
        const chosen = value as number[];

        content = chosen.map((id) => (
            <span key={id} className="inline-flex items-center gap-0.5">
                <LinkChip title={titles?.get(id) ?? 'Untitled'} />
                <RemoveButton
                    label={`Remove ${titles?.get(id) ?? 'Untitled'}`}
                    onClick={() =>
                        onChange(chosen.filter((candidate) => candidate !== id))
                    }
                />
            </span>
        ));
    }

    const empty = isEmpty(value);
    const adds =
        field.type === 'multiSelect' ||
        (field.type === 'link' && field.options.multiple === true);

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <div
                    role="button"
                    tabIndex={0}
                    aria-label={`Edit ${field.name}`}
                    aria-haspopup="dialog"
                    aria-expanded={open}
                    title={
                        empty
                            ? undefined
                            : displayText(field, value, store.context)
                    }
                    className={cn(
                        'flex min-h-8 w-full min-w-0 cursor-pointer flex-wrap items-center gap-1 rounded-md border border-neutral-200 bg-white px-2 py-1 text-sm shadow-xs transition-colors outline-none hover:border-neutral-300 focus-visible:border-blue-500 focus-visible:ring-2 focus-visible:ring-blue-500/20 dark:border-neutral-800 dark:bg-neutral-950 dark:hover:border-neutral-700',
                        open && 'border-blue-500 ring-2 ring-blue-500/20',
                    )}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter' || event.key === ' ') {
                            event.preventDefault();
                            setOpen(true);
                        }
                    }}
                >
                    {content}
                    {(empty || adds) && (
                        <span className="inline-flex items-center gap-1 px-0.5 text-xs text-neutral-400">
                            {empty ? (
                                pickerPrompt(field)
                            ) : (
                                <Plus className="size-3.5" aria-hidden />
                            )}
                        </span>
                    )}
                </div>
            </PopoverTrigger>
            <PopoverContent className="w-72 p-2" align="start">
                <Editor
                    {...props}
                    variant="popover"
                    autoFocus
                    onDone={() => setOpen(false)}
                />
            </PopoverContent>
        </Popover>
    );
}

function pickerPrompt(field: Field): string {
    switch (field.type) {
        case 'select':
            return 'Pick an option';
        case 'multiSelect':
            return 'Pick options';
        case 'user':
            return 'Pick a person';
        default:
            return field.options.multiple ? 'Link records' : 'Link a record';
    }
}
