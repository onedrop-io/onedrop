import { FilterX, Plus } from 'lucide-react';
import { useMemo, useState } from 'react';
import type { DragEvent, ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import { CellView, ChoicePill, UserChip } from './cells';
import { FIELD_ICONS } from './field-icons';
import { displayText, isEditable, isEmpty, isImage } from './format';
import type { Attachment, CellValue, Field, TableRecord } from './types';
import { useTableStore } from './use-table';
import type { TableStore } from './use-table';

/* Helpers shared by the board, calendar and gallery views */

export function recordTitle(store: TableStore, record: TableRecord): string {
    const primary = store.fields.find((field) => field.primary);

    if (!primary) {
        return 'Untitled';
    }

    return (
        displayText(primary, record.values[primary.key], store.context) ||
        'Untitled'
    );
}

/** The first image in a record's cover field. */
export function coverImage(
    record: TableRecord,
    coverField: Field | undefined,
): Attachment | null {
    if (!coverField) {
        return null;
    }

    const files = record.values[coverField.key];

    if (!Array.isArray(files)) {
        return null;
    }

    return (files as Attachment[]).find((file) => isImage(file)) ?? null;
}

/** The view's attachment field used as card covers, if any. */
export function useCoverField(): Field | undefined {
    const store = useTableStore();
    const field = store.view.config.coverField
        ? store.fieldsByKey.get(store.view.config.coverField)
        : undefined;

    return field?.type === 'attachment' ? field : undefined;
}

/** Labeled values for a card: the shown fields after the title, skipping empty ones. */
export function CardFields({
    record,
    exclude,
    limit,
}: {
    record: TableRecord;
    exclude: string[];
    limit: number;
}) {
    const store = useTableStore();
    const fields = store.visibleFields
        .filter((field) => !field.primary && !exclude.includes(field.key))
        .filter(
            (field) =>
                !isEmpty(record.values[field.key]) ||
                record.errors?.[field.key] !== undefined,
        )
        .slice(0, limit);

    if (fields.length === 0) {
        return null;
    }

    return (
        <dl className="flex flex-col gap-1.5">
            {fields.map((field) => (
                <div key={field.key} className="flex min-w-0 flex-col gap-0.5">
                    <dt className="truncate text-[11px] font-medium text-neutral-500 dark:text-neutral-400">
                        {field.name}
                    </dt>
                    <dd className="min-w-0 text-xs text-neutral-800 dark:text-neutral-200">
                        <CellView
                            field={field}
                            value={record.values[field.key]}
                            error={record.errors?.[field.key]}
                            context={store.context}
                            wrap
                        />
                    </dd>
                </div>
            ))}
        </dl>
    );
}

/** Whether the view narrows its records (filters or a search). */
export function useIsFiltered(): boolean {
    const store = useTableStore();

    return (
        store.view.config.filters.conditions.length > 0 || store.search !== ''
    );
}

export function useClearFilters(): () => void {
    const store = useTableStore();

    return () => {
        store.setSearch('');

        if (store.view.config.filters.conditions.length > 0) {
            store.updateView({
                filters: { ...store.view.config.filters, conditions: [] },
            });
        }
    };
}

/** "No records match this view", with a way out when filters hide them. */
export function NoRecords({
    className,
    children,
}: {
    className?: string;
    children?: ReactNode;
}) {
    const store = useTableStore();
    const filtered = useIsFiltered();
    const clear = useClearFilters();
    const anyRecords = store.records.length > 0;

    return (
        <div
            className={cn(
                'flex flex-col items-center justify-center gap-3 px-6 py-16 text-center',
                className,
            )}
        >
            <p className="text-sm font-medium text-neutral-800 dark:text-neutral-200">
                {anyRecords && filtered
                    ? 'No records match this view'
                    : 'No records yet'}
            </p>
            {anyRecords && filtered ? (
                <Button variant="outline" size="sm" onClick={clear}>
                    <FilterX className="size-4" />
                    Clear filters
                </Button>
            ) : (
                children
            )}
        </div>
    );
}

/** A thin bar over a board or calendar when the view's filters hide every record. */
export function FilteredOutBar() {
    const store = useTableStore();
    const filtered = useIsFiltered();
    const clear = useClearFilters();

    if (store.rows.length > 0 || store.records.length === 0 || !filtered) {
        return null;
    }

    return (
        <div className="flex items-center justify-center gap-3 border-b border-neutral-200 bg-neutral-50 px-4 py-2 text-sm text-neutral-600 dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-400">
            No records match this view
            <Button variant="outline" size="sm" className="h-7" onClick={clear}>
                <FilterX className="size-3.5" />
                Clear filters
            </Button>
        </div>
    );
}

/** Asks which field a board or calendar goes by. */
export function PickField({
    title,
    description,
    label,
    fields,
    onPick,
    emptyText,
}: {
    title: string;
    description: string;
    label: string;
    fields: Field[];
    onPick: (key: string) => void;
    emptyText: string;
}) {
    const store = useTableStore();

    return (
        <div className="flex h-full min-h-64 flex-col items-center justify-center gap-4 px-6 py-16 text-center">
            <div className="flex max-w-sm flex-col gap-1">
                <p className="text-sm font-medium text-neutral-900 dark:text-neutral-100">
                    {title}
                </p>
                <p className="text-sm text-neutral-500 dark:text-neutral-400">
                    {fields.length > 0 ? description : emptyText}
                </p>
            </div>
            {fields.length > 0 && (
                <Select onValueChange={onPick}>
                    <SelectTrigger
                        aria-label={label}
                        className="w-64"
                        disabled={
                            !store.view.personal && !store.can.manageViews
                        }
                    >
                        <SelectValue placeholder={label} />
                    </SelectTrigger>
                    <SelectContent>
                        {fields.map((field) => {
                            const Icon = FIELD_ICONS[field.type];

                            return (
                                <SelectItem key={field.key} value={field.key}>
                                    <span className="flex items-center gap-2">
                                        <Icon className="size-3.5 text-neutral-500" />
                                        {field.name}
                                    </span>
                                </SelectItem>
                            );
                        })}
                    </SelectContent>
                </Select>
            )}
        </div>
    );
}

/** Creates a record with these values and opens it. */
export function useCreateAndOpen(): (
    values: Record<string, CellValue>,
) => Promise<void> {
    const store = useTableStore();

    return async (values) => {
        const record = await store.createRecord(values);

        if (record) {
            store.expand(record.id);
        }
    };
}

/* The board */

interface Column {
    key: string;
    value: CellValue;
    header: ReactNode;
    records: TableRecord[];
}

const NONE = '__none';
const PAGE = 200;

function columnKey(value: CellValue): string {
    if (typeof value === 'string' && value !== '') {
        return value;
    }

    return typeof value === 'number' ? String(value) : NONE;
}

export function BoardView() {
    const store = useTableStore();
    const coverField = useCoverField();
    const createAndOpen = useCreateAndOpen();
    const stackKey = store.view.config.stackBy;
    const stackField = stackKey ? store.fieldsByKey.get(stackKey) : undefined;
    const stackable = store.fields.filter(
        (field) => field.type === 'select' || field.type === 'user',
    );
    const [limits, setLimits] = useState<Record<string, number>>({});
    const [dragging, setDragging] = useState<number | null>(null);
    const [over, setOver] = useState<string | null>(null);

    const columns = useMemo<Column[]>(() => {
        if (
            !stackField ||
            (stackField.type !== 'select' && stackField.type !== 'user')
        ) {
            return [];
        }

        const list: Column[] = [
            {
                key: NONE,
                value: null,
                header: (
                    <span className="text-xs font-medium text-neutral-500">
                        Uncategorized
                    </span>
                ),
                records: [],
            },
        ];

        if (stackField.type === 'select') {
            for (const choice of stackField.options.choices ?? []) {
                list.push({
                    key: choice.name,
                    value: choice.name,
                    header: (
                        <ChoicePill field={stackField} name={choice.name} />
                    ),
                    records: [],
                });
            }
        } else {
            for (const user of store.users) {
                list.push({
                    key: String(user.id),
                    value: user.id,
                    header: (
                        <span className="text-xs font-medium">
                            <UserChip user={user} />
                        </span>
                    ),
                    records: [],
                });
            }
        }

        const byKey = new Map(list.map((column) => [column.key, column]));

        for (const record of store.rows) {
            const column =
                byKey.get(columnKey(record.values[stackField.key])) ??
                byKey.get(NONE)!;

            column.records.push(record);
        }

        // An empty "Uncategorized" column only takes room, like Airtable leaves it out.
        return list.filter(
            (column) => column.key !== NONE || column.records.length > 0,
        );
    }, [stackField, store.rows, store.users]);

    if (
        !stackField ||
        (stackField.type !== 'select' && stackField.type !== 'user')
    ) {
        return (
            <PickField
                title="Pick a field to stack records by"
                description="The board shows a column for each option of a single select field, or for each person. You can change it later in Customize."
                emptyText="Add a single select or person field to the table to use a board."
                label="Stack records by"
                fields={stackable}
                onPick={(key) => store.updateView({ stackBy: key })}
            />
        );
    }

    const canMove = isEditable(stackField) && store.can.edit;

    const dropOn = (event: DragEvent, column: Column) => {
        event.preventDefault();

        const id = Number(event.dataTransfer.getData('text/plain'));

        setOver(null);
        setDragging(null);

        const record = store.recordsById.get(id);

        if (record && columnKey(record.values[stackField.key]) !== column.key) {
            store.setCell(id, stackField.key, column.value);
        }
    };

    return (
        <div className="flex min-h-0 flex-1 flex-col">
            <FilteredOutBar />
            <div className="flex min-h-0 flex-1 gap-3 overflow-x-auto bg-neutral-50/60 p-3 dark:bg-neutral-950">
                {columns.map((column) => {
                    const limit = limits[column.key] ?? PAGE;
                    const isOver = over === column.key && dragging !== null;

                    return (
                        <section
                            key={column.key}
                            aria-label={
                                column.key === NONE
                                    ? 'Uncategorized'
                                    : typeof column.value === 'number'
                                      ? (store.context.usersById.get(
                                            column.value,
                                        )?.name ?? column.key)
                                      : column.key
                            }
                            className={cn(
                                'flex max-h-full w-72 shrink-0 flex-col rounded-lg border border-neutral-200 bg-neutral-100/70 transition-colors dark:border-neutral-800 dark:bg-neutral-900/70',
                                isOver &&
                                    'border-blue-400 bg-blue-50 dark:border-blue-700 dark:bg-blue-950/40',
                            )}
                            onDragOver={(event) => {
                                if (!canMove || dragging === null) {
                                    return;
                                }

                                event.preventDefault();
                                event.dataTransfer.dropEffect = 'move';

                                if (over !== column.key) {
                                    setOver(column.key);
                                }
                            }}
                            onDragLeave={(event) => {
                                if (
                                    !event.currentTarget.contains(
                                        event.relatedTarget as Node,
                                    )
                                ) {
                                    setOver((current) =>
                                        current === column.key ? null : current,
                                    );
                                }
                            }}
                            onDrop={(event) => {
                                if (canMove) {
                                    dropOn(event, column);
                                }
                            }}
                        >
                            <header className="flex items-center gap-2 px-3 pt-2.5 pb-2">
                                <span className="flex min-w-0 items-center">
                                    {column.header}
                                </span>
                                <span className="text-xs text-neutral-500 tabular-nums">
                                    {column.records.length}
                                </span>
                            </header>
                            <div className="flex min-h-12 flex-1 flex-col gap-2 overflow-y-auto px-2 pb-2">
                                {column.records
                                    .slice(0, limit)
                                    .map((record) => (
                                        <BoardCard
                                            key={record.id}
                                            record={record}
                                            coverField={coverField}
                                            exclude={[
                                                stackField.key,
                                                coverField?.key ?? '',
                                            ]}
                                            draggable={canMove}
                                            dragging={dragging === record.id}
                                            onDragStart={() =>
                                                setDragging(record.id)
                                            }
                                            onDragEnd={() => {
                                                setDragging(null);
                                                setOver(null);
                                            }}
                                        />
                                    ))}
                                {isOver && (
                                    <div className="h-14 shrink-0 rounded-md border-2 border-dashed border-blue-400 dark:border-blue-700" />
                                )}
                                {column.records.length > limit && (
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        className="text-neutral-600"
                                        onClick={() =>
                                            setLimits((current) => ({
                                                ...current,
                                                [column.key]: limit + PAGE,
                                            }))
                                        }
                                    >
                                        Show more (
                                        {column.records.length - limit})
                                    </Button>
                                )}
                            </div>
                            {store.can.create && (
                                <button
                                    type="button"
                                    className="mx-2 mb-2 flex items-center gap-1.5 rounded-md px-2 py-1.5 text-sm text-neutral-500 hover:bg-neutral-200/70 hover:text-neutral-900 dark:hover:bg-neutral-800 dark:hover:text-neutral-100"
                                    onClick={() =>
                                        void createAndOpen(
                                            column.value === null
                                                ? {}
                                                : {
                                                      [stackField.key]:
                                                          column.value,
                                                  },
                                        )
                                    }
                                >
                                    <Plus className="size-4" />
                                    New
                                </button>
                            )}
                        </section>
                    );
                })}
            </div>
        </div>
    );
}

function BoardCard({
    record,
    coverField,
    exclude,
    draggable,
    dragging,
    onDragStart,
    onDragEnd,
}: {
    record: TableRecord;
    coverField: Field | undefined;
    exclude: string[];
    draggable: boolean;
    dragging: boolean;
    onDragStart: () => void;
    onDragEnd: () => void;
}) {
    const store = useTableStore();
    const cover = coverImage(record, coverField);
    const title = recordTitle(store, record);

    return (
        <div
            role="button"
            tabIndex={0}
            aria-label={title}
            draggable={draggable}
            className={cn(
                'group flex shrink-0 cursor-pointer flex-col overflow-hidden rounded-md border border-neutral-200 bg-white text-left shadow-xs transition-shadow outline-none hover:shadow-md focus-visible:ring-2 focus-visible:ring-blue-500/40 dark:border-neutral-800 dark:bg-neutral-950',
                dragging && 'opacity-40',
            )}
            onClick={() => store.expand(record.id)}
            onKeyDown={(event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    store.expand(record.id);
                }
            }}
            onDragStart={(event) => {
                event.dataTransfer.setData('text/plain', String(record.id));
                event.dataTransfer.effectAllowed = 'move';
                onDragStart();
            }}
            onDragEnd={onDragEnd}
        >
            {cover && (
                <img
                    src={cover.url}
                    alt=""
                    loading="lazy"
                    draggable={false}
                    className="h-32 w-full object-cover"
                />
            )}
            <div className="flex flex-col gap-2 p-2.5">
                <p
                    className={cn(
                        'line-clamp-2 text-sm font-medium',
                        title === 'Untitled' && 'text-neutral-400',
                    )}
                >
                    {title}
                </p>
                <CardFields record={record} exclude={exclude} limit={3} />
            </div>
        </div>
    );
}
