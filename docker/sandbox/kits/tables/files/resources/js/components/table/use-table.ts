import { echo, echoIsConfigured } from '@laravel/echo-react';
import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import { request, setSocketId, TableRequestError } from './api';
import { applyFilters, applySearch, applySorts, groupRecords } from './filters';
import type { GroupNode } from './filters';
import { asText, isEditable } from './format';
import type { ValueContext } from './format';
import type {
    CellValue,
    Field,
    FieldInput,
    FormulaPreview,
    RecordChanges,
    RecordChangesResult,
    TableChangedEvent,
    TableData,
    TableRecord,
    TableSummary,
    TableUser,
    TableView,
    ViewConfig,
    ViewType,
} from './types';

/** One undoable step: the changes that reverse it, and the ones that redo it. */
interface HistoryEntry {
    undo: RecordChanges;
    redo: RecordChanges;
}

export interface TableStore {
    key: string;
    name: string;
    endpoint: string;
    fields: Field[];
    fieldsByKey: Map<string, Field>;
    records: TableRecord[];
    recordsById: Map<number, TableRecord>;
    views: TableView[];
    view: TableView;
    setViewId: (id: number) => void;
    /** The view's fields in order, the primary field first, hidden ones included */
    orderedFields: Field[];
    /** The view's shown fields, in order */
    visibleFields: Field[];
    /** The view's records: filtered, searched and sorted */
    rows: TableRecord[];
    /** The view's groups, when it's grouped */
    groups: GroupNode[] | null;
    users: TableUser[];
    /** Tables a link can point to */
    linkableTables: { key: string; name: string }[];
    /** Each table's fields, by table key, for lookups and rollups */
    linkedFields: Record<string, TableSummary['fields']>;
    context: ValueContext;
    can: TableData['can'];
    me: number | null;
    truncated: boolean;
    search: string;
    setSearch: (search: string) => void;
    /** The last error to show, until dismissed */
    error: string | null;
    setError: (error: string | null) => void;
    /** The record open in the record panel */
    expandedId: number | null;
    expand: (id: number | null) => void;
    /** Changes the view's settings now, and saves them shortly after (when the user may) */
    updateView: (patch: Partial<ViewConfig>, viewId?: number) => void;
    /** Takes a view's form links (formUrl, publicFormUrl) from the server's answer */
    applyViewLinks: (view: TableView) => void;
    createView: (
        name: string,
        type: ViewType,
        options?: { personal?: boolean; duplicate?: number },
    ) => Promise<TableView | null>;
    renameView: (id: number, name: string) => Promise<void>;
    deleteView: (id: number) => Promise<void>;
    reorderViews: (ids: number[]) => Promise<void>;
    /**
     * Sends a batch of record changes. Updates show at once and roll back if the server refuses them.
     * Undoable unless `undoable: false`.
     */
    change: (
        changes: RecordChanges,
        options?: { undoable?: boolean },
    ) => Promise<RecordChangesResult | null>;
    setCell: (recordId: number, fieldKey: string, value: CellValue) => void;
    createRecord: (
        values?: Record<string, CellValue>,
    ) => Promise<TableRecord | null>;
    deleteRecords: (ids: number[]) => Promise<void>;
    undo: () => void;
    redo: () => void;
    canUndo: boolean;
    canRedo: boolean;
    /** Adds a field, or changes the one with that key. Resolves to the field, or null when refused (see error). */
    saveField: (input: FieldInput, key?: string) => Promise<Field | null>;
    deleteField: (key: string) => Promise<void>;
    duplicateField: (key: string) => Promise<void>;
    previewFormula: (
        formula: string,
        format?: string,
    ) => Promise<FormulaPreview>;
    /** Fetches the whole table again */
    reload: () => Promise<void>;
    /** Where attachment uploads go when not `${endpoint}/attachments` (the form page sets it) */
    uploadUrl?: string;
}

export const TableContext = createContext<TableStore | null>(null);

export function useTableStore(): TableStore {
    const store = useContext(TableContext);

    if (!store) {
        throw new Error('useTableStore must be used inside <DataTable>.');
    }

    return store;
}

function viewStorageKey(key: string): string {
    return `table:${key}:view`;
}

function messageOf(error: unknown): string {
    if (error instanceof TableRequestError) {
        return error.firstErrors().join(' ');
    }

    return 'Something went wrong. Try again.';
}

