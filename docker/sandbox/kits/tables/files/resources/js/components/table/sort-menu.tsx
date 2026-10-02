import { ArrowUpDown, GripVertical, Plus, X } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import type { ComponentProps, DragEvent, KeyboardEvent } from 'react';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import { FIELD_ICONS } from './field-icons';
import { valueKind } from './format';
import { Popover, PopoverContent, PopoverTrigger } from './popover';
import type { Field, SortRule } from './types';
import { useTableStore } from './use-table';

/*
 * The sort menu, and the pieces the other toolbar menus share: the toolbar button, field pickers,
 * drag to reorder, and the list of sort or group rules.
 */

export type ToolbarTint = 'blue' | 'amber' | 'violet' | 'orange';

const TINTS: Record<ToolbarTint, string> = {
    blue: 'bg-blue-100 text-blue-900 hover:bg-blue-200/70 dark:bg-blue-950 dark:text-blue-200 dark:hover:bg-blue-900/70',
    amber: 'bg-amber-100 text-amber-900 hover:bg-amber-200/70 dark:bg-amber-950 dark:text-amber-200 dark:hover:bg-amber-900/70',
    violet: 'bg-violet-100 text-violet-900 hover:bg-violet-200/70 dark:bg-violet-950 dark:text-violet-200 dark:hover:bg-violet-900/70',
    orange: 'bg-orange-100 text-orange-900 hover:bg-orange-200/70 dark:bg-orange-950 dark:text-orange-200 dark:hover:bg-orange-900/70',
};

/** A compact toolbar button: an icon and a label, tinted when its setting is in use. */
export function ToolbarButton({
    icon: Icon,
    label,
    tint,
    className,
    ...props
}: ComponentProps<'button'> & {
    icon: LucideIcon;
    label?: string;
    tint?: ToolbarTint | null;
}) {
    return (
        <button
            type="button"
            className={cn(
                'inline-flex h-7 shrink-0 items-center gap-1.5 rounded-md px-2 text-[13px] whitespace-nowrap transition-colors outline-none focus-visible:ring-2 focus-visible:ring-blue-500/50 disabled:pointer-events-none disabled:opacity-50',
                tint
                    ? TINTS[tint]
                    : 'text-neutral-600 hover:bg-neutral-100 hover:text-neutral-900 data-[state=open]:bg-neutral-100 dark:text-neutral-400 dark:hover:bg-neutral-800 dark:hover:text-neutral-100 dark:data-[state=open]:bg-neutral-800',
                className,
            )}
            {...props}
        >
            <Icon className="size-4 shrink-0" />
            {label && <span>{label}</span>}
        </button>
    );
}

export function plural(count: number, word: string): string {
    return `${count.toLocaleString()} ${word}${count === 1 ? '' : 's'}`;
}

export function FieldIcon({
    field,
    className,
}: {
    field: Field;
    className?: string;
}) {
    const Icon = FIELD_ICONS[field.type];

    return (
        <Icon className={cn('size-3.5 shrink-0 text-neutral-500', className)} />
    );
}

/** A searchable list of fields to pick one from, with arrow keys and Enter. */
export function FieldList({
    fields,
    onPick,
    placeholder = 'Find a field',
    emptyText = 'No fields match',
}: {
    fields: Field[];
    onPick: (field: Field) => void;
    placeholder?: string;
    emptyText?: string;
}) {
    const [query, setQuery] = useState('');
    const [active, setActive] = useState(0);
    const needle = query.trim().toLowerCase();
    const shown = fields.filter((field) =>
        field.name.toLowerCase().includes(needle),
    );
    const current = Math.min(active, shown.length - 1);

    const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setActive(Math.min(current + 1, shown.length - 1));
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActive(Math.max(current - 1, 0));
        } else if (event.key === 'Enter' && shown[current]) {
            event.preventDefault();
            onPick(shown[current]);
        }
    };

    return (
        <div className="flex flex-col">
            <input
                autoFocus
                value={query}
                onChange={(event) => {
                    setQuery(event.target.value);
                    setActive(0);
                }}
                onKeyDown={onKeyDown}
                placeholder={placeholder}
                aria-label={placeholder}
                className="mb-1 h-8 w-full rounded-md border-0 border-b border-neutral-200 bg-transparent px-2 text-sm outline-none placeholder:text-neutral-400 dark:border-neutral-800"
            />
            <div role="listbox" className="max-h-64 overflow-y-auto">
                {shown.map((field, index) => (
                    <button
                        key={field.key}
                        type="button"
                        role="option"
                        aria-selected={index === current}
                        onMouseEnter={() => setActive(index)}
                        onClick={() => onPick(field)}
                        className={cn(
                            'flex w-full items-center gap-2 rounded px-2 py-1.5 text-left text-[13px]',
                            index === current &&
                                'bg-neutral-100 dark:bg-neutral-800',
                        )}
                    >
                        <FieldIcon field={field} />
                        <span className="truncate">{field.name}</span>
                    </button>
                ))}
                {shown.length === 0 && (
                    <p className="px-2 py-1.5 text-[13px] text-neutral-500">
                        {emptyText}
                    </p>
                )}
            </div>
        </div>
    );
}

