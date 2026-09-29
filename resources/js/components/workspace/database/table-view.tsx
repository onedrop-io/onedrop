import {
    ArrowDown,
    ArrowUp,
    ChevronLeft,
    ChevronRight,
    Filter,
    KeyRound,
    Plus,
    RefreshCw,
    Trash2,
    X,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import CellValue, {
    editableText,
    isPreview,
} from '@/components/workspace/database/cell-value';
import { databaseApi } from '@/lib/database-api';
import { cn } from '@/lib/utils';
import type {
    DatabaseColumn,
    DatabaseFilter,
    DatabaseFilterOperator,
    DatabaseRow,
    DatabaseRows,
    DatabaseValue,
} from '@/types';

const OPERATORS: { value: DatabaseFilterOperator; label: string }[] = [
    { value: 'eq', label: 'equals' },
    { value: 'neq', label: 'not equal' },
    { value: 'contains', label: 'contains' },
    { value: 'gt', label: '>' },
    { value: 'gte', label: '≥' },
    { value: 'lt', label: '<' },
    { value: 'lte', label: '≤' },
    { value: 'null', label: 'is NULL' },
    { value: 'notnull', label: 'is not NULL' },
];

const PAGE_SIZES = [25, 50, 100];

/** Passed to a cell's finish() when editing is cancelled; a symbol, since any string is a valid cell value. */
const CANCEL = Symbol('cancel');

type Sort = { column: string; direction: 'asc' | 'desc' } | null;

/** A cell being edited: an existing row by index on the page, or a new row. */
type EditTarget = { kind: 'row' | 'new'; index: number; column: string };

export default function TableView({
    projectId,
    connection,
    table,
    onDirtyChange,
}: {
    projectId: number;
    connection: string;
    table: string;
    onDirtyChange: (dirty: boolean) => void;
}) {
    const [data, setData] = useState<DatabaseRows | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const [reload, setReload] = useState(0);

    const [page, setPage] = useState(1);
    const [perPage, setPerPage] = useState(50);
    const [sort, setSort] = useState<Sort>(null);
    const [filters, setFilters] = useState<DatabaseFilter[]>([]);
    const [draftFilters, setDraftFilters] = useState<DatabaseFilter[]>([]);
    const [showFilters, setShowFilters] = useState(false);

    const [edits, setEdits] = useState<
        Record<number, Record<string, DatabaseValue>>
    >({});
    const [inserts, setInserts] = useState<Record<string, DatabaseValue>[]>([]);
    const [deletes, setDeletes] = useState<Set<number>>(new Set());
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [editing, setEditing] = useState<EditTarget | null>(null);
    const [saving, setSaving] = useState(false);
    const [saveError, setSaveError] = useState<string | null>(null);

    const changeCount =
        Object.values(edits).reduce(
            (sum, row) => sum + Object.keys(row).length,
            0,
        ) +
        inserts.length +
        deletes.size;
    const dirty = changeCount > 0;

    useEffect(() => onDirtyChange(dirty), [dirty, onDirtyChange]);

    useEffect(() => {
        let cancelled = false;
        setLoading(true);

        databaseApi
            .rows(projectId, {
                connection,
                table,
                page,
                perPage,
                sort,
                filters,
            })
            .then((body) => {
                if (!cancelled) {
                    setData(body);
                    setError(null);
                }
            })
            .catch((e: Error) => !cancelled && setError(e.message))
            .finally(() => !cancelled && setLoading(false));

        return () => {
            cancelled = true;
        };
    }, [projectId, connection, table, page, perPage, sort, filters, reload]);

    const columns = useMemo(() => data?.columns ?? [], [data]);
    const primary = columns.filter((column) => column.primary);
    const isTable = data?.type === 'table';
    const canEditRows = isTable && primary.length > 0;

    const clearChanges = () => {
        setEdits({});
        setInserts([]);
        setDeletes(new Set());
        setSelected(new Set());
        setEditing(null);
        setSaveError(null);
    };

    /** Run a change that reloads the grid, asking first if edits would be lost. */
    const navigate = (change: () => void) => {
        if (
            dirty &&
            !window.confirm('Discard your unsaved changes to this table?')
        ) {
            return;
        }

        clearChanges();
        change();
    };

    const toggleSort = (column: string) =>
        navigate(() =>
            setSort((current) =>
                current?.column !== column
                    ? { column, direction: 'asc' }
                    : current.direction === 'asc'
                      ? { column, direction: 'desc' }
                      : null,
            ),
        );

    const applyFilters = () =>
        navigate(() => {
            setFilters(draftFilters.filter((filter) => filter.column));
            setPage(1);
        });

    const commitEdit = (
        target: EditTarget,
        value: DatabaseValue | undefined,
    ) => {
        setEditing(null);

        if (target.kind === 'new') {
            setInserts((rows) =>
                rows.map((row, index) => {
                    if (index !== target.index) {
                        return row;
                    }

                    const next = { ...row };

                    if (value === undefined) {
                        delete next[target.column];
                    } else {
                        next[target.column] = value;
                    }

                    return next;
                }),
            );

            return;
        }

        const original = data?.rows[target.index]?.[target.column];

        setEdits((current) => {
            const row = { ...current[target.index] };
            const unchanged =
                value === undefined ||
                (value === null
                    ? original === null
                    : original !== undefined &&
                      original !== null &&
                      editableText(original) === value);

            if (unchanged) {
                delete row[target.column];
            } else {
                row[target.column] = value;
            }

            const next = { ...current };

            if (Object.keys(row).length === 0) {
                delete next[target.index];
            } else {
                next[target.index] = row;
            }

            return next;
        });
    };

    const keyOf = (row: DatabaseRow) =>
        Object.fromEntries(
            primary.map((column) => [column.name, row[column.name]]),
        );

    const save = async () => {
        if (!data) {
            return;
        }

        setSaving(true);
        setSaveError(null);

        try {
            await databaseApi.change(projectId, connection, table, {
                inserts,
                updates: Object.entries(edits)
                    .filter(([index]) => !deletes.has(Number(index)))
                    .map(([index, values]) => ({
                        key: keyOf(data.rows[Number(index)]),
                        values,
                    })),
                deletes: [...deletes].map((index) => keyOf(data.rows[index])),
            });
            clearChanges();
            setReload((n) => n + 1);
        } catch (e) {
            setSaveError((e as Error).message);
        } finally {
            setSaving(false);
        }
    };

    const markSelectedForDeletion = () => {
        setDeletes((current) => new Set([...current, ...selected]));
        setSelected(new Set());
    };

    const lastPage = data ? Math.max(1, Math.ceil(data.total / perPage)) : 1;
    const first = data && data.total > 0 ? (page - 1) * perPage + 1 : 0;
    const last = data ? Math.min(page * perPage, data.total) : 0;

    return (
        <div
            className="@container flex min-h-0 min-w-0 flex-1 flex-col"
            data-test="db-table-view"
        >
            <header className="flex flex-wrap items-center gap-2 border-b border-sidebar-border/70 px-3 py-2 dark:border-sidebar-border">
                <h3 className="mr-auto truncate font-mono text-sm font-medium">
                    {table}
                    {data && (
                        <span
                            className="ml-2 font-sans text-xs font-normal text-muted-foreground"
                            data-test="db-row-count"
                        >
                            {data.total.toLocaleString()}{' '}
                            {data.total === 1 ? 'row' : 'rows'}
                        </span>
                    )}
                </h3>

                {selected.size > 0 && (
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={markSelectedForDeletion}
                        aria-label={`Delete ${selected.size} selected rows`}
                        data-test="db-delete-selected"
                    >
                        <Trash2 />
                        <span className="hidden @xl:inline">Delete</span>{' '}
                        {selected.size}
                    </Button>
                )}
                <Button
                    size="sm"
                    variant={
                        showFilters || filters.length ? 'secondary' : 'ghost'
                    }
                    onClick={() => {
                        setDraftFilters(
                            filters.length
                                ? filters
                                : [
                                      {
                                          column: columns[0]?.name ?? '',
                                          operator: 'eq',
                                          value: '',
                                      },
                                  ],
                        );
                        setShowFilters((open) => !open);
                    }}
                    aria-label="Filters"
                    title="Filters"
                    data-test="db-filters-toggle"
                >
                    <Filter />
                    <span className="hidden @xl:inline">Filters</span>
                    {filters.length ? ` (${filters.length})` : ''}
                </Button>
                {isTable && (
                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => setInserts((rows) => [...rows, {}])}
                        aria-label="Add row"
                        title="Add row"
                        data-test="db-add-row"
                    >
                        <Plus />
                        <span className="hidden @xl:inline">Add row</span>
                    </Button>
                )}
                <Button
                    size="sm"
                    variant="ghost"
                    onClick={() => navigate(() => setReload((n) => n + 1))}
                    aria-label="Refresh rows"
                    title="Refresh rows"
                    data-test="db-refresh"
                >
                    <RefreshCw className={cn(loading && 'animate-spin')} />
                </Button>
            </header>

            {showFilters && (
                <FilterEditor
                    columns={columns}
                    filters={draftFilters}
                    onChange={setDraftFilters}
                    onApply={applyFilters}
                    onClear={() => {
                        setDraftFilters([]);
                        navigate(() => {
                            setFilters([]);
                            setPage(1);
                        });
                    }}
                />
            )}

            {data && !isTable && (
                <Notice>
                    This is a view, so its rows can't be edited here.
                </Notice>
            )}
            {data && isTable && primary.length === 0 && (
                <Notice>
                    This table has no primary key, so existing rows can only be
                    changed with the SQL runner.
                </Notice>
            )}

            <div className="min-h-0 flex-1 overflow-auto" data-test="db-grid">
                {error ? (
                    <p
                        className="p-6 text-sm text-red-600"
                        data-test="db-error"
                    >
                        {error}
                    </p>
                ) : !data ? (
                    <p className="p-6 text-sm text-muted-foreground">
                        Loading…
                    </p>
                ) : (
                    <table className="min-w-full border-separate border-spacing-0 text-xs">
                        <thead className="sticky top-0 z-10 bg-background">
                            <tr>
                                <th className="w-8 border-r border-b border-sidebar-border/70 px-2 dark:border-sidebar-border">
                                    {canEditRows && data.rows.length > 0 && (
                                        <input
                                            type="checkbox"
                                            aria-label="Select all rows"
                                            checked={
                                                selected.size ===
                                                data.rows.length
                                            }
                                            onChange={(event) =>
                                                setSelected(
                                                    event.target.checked
                                                        ? new Set(
                                                              data.rows.map(
                                                                  (_, i) => i,
                                                              ),
                                                          )
                                                        : new Set(),
                                                )
                                            }
                                        />
                                    )}
                                </th>
                                {columns.map((column) => (
                                    <th
                                        key={column.name}
                                        className="border-r border-b border-sidebar-border/70 p-0 text-left font-normal dark:border-sidebar-border"
                                    >
                                        <button
                                            type="button"
                                            onClick={() =>
                                                toggleSort(column.name)
                                            }
                                            className="flex w-full items-center gap-1.5 px-3 py-1.5 whitespace-nowrap hover:bg-muted"
                                            title={columnTitle(column)}
                                            data-test={`db-column-${column.name}`}
                                        >
                                            {column.primary && (
                                                <KeyRound
                                                    className="size-3 text-amber-500"
                                                    aria-label="Primary key"
                                                />
                                            )}
                                            <span className="font-medium">
                                                {column.name}
                                            </span>
                                            <span className="text-muted-foreground">
                                                {column.type}
                                            </span>
                                            {sort?.column === column.name &&
                                                (sort.direction === 'asc' ? (
                                                    <ArrowUp
                                                        className="size-3"
                                                        aria-label="Sorted ascending"
                                                    />
                                                ) : (
                                                    <ArrowDown
                                                        className="size-3"
                                                        aria-label="Sorted descending"
                                                    />
                                                ))}
                                        </button>
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {inserts.map((row, index) => (
                                <tr
                                    key={`new-${index}`}
                                    className="bg-green-500/10"
                                    data-test="db-new-row"
                                >
                                    <td className="border-r border-b border-sidebar-border/70 px-2 text-center dark:border-sidebar-border">
                                        <button
                                            type="button"
                                            aria-label="Remove new row"
                                            onClick={() =>
                                                setInserts((rows) =>
                                                    rows.filter(
                                                        (_, i) => i !== index,
                                                    ),
                                                )
                                            }
                                        >
                                            <X className="size-3" />
                                        </button>
                                    </td>
                                    {columns.map((column) => (
                                        <Cell
                                            key={column.name}
                                            value={row[column.name]}
                                            placeholder={
                                                column.auto ? 'auto' : 'default'
                                            }
                                            editable
                                            isNew
                                            changed={column.name in row}
                                            editing={
                                                editing?.kind === 'new' &&
                                                editing.index === index &&
                                                editing.column === column.name
                                            }
                                            onEdit={() =>
                                                setEditing({
                                                    kind: 'new',
                                                    index,
                                                    column: column.name,
                                                })
                                            }
                                            onCommit={(value) =>
                                                commitEdit(
                                                    {
                                                        kind: 'new',
                                                        index,
                                                        column: column.name,
                                                    },
                                                    value,
                                                )
                                            }
                                            onCancel={() => setEditing(null)}
                                            testId={`db-new-cell-${index}-${column.name}`}
                                        />
                                    ))}
                                </tr>
                            ))}
                            {data.rows.map((row, index) => {
                                const deleted = deletes.has(index);

                                return (
                                    <tr
                                        key={index}
                                        className={cn(
                                            deleted &&
                                                'bg-red-500/10 line-through opacity-60',
                                            selected.has(index) &&
                                                'bg-muted/60',
                                        )}
                                        data-test="db-row"
                                    >
                                        <td className="border-r border-b border-sidebar-border/70 px-2 text-center dark:border-sidebar-border">
                                            {deleted ? (
                                                <button
                                                    type="button"
                                                    aria-label="Keep row"
                                                    title="Keep row"
                                                    onClick={() =>
                                                        setDeletes(
                                                            (current) => {
                                                                const next =
                                                                    new Set(
                                                                        current,
                                                                    );
                                                                next.delete(
                                                                    index,
                                                                );

                                                                return next;
                                                            },
                                                        )
                                                    }
                                                >
                                                    <X className="size-3" />
                                                </button>
                                            ) : (
                                                canEditRows && (
                                                    <input
                                                        type="checkbox"
                                                        aria-label={`Select row ${index + 1}`}
                                                        checked={selected.has(
                                                            index,
                                                        )}
                                                        onChange={(event) =>
                                                            setSelected(
                                                                (current) => {
                                                                    const next =
                                                                        new Set(
                                                                            current,
                                                                        );

                                                                    if (
                                                                        event
                                                                            .target
                                                                            .checked
                                                                    ) {
                                                                        next.add(
                                                                            index,
                                                                        );
                                                                    } else {
                                                                        next.delete(
                                                                            index,
                                                                        );
                                                                    }

                                                                    return next;
                                                                },
                                                            )
                                                        }
                                                    />
                                                )
                                            )}
                                        </td>
                                        {columns.map((column) => {
                                            const changed =
                                                edits[index] !== undefined &&
                                                column.name in edits[index];
                                            const value = changed
                                                ? edits[index][column.name]
                                                : row[column.name];

                                            return (
                                                <Cell
                                                    key={column.name}
                                                    value={value}
                                                    editable={
                                                        canEditRows &&
                                                        !deleted &&
                                                        !isPreview(
                                                            row[column.name],
                                                        )
                                                    }
                                                    changed={changed}
                                                    editing={
                                                        editing?.kind ===
                                                            'row' &&
                                                        editing.index ===
                                                            index &&
                                                        editing.column ===
                                                            column.name
                                                    }
                                                    onEdit={() =>
                                                        setEditing({
                                                            kind: 'row',
                                                            index,
                                                            column: column.name,
                                                        })
                                                    }
                                                    onCommit={(next) =>
                                                        commitEdit(
                                                            {
                                                                kind: 'row',
                                                                index,
                                                                column: column.name,
                                                            },
                                                            next,
                                                        )
                                                    }
                                                    onCancel={() =>
                                                        setEditing(null)
                                                    }
                                                    testId={`db-cell-${index}-${column.name}`}
                                                />
                                            );
                                        })}
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                )}
                {data && data.rows.length === 0 && inserts.length === 0 && (
                    <p
                        className="p-6 text-center text-sm text-muted-foreground"
                        data-test="db-no-rows"
                    >
                        {filters.length
                            ? 'No rows match these filters.'
                            : 'This table is empty.'}
                    </p>
                )}
            </div>

            {dirty && (
                <div
                    className="flex flex-wrap items-center gap-2 border-t border-sidebar-border/70 bg-amber-500/10 px-3 py-2 text-sm dark:border-sidebar-border"
                    data-test="db-pending"
                >
                    <span className="mr-auto">
                        {changeCount} unsaved{' '}
                        {changeCount === 1 ? 'change' : 'changes'}
                        {saveError && (
                            <span
                                className="ml-2 text-red-600"
                                data-test="db-save-error"
                            >
                                {saveError}
                            </span>
                        )}
                    </span>
                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={clearChanges}
                        disabled={saving}
                    >
                        Discard
                    </Button>
                    <Button
                        size="sm"
                        onClick={save}
                        disabled={saving}
                        data-test="db-save"
                    >
                        {saving ? 'Saving…' : 'Save changes'}
                    </Button>
                </div>
            )}

            <footer className="flex items-center justify-end gap-2 border-t border-sidebar-border/70 px-3 py-1.5 text-xs text-muted-foreground dark:border-sidebar-border">
                <label className="flex items-center gap-1">
                    Rows per page
                    <select
                        value={perPage}
                        onChange={(event) =>
                            navigate(() => {
                                setPerPage(Number(event.target.value));
                                setPage(1);
                            })
                        }
                        className="h-7 rounded-md border border-input bg-transparent px-1"
                    >
                        {PAGE_SIZES.map((size) => (
                            <option key={size} value={size}>
                                {size}
                            </option>
                        ))}
                    </select>
                </label>
                <span className="tabular-nums" data-test="db-page-range">
                    {first.toLocaleString()}–{last.toLocaleString()} of{' '}
                    {(data?.total ?? 0).toLocaleString()}
                </span>
                <Button
                    size="icon"
                    variant="ghost"
                    className="size-7"
                    aria-label="Previous page"
                    disabled={page <= 1}
                    onClick={() => navigate(() => setPage((p) => p - 1))}
                >
                    <ChevronLeft />
                </Button>
                <Button
                    size="icon"
                    variant="ghost"
                    className="size-7"
                    aria-label="Next page"
                    disabled={page >= lastPage}
                    onClick={() => navigate(() => setPage((p) => p + 1))}
                    data-test="db-next-page"
                >
                    <ChevronRight />
                </Button>
            </footer>
        </div>
    );
}

function columnTitle(column: DatabaseColumn): string {
    return [
        `${column.name}: ${column.type}`,
        column.primary && 'primary key',
        column.nullable ? 'nullable' : 'not null',
        column.default !== null && `default ${column.default}`,
    ]
        .filter(Boolean)
        .join(' · ');
}

function Notice({ children }: { children: React.ReactNode }) {
    return (
        <p
            className="border-b border-sidebar-border/70 bg-muted/40 px-3 py-1.5 text-xs text-muted-foreground dark:border-sidebar-border"
            data-test="db-notice"
        >
            {children}
        </p>
    );
}

/**
 * A grid cell. Double-click (or Enter) to edit; Enter saves, Escape cancels.
 */
function Cell({
    value,
    placeholder,
    editable,
    isNew = false,
    changed,
    editing,
    onEdit,
    onCommit,
    onCancel,
    testId,
}: {
    value: DatabaseValue | undefined;
    placeholder?: string;
    editable: boolean;
    isNew?: boolean;
    changed: boolean;
    editing: boolean;
    onEdit: () => void;
    onCommit: (value: DatabaseValue | undefined) => void;
    onCancel: () => void;
    testId: string;
}) {
    const [draft, setDraft] = useState('');
    const input = useRef<HTMLInputElement>(null);
    // Enter/Escape unmount the input, which can also fire blur; only finish once.
    const finished = useRef(false);

    const finish = (value: DatabaseValue | undefined | typeof CANCEL) => {
        if (finished.current) {
            return;
        }

        finished.current = true;

        if (value === CANCEL) {
            onCancel();
        } else {
            onCommit(value);
        }
    };

    useEffect(() => {
        if (editing) {
            finished.current = false;
            setDraft(value === undefined ? '' : editableText(value));
            input.current?.focus();
            input.current?.select();
        }
        // Only when editing starts.
    }, [editing]);

    const border =
        'border-r border-b border-sidebar-border/70 dark:border-sidebar-border';

    if (editing) {
        return (
            <td className={cn(border, 'relative p-0')}>
                <div className="flex min-w-48 items-center gap-1 bg-background p-0.5 ring-2 ring-primary ring-inset">
                    <input
                        ref={input}
                        value={draft}
                        onChange={(event) => setDraft(event.target.value)}
                        onKeyDown={(event) => {
                            if (event.key === 'Enter') {
                                event.preventDefault();
                                finish(draft);
                            } else if (event.key === 'Escape') {
                                event.preventDefault();
                                finish(CANCEL);
                            }
                        }}
                        onBlur={() => finish(draft)}
                        className="min-w-0 flex-1 bg-transparent px-2 py-1 font-mono outline-none"
                        aria-label="Cell value"
                        data-test="db-cell-input"
                    />
                    {/* mousedown so the input's blur doesn't commit the draft first */}
                    <button
                        type="button"
                        onMouseDown={(event) => {
                            event.preventDefault();
                            finish(null);
                        }}
                        className="rounded px-1.5 py-0.5 text-[10px] text-muted-foreground hover:bg-muted"
                        data-test="db-set-null"
                    >
                        NULL
                    </button>
                    {isNew && (
                        <button
                            type="button"
                            onMouseDown={(event) => {
                                event.preventDefault();
                                finish(undefined);
                            }}
                            className="rounded px-1.5 py-0.5 text-[10px] text-muted-foreground hover:bg-muted"
                        >
                            DEFAULT
                        </button>
                    )}
                </div>
            </td>
        );
    }

    return (
        <td
            className={cn(
                border,
                'max-w-80 truncate px-3 py-1.5 font-mono whitespace-nowrap',
                editable && 'cursor-text',
                changed && 'bg-amber-500/20',
            )}
            tabIndex={editable ? 0 : undefined}
            onDoubleClick={editable ? onEdit : undefined}
            onKeyDown={(event) => editable && event.key === 'Enter' && onEdit()}
            title={typeof value === 'string' ? value : undefined}
            data-test={testId}
        >
            <CellValue value={value} placeholder={placeholder} />
        </td>
    );
}

function FilterEditor({
    columns,
    filters,
    onChange,
    onApply,
    onClear,
}: {
    columns: DatabaseColumn[];
    filters: DatabaseFilter[];
    onChange: (filters: DatabaseFilter[]) => void;
    onApply: () => void;
    onClear: () => void;
}) {
    const update = (index: number, patch: Partial<DatabaseFilter>) =>
        onChange(
            filters.map((filter, i) =>
                i === index ? { ...filter, ...patch } : filter,
            ),
        );
    const control =
        'h-7 rounded-md border border-input bg-transparent px-2 text-xs';

    return (
        <form
            className="space-y-2 border-b border-sidebar-border/70 bg-muted/30 px-3 py-2 dark:border-sidebar-border"
            onSubmit={(event) => {
                event.preventDefault();
                onApply();
            }}
            data-test="db-filters"
        >
            {filters.map((filter, index) => (
                <div key={index} className="flex flex-wrap items-center gap-2">
                    <select
                        value={filter.column}
                        onChange={(event) =>
                            update(index, { column: event.target.value })
                        }
                        className={control}
                        aria-label="Filter column"
                    >
                        {columns.map((column) => (
                            <option key={column.name} value={column.name}>
                                {column.name}
                            </option>
                        ))}
                    </select>
                    <select
                        value={filter.operator}
                        onChange={(event) =>
                            update(index, {
                                operator: event.target
                                    .value as DatabaseFilterOperator,
                            })
                        }
                        className={control}
                        aria-label="Filter operator"
                    >
                        {OPERATORS.map((operator) => (
                            <option key={operator.value} value={operator.value}>
                                {operator.label}
                            </option>
                        ))}
                    </select>
                    {filter.operator !== 'null' &&
                        filter.operator !== 'notnull' && (
                            <input
                                value={filter.value}
                                onChange={(event) =>
                                    update(index, { value: event.target.value })
                                }
                                className={cn(control, 'w-48 font-mono')}
                                placeholder="value"
                                aria-label="Filter value"
                                data-test="db-filter-value"
                            />
                        )}
                    <button
                        type="button"
                        aria-label="Remove filter"
                        onClick={() =>
                            onChange(filters.filter((_, i) => i !== index))
                        }
                        className="text-muted-foreground hover:text-foreground"
                    >
                        <X className="size-3.5" />
                    </button>
                </div>
            ))}
            <div className="flex items-center gap-2">
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    onClick={() =>
                        onChange([
                            ...filters,
                            {
                                column: columns[0]?.name ?? '',
                                operator: 'eq',
                                value: '',
                            },
                        ])
                    }
                >
                    <Plus /> Add filter
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    onClick={onClear}
                >
                    Clear
                </Button>
                <Button type="submit" size="sm" data-test="db-apply-filters">
                    Apply
                </Button>
            </div>
        </form>
    );
}