/** Values a record could be created again with: everything people can edit. */
function editableValues(
    record: TableRecord,
    fields: Field[],
): Record<string, CellValue> {
    const values: Record<string, CellValue> = {};

    for (const field of fields) {
        if (isEditable(field) && field.key in record.values) {
            values[field.key] = record.values[field.key];
        }
    }

    return values;
}

function mergeRecords(
    records: TableRecord[],
    changed: TableRecord[],
    deleted: number[] = [],
): TableRecord[] {
    const byId = new Map(changed.map((record) => [record.id, record]));
    const gone = new Set(deleted);
    const merged = records
        .filter((record) => !gone.has(record.id))
        .map((record) => byId.get(record.id) ?? record);
    const existing = new Set(records.map((record) => record.id));

    for (const record of changed) {
        if (!existing.has(record.id) && !gone.has(record.id)) {
            merged.push(record);
        }
    }

    return merged;
}

export function useTable(initial: TableData): TableStore {
    const [data, setData] = useState(initial);
    const [records, setRecords] = useState(initial.records);
    const [views, setViews] = useState(initial.views);
    const [viewId, setViewIdState] = useState<number>(() => {
        const saved = Number(
            typeof window === 'undefined'
                ? NaN
                : window.localStorage.getItem(viewStorageKey(initial.key)),
        );

        return initial.views.some((view) => view.id === saved)
            ? saved
            : (initial.views[0]?.id ?? 0);
    });
    const [search, setSearch] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [expandedId, setExpandedId] = useState<number | null>(null);
    const [history, setHistory] = useState<{
        past: HistoryEntry[];
        future: HistoryEntry[];
    }>({ past: [], future: [] });
    const recordsRef = useRef(records);
    const viewSaves = useRef(new Map<number, ReturnType<typeof setTimeout>>());

    useEffect(() => {
        recordsRef.current = records;
    }, [records]);

    // A new page visit with fresh data (Inertia props) replaces what's shown.
    useEffect(() => {
        setData(initial);
        setRecords(initial.records);
        setViews(initial.views);
    }, [initial]);

    const fieldsByKey = useMemo(
        () => new Map(data.fields.map((field) => [field.key, field])),
        [data.fields],
    );
    const recordsById = useMemo(
        () => new Map(records.map((record) => [record.id, record])),
        [records],
    );
    const usersById = useMemo(
        () => new Map(data.users.map((user) => [user.id, user])),
        [data.users],
    );
    const context = useMemo<ValueContext>(() => {
        const titles = new Map<string, Map<number, string>>();

        for (const [key, table] of Object.entries(data.linked)) {
            titles.set(
                key,
                new Map(
                    table.records.map((record) => [record.id, record.title]),
                ),
            );
        }

        // Links within the same table show the current titles.
        const primary = data.fields.find((field) => field.primary);

        if (primary && titles.has(data.key)) {
            titles.set(
                data.key,
                new Map(
                    records.map((record) => [
                        record.id,
                        asText(record.values[primary.key]) || 'Untitled',
                    ]),
                ),
            );
        }

        return { usersById, linked: data.linked, titles };
    }, [data.linked, data.fields, data.key, records, usersById]);

    const view = useMemo(
        () => views.find((candidate) => candidate.id === viewId) ?? views[0],
        [views, viewId],
    );

    const orderedFields = useMemo(() => {
        const order = view?.config.order ?? [];
        const position = new Map(order.map((key, index) => [key, index]));
        const fields = [...data.fields].sort((a, b) => {
            if (a.primary !== b.primary) {
                return a.primary ? -1 : 1;
            }

            const left =
                position.get(a.key) ?? order.length + data.fields.indexOf(a);
            const right =
                position.get(b.key) ?? order.length + data.fields.indexOf(b);

            return left - right;
        });

        return fields;
    }, [data.fields, view]);

    const visibleFields = useMemo(() => {
        const hidden = new Set(view?.config.hidden ?? []);

        return orderedFields.filter(
            (field) => field.primary || !hidden.has(field.key),
        );
    }, [orderedFields, view]);

    const rows = useMemo(() => {
        if (!view) {
            return records;
        }

        const filtered = applyFilters(
            records,
            view.config.filters,
            fieldsByKey,
            context,
            data.me,
        );
        const searched = applySearch(filtered, search, visibleFields, context);

        return applySorts(
            searched,
            [...view.config.groups, ...view.config.sorts],
            fieldsByKey,
            context,
        );
    }, [records, view, fieldsByKey, context, data.me, search, visibleFields]);

    const groups = useMemo(() => {
        if (!view || view.type !== 'grid' || view.config.groups.length === 0) {
            return null;
        }

        return groupRecords(rows, view.config.groups, fieldsByKey, context);
    }, [rows, view, fieldsByKey, context]);

    const setViewId = useCallback(
        (id: number) => {
            setViewIdState(id);
            window.localStorage.setItem(viewStorageKey(data.key), String(id));
        },
        [data.key],
    );

    const reload = useCallback(async () => {
        try {
            const fresh = await request<TableData>('GET', data.endpoint);

            setData(fresh);
            setRecords(fresh.records);
            setViews(fresh.views);
        } catch (failure) {
            setError(messageOf(failure));
        }
    }, [data.endpoint]);

    const fetchRecords = useCallback(
        async (ids: number[]) => {
            try {
                const result = await request<{ records: TableRecord[] }>(
                    'GET',
                    `${data.endpoint}/records?ids=${ids.join(',')}`,
                );

                setRecords((current) => mergeRecords(current, result.records));
            } catch {
                // The next change or reload brings them.
            }
        },
        [data.endpoint],
    );

    // Live updates from other people, over Echo when the app has it set up.
    useEffect(() => {
        if (!echoIsConfigured()) {
            return;
        }

        let channel: ReturnType<ReturnType<typeof echo>['private']> | null =
            null;

        try {
            const connection = echo();

            setSocketId(() => connection.socketId() ?? null);
            channel = connection.private(`tables.${data.key}`);
            channel.listen('.table.changed', (event: TableChangedEvent) => {
                if (event.reload) {
                    void reload();

                    return;
                }

                if (event.deleted?.length) {
                    const gone = new Set(event.deleted);

                    setRecords((current) =>
                        current.filter((record) => !gone.has(record.id)),
                    );
                }

                if (event.records?.length) {
                    void fetchRecords(event.records);
                }
            });
        } catch {
            return;
        }

        return () => {
            try {
                echo().leave(`tables.${data.key}`);
            } catch {
                // Already gone.
            }
        };
    }, [data.key, reload, fetchRecords]);

    const send = useCallback(
        async (changes: RecordChanges): Promise<RecordChangesResult> => {
            return request<RecordChangesResult>(
                'POST',
                `${data.endpoint}/records`,
                changes,
            );
        },
        [data.endpoint],
    );

    const change = useCallback(
        async (
            changes: RecordChanges,
            options: { undoable?: boolean } = {},
        ): Promise<RecordChangesResult | null> => {
            const before = recordsRef.current;
            const byId = new Map(before.map((record) => [record.id, record]));

            // Show updates and deletions at once.
            setRecords((current) => {
                const gone = new Set(changes.deletes ?? []);

                return current
                    .filter((record) => !gone.has(record.id))
                    .map((record) => {
                        const update = changes.updates?.find(
                            (candidate) => candidate.id === record.id,
                        );

                        return update
                            ? {
                                  ...record,
                                  values: {
                                      ...record.values,
                                      ...update.values,
                                  },
                              }
                            : record;
                    });
            });

            try {
                const result = await send(changes);

                setRecords((current) =>
                    mergeRecords(current, result.records, result.deleted),
                );

                if (options.undoable !== false) {
                    const created = result.records.filter(
                        (record) => !byId.has(record.id),
                    );
                    const undo: RecordChanges = {
                        updates: (changes.updates ?? [])
                            .filter((update) => byId.has(update.id))
                            .map((update) => ({
                                id: update.id,
                                values: Object.fromEntries(
                                    Object.keys(update.values).map((key) => [
                                        key,
                                        byId.get(update.id)!.values[key] ??
                                            null,
                                    ]),
                                ),
                            })),
                        deletes: created.map((record) => record.id),
                        creates: (changes.deletes ?? [])
                            .filter((id) => byId.has(id))
                            .map((id) => ({
                                values: editableValues(
                                    byId.get(id)!,
                                    data.fields,
                                ),
                            })),
                    };

                    setHistory((current) => ({
                        past: [
                            ...current.past.slice(-99),
                            { undo, redo: changes },
                        ],
                        future: [],
                    }));
                }

                return result;
            } catch (failure) {
                setRecords(before);
                setError(messageOf(failure));

                return null;
            }
        },
        [send, data.fields],
    );

    const replay = useCallback(
        async (direction: 'undo' | 'redo') => {
            const stack = direction === 'undo' ? history.past : history.future;
            const entry = stack[stack.length - 1];

            if (!entry) {
                return;
            }

            const changes = direction === 'undo' ? entry.undo : entry.redo;
            const result = await change(changes, { undoable: false });

            if (!result) {
                return;
            }

            // Recreated records get new ids, so the opposite step deletes those.
            const created = result.records
                .filter(
                    (record) =>
                        !recordsRef.current.some(
                            (existing) => existing.id === record.id,
                        ),
                )
                .map((record) => record.id);
            const opposite: HistoryEntry =
                direction === 'undo'
                    ? {
                          undo: entry.undo,
                          redo: {
                              ...entry.redo,
                              deletes:
                                  created.length > 0
                                      ? created
                                      : entry.redo.deletes,
                          },
                      }
                    : {
                          undo: {
                              ...entry.undo,
                              deletes:
                                  created.length > 0
                                      ? created
                                      : entry.undo.deletes,
                          },
                          redo: entry.redo,
                      };

            setHistory((current) =>
                direction === 'undo'
                    ? {
                          past: current.past.slice(0, -1),
                          future: [...current.future, opposite],
                      }
                    : {
                          past: [...current.past, opposite],
                          future: current.future.slice(0, -1),
                      },
            );
        },
        [history, change],
    );

    const setCell = useCallback(
        (recordId: number, fieldKey: string, value: CellValue) => {
            void change({
                updates: [{ id: recordId, values: { [fieldKey]: value } }],
            });
        },
        [change],
    );

    const createRecord = useCallback(
        async (values: Record<string, CellValue> = {}) => {
            const result = await change({ creates: [{ values }] });

            return result?.records[0] ?? null;
        },
        [change],
    );

    const deleteRecords = useCallback(
        async (ids: number[]) => {
            if (ids.length === 0) {
                return;
            }

            await change({ deletes: ids });
            setExpandedId((current) =>
                current !== null && ids.includes(current) ? null : current,
            );
        },
        [change],
    );

    const applyViewLinks = useCallback((fresh: TableView) => {
        setViews((current) =>
            current.map((candidate) =>
                candidate.id === fresh.id
                    ? {
                          ...candidate,
                          formUrl: fresh.formUrl,
                          publicFormUrl: fresh.publicFormUrl,
                      }
                    : candidate,
            ),
        );
    }, []);

    const persistView = useCallback(
        (id: number, config: ViewConfig) => {
            const timers = viewSaves.current;

            clearTimeout(timers.get(id));
            timers.set(
                id,
                setTimeout(() => {
                    timers.delete(id);
                    request<{ view?: TableView } | undefined>(
                        'PATCH',
                        `${data.endpoint}/views/${id}`,
                        { config },
                    )
                        .then((result) => {
                            // A form's public link comes and goes with its "public" setting.
                            if (result?.view) {
                                applyViewLinks(result.view);
                            }
                        })
                        .catch((failure: unknown) =>
                            setError(messageOf(failure)),
                        );
                }, 500),
            );
        },
        [data.endpoint, applyViewLinks],
    );

    const updateView = useCallback(
        (patch: Partial<ViewConfig>, id: number = viewId) => {
            setViews((current) =>
                current.map((candidate) => {
                    if (candidate.id !== id) {
                        return candidate;
                    }

                    const updated = {
                        ...candidate,
                        config: { ...candidate.config, ...patch },
                    };

                    if (candidate.personal || data.can.manageViews) {
                        persistView(id, updated.config);
                    }

                    return updated;
                }),
            );
        },
        [viewId, data.can.manageViews, persistView],
    );

    const createView = useCallback(
        async (
            name: string,
            type: ViewType,
            options: { personal?: boolean; duplicate?: number } = {},
        ) => {
            try {
                const result = await request<{ view: TableView }>(
                    'POST',
                    `${data.endpoint}/views`,
                    { name, type, ...options },
                );

                setViews((current) => [...current, result.view]);
                setViewId(result.view.id);

                return result.view;
            } catch (failure) {
                setError(messageOf(failure));

                return null;
            }
        },
        [data.endpoint, setViewId],
    );

    const renameView = useCallback(
        async (id: number, name: string) => {
            try {
                const result = await request<{ view: TableView }>(
                    'PATCH',
                    `${data.endpoint}/views/${id}`,
                    { name },
                );

                setViews((current) =>
                    current.map((candidate) =>
                        candidate.id === id ? result.view : candidate,
                    ),
                );
            } catch (failure) {
                setError(messageOf(failure));
            }
        },
        [data.endpoint],
    );

    const deleteView = useCallback(
        async (id: number) => {
            try {
                await request('DELETE', `${data.endpoint}/views/${id}`);

                setViews((current) => {
                    const remaining = current.filter(
                        (candidate) => candidate.id !== id,
                    );

                    if (id === viewId && remaining[0]) {
                        setViewId(remaining[0].id);
                    }

                    return remaining;
                });
            } catch (failure) {
                setError(messageOf(failure));
            }
        },
        [data.endpoint, viewId, setViewId],
    );

    const reorderViews = useCallback(
        async (ids: number[]) => {
            setViews((current) =>
                [...current].sort(
                    (a, b) => ids.indexOf(a.id) - ids.indexOf(b.id),
                ),
            );

            try {
                await request('POST', `${data.endpoint}/views/order`, { ids });
            } catch (failure) {
                setError(messageOf(failure));
            }
        },
        [data.endpoint],
    );

    const applyTable = useCallback((fresh: TableData) => {
        setData(fresh);
        setRecords(fresh.records);
        setViews(fresh.views);
    }, []);

    const saveField = useCallback(
        async (input: FieldInput, key?: string) => {
            try {
                const result = await request<{ table: TableData }>(
                    key ? 'PATCH' : 'POST',
                    key
                        ? `${data.endpoint}/fields/${key}`
                        : `${data.endpoint}/fields`,
                    input,
                );
                const known = new Set(data.fields.map((field) => field.key));

                applyTable(result.table);

                return (
                    result.table.fields.find((field) =>
                        key ? field.key === key : !known.has(field.key),
                    ) ?? null
                );
            } catch (failure) {
                setError(messageOf(failure));

                return null;
            }
        },
        [data.endpoint, data.fields, applyTable],
    );

    const deleteField = useCallback(
        async (key: string) => {
            try {
                const result = await request<{ table: TableData }>(
                    'DELETE',
                    `${data.endpoint}/fields/${key}`,
                );

                applyTable(result.table);
            } catch (failure) {
                setError(messageOf(failure));
            }
        },
        [data.endpoint, applyTable],
    );

    const duplicateField = useCallback(
        async (key: string) => {
            try {
                const result = await request<{ table: TableData }>(
                    'POST',
                    `${data.endpoint}/fields/${key}/duplicate`,
                );

                applyTable(result.table);
            } catch (failure) {
                setError(messageOf(failure));
            }
        },
        [data.endpoint, applyTable],
    );

    const previewFormula = useCallback(
        async (formula: string, format?: string) => {
            try {
                return await request<FormulaPreview>(
                    'POST',
                    `${data.endpoint}/formula`,
                    { formula, format },
                );
            } catch (failure) {
                return { ok: false, error: messageOf(failure) };
            }
        },
        [data.endpoint],
    );

    return {
        key: data.key,
        name: data.name,
        endpoint: data.endpoint,
        fields: data.fields,
        fieldsByKey,
        records,
        recordsById,
        views,
        view,
        setViewId,
        orderedFields,
        visibleFields,
        rows,
        groups,
        users: data.users,
        linkableTables: data.tables.map(({ key, name }) => ({ key, name })),
        linkedFields: Object.fromEntries(
            data.tables.map((table) => [table.key, table.fields]),
        ),
        context,
        can: data.can,
        me: data.me,
        truncated: data.truncated,
        search,
        setSearch,
        error,
        setError,
        expandedId,
        expand: setExpandedId,
        updateView,
        applyViewLinks,
        createView,
        renameView,
        deleteView,
        reorderViews,
        change,
        setCell,
        createRecord,
        deleteRecords,
        undo: () => void replay('undo'),
        redo: () => void replay('redo'),
        canUndo: history.past.length > 0,
        canRedo: history.future.length > 0,
        saveField,
        deleteField,
        duplicateField,
        previewFormula,
        reload,
    };
}