/** A compact dropdown of fields. */
export function FieldSelect({
    fields,
    value,
    onChange,
    className,
    label = 'Field',
}: {
    fields: Field[];
    value: string;
    onChange: (key: string) => void;
    className?: string;
    label?: string;
}) {
    return (
        <Select value={value} onValueChange={onChange}>
            <SelectTrigger
                aria-label={label}
                className={cn(
                    'h-7 w-40 px-2 text-[13px] shadow-none',
                    className,
                )}
            >
                <SelectValue placeholder="Pick a field" />
            </SelectTrigger>
            <SelectContent>
                {fields.map((field) => (
                    <SelectItem
                        key={field.key}
                        value={field.key}
                        className="text-[13px]"
                    >
                        <FieldIcon field={field} />
                        <span className="truncate">{field.name}</span>
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

/** The list with `from` moved to where `to` is. */
export function moveItem<T>(items: T[], from: T, to: T): T[] {
    const fromIndex = items.indexOf(from);
    const toIndex = items.indexOf(to);

    if (fromIndex === -1 || toIndex === -1 || fromIndex === toIndex) {
        return items;
    }

    const next = [...items];

    next.splice(fromIndex, 1);
    next.splice(toIndex, 0, from);

    return next;
}

/**
 * Reordering a list by dragging its rows' handles (HTML5 drag and drop), or with Alt + arrow keys on a handle.
 * Spread `rowProps(id)` on each row and `handleProps(id)` on its handle.
 */
export function useDragReorder(
    ids: string[],
    onReorder: (ids: string[]) => void,
) {
    const [dragging, setDragging] = useState<string | null>(null);
    const [over, setOver] = useState<string | null>(null);

    const reset = () => {
        setDragging(null);
        setOver(null);
    };

    const handleProps = (id: string) => ({
        draggable: true,
        onDragStart: (event: DragEvent<HTMLElement>) => {
            const row = event.currentTarget.closest('[data-drag-row]');

            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', id);

            if (row instanceof HTMLElement) {
                event.dataTransfer.setDragImage(row, 16, 14);
            }

            setDragging(id);
        },
        onDragEnd: reset,
        onKeyDown: (event: KeyboardEvent<HTMLElement>) => {
            if (!event.altKey) {
                return;
            }

            const index = ids.indexOf(id);
            const target =
                event.key === 'ArrowUp'
                    ? ids[index - 1]
                    : event.key === 'ArrowDown'
                      ? ids[index + 1]
                      : undefined;

            if (target !== undefined) {
                event.preventDefault();
                onReorder(moveItem(ids, id, target));
            }
        },
    });

    const rowProps = (id: string) => ({
        'data-drag-row': true,
        onDragOver: (event: DragEvent<HTMLElement>) => {
            if (dragging === null) {
                return;
            }

            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';

            if (over !== id) {
                setOver(id);
            }
        },
        onDrop: (event: DragEvent<HTMLElement>) => {
            if (dragging === null) {
                return;
            }

            event.preventDefault();

            if (dragging !== id) {
                onReorder(moveItem(ids, dragging, id));
            }

            reset();
        },
    });

    return { dragging, over, handleProps, rowProps };
}

export function DragHandle(props: ComponentProps<'span'>) {
    return (
        <span
            role="button"
            tabIndex={0}
            aria-label="Drag to reorder"
            title="Drag to reorder (or Alt + arrow keys)"
            className="flex size-6 shrink-0 cursor-grab items-center justify-center rounded text-neutral-400 outline-none hover:text-neutral-600 focus-visible:ring-2 focus-visible:ring-blue-500/50 active:cursor-grabbing dark:hover:text-neutral-300"
            {...props}
        >
            <GripVertical className="size-3.5" />
        </span>
    );
}

/** What ascending and descending read as for a field. */
export function directionLabels(field: Field): [string, string] {
    switch (valueKind(field)) {
        case 'number':
            return ['1 → 9', '9 → 1'];
        case 'date':
            return ['Old → new', 'New → old'];
        case 'choice':
        case 'choices':
            return ['First → last', 'Last → first'];
        case 'boolean':
            return ['Unchecked → checked', 'Checked → unchecked'];
        default:
            return ['A → Z', 'Z → A'];
    }
}

/** A list of sort or group rules: a field and a direction each, reordered by dragging. */
export function RuleEditor({
    rules,
    fields,
    onChange,
    max,
    addLabel,
    emptyText,
}: {
    rules: SortRule[];
    /** The fields rules may use */
    fields: Field[];
    onChange: (rules: SortRule[]) => void;
    max?: number;
    addLabel: string;
    emptyText: string;
}) {
    const [adding, setAdding] = useState(false);
    const byKey = new Map(fields.map((field) => [field.key, field]));
    const shown = rules.filter((rule) => byKey.has(rule.field));
    const used = new Set(shown.map((rule) => rule.field));
    const unused = fields.filter((field) => !used.has(field.key));
    const { dragging, over, handleProps, rowProps } = useDragReorder(
        shown.map((rule) => rule.field),
        (keys) =>
            onChange(
                keys.map((key) => shown.find((rule) => rule.field === key)!),
            ),
    );

    const update = (index: number, patch: Partial<SortRule>) =>
        onChange(
            shown.map((rule, position) =>
                position === index ? { ...rule, ...patch } : rule,
            ),
        );

    if (shown.length === 0) {
        return (
            <div className="p-2">
                <p className="px-2 pt-1 pb-2 text-xs text-neutral-500">
                    {emptyText}
                </p>
                <FieldList
                    fields={fields}
                    onPick={(field) =>
                        onChange([{ field: field.key, direction: 'asc' }])
                    }
                />
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-1 p-3">
            {shown.map((rule, index) => {
                const field = byKey.get(rule.field)!;
                const [ascending, descending] = directionLabels(field);
                const choices = fields.filter(
                    (candidate) =>
                        candidate.key === rule.field ||
                        !used.has(candidate.key),
                );

                return (
                    <div
                        key={rule.field}
                        {...rowProps(rule.field)}
                        className={cn(
                            'flex items-center gap-1.5 rounded-md',
                            dragging === rule.field && 'opacity-50',
                            over === rule.field &&
                                dragging !== rule.field &&
                                'ring-2 ring-blue-500/40',
                        )}
                    >
                        <DragHandle {...handleProps(rule.field)} />
                        <FieldSelect
                            fields={choices}
                            value={rule.field}
                            onChange={(key) => update(index, { field: key })}
                            className="w-44"
                        />
                        <Select
                            value={rule.direction}
                            onValueChange={(direction) =>
                                update(index, {
                                    direction:
                                        direction as SortRule['direction'],
                                })
                            }
                        >
                            <SelectTrigger
                                aria-label="Direction"
                                className="h-7 w-40 px-2 text-[13px] shadow-none"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="asc" className="text-[13px]">
                                    {ascending}
                                </SelectItem>
                                <SelectItem
                                    value="desc"
                                    className="text-[13px]"
                                >
                                    {descending}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <button
                            type="button"
                            aria-label={`Remove ${field.name}`}
                            onClick={() =>
                                onChange(
                                    shown.filter(
                                        (_, position) => position !== index,
                                    ),
                                )
                            }
                            className="flex size-7 items-center justify-center rounded text-neutral-400 hover:bg-neutral-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                        >
                            <X className="size-3.5" />
                        </button>
                    </div>
                );
            })}
            {unused.length > 0 && (max === undefined || shown.length < max) && (
                <Popover open={adding} onOpenChange={setAdding}>
                    <PopoverTrigger asChild>
                        <button
                            type="button"
                            className="mt-1 inline-flex w-fit items-center gap-1.5 rounded px-1.5 py-1 text-[13px] text-neutral-600 hover:bg-neutral-100 hover:text-neutral-900 dark:text-neutral-400 dark:hover:bg-neutral-800 dark:hover:text-neutral-100"
                        >
                            <Plus className="size-3.5" />
                            {addLabel}
                        </button>
                    </PopoverTrigger>
                    <PopoverContent className="w-60 p-1.5">
                        <FieldList
                            fields={unused}
                            onPick={(field) => {
                                onChange([
                                    ...shown,
                                    { field: field.key, direction: 'asc' },
                                ]);
                                setAdding(false);
                            }}
                        />
                    </PopoverContent>
                </Popover>
            )}
        </div>
    );
}

/** The "Sort" toolbar button and its menu. */
export function SortMenu() {
    const store = useTableStore();
    const sorts = store.view.config.sorts.filter((rule) =>
        store.fieldsByKey.has(rule.field),
    );

    return (
        <Popover>
            <PopoverTrigger asChild>
                <ToolbarButton
                    icon={ArrowUpDown}
                    label={
                        sorts.length > 0
                            ? `Sorted by ${plural(sorts.length, 'field')}`
                            : 'Sort'
                    }
                    tint={sorts.length > 0 ? 'orange' : null}
                />
            </PopoverTrigger>
            <PopoverContent
                className={cn('p-0', sorts.length > 0 ? 'w-auto' : 'w-72')}
            >
                <div className="border-b border-neutral-200 px-3 py-2 text-xs font-medium text-neutral-500 dark:border-neutral-800">
                    Sort by
                </div>
                <RuleEditor
                    rules={sorts}
                    fields={store.orderedFields}
                    onChange={(rules) => store.updateView({ sorts: rules })}
                    addLabel="Add another sort"
                    emptyText="Pick a field to sort by"
                />
            </PopoverContent>
        </Popover>
    );
}
