import {
    Database,
    Download,
    Eye,
    RefreshCw,
    Search,
    SquareTerminal,
    Table2,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import SqlRunner, {
    mongoCollectionReference,
} from '@/components/workspace/database/sql-runner';
import TableView from '@/components/workspace/database/table-view';
import { databaseApi } from '@/lib/database-api';
import type { DatabaseLocation } from '@/lib/database-api';
import ResizeHandle from '@/components/workspace/resize-handle';
import { useResizableWidth } from '@/hooks/use-resizable-width';
import { cn } from '@/lib/utils';
import type { DatabaseConnection, DatabaseTable } from '@/types';

type View = { kind: 'table'; table: string } | { kind: 'sql' };

/** The table list's width in the side-by-side layout, dragged by its edge (DB-001). */
const LIST_WIDTH = { initial: 224, min: 160, max: 560 };

/** What the runner starts with: the first table's (or collection's) first rows. */
function initialQuery(
    driver: DatabaseConnection['driver'],
    tables: DatabaseTable[] | null,
): string {
    const first =
        tables?.find((table) => table.type === 'table') ?? tables?.[0];

    if (!first) {
        return '';
    }

    if (driver !== 'mongodb') {
        return `select * from ${first.name} limit 50`;
    }

    const collection = first.collection ?? first.name;
    // A connection covering several databases names collections "database.collection".
    const use =
        first.database && first.name !== collection
            ? `use ${first.database}\n`
            : '';

    return `${use}${mongoCollectionReference(collection)}.find({}).limit(50)`;
}

/**
 * Browse and edit the app's own database, like Drizzle Studio:
 * tables on the left, an editable grid or a SQL runner on the right.
 * A hosted project's live database is one switch away (HOST-007).
 */
export default function DatabasePanel({
    projectId,
    running,
    hosted = false,
}: {
    projectId: number;
    running: boolean;
    /** Published to Hosting, so its hosted app has a database of its own. */
    hosted?: boolean;
}) {
    const [where, setWhere] = useState<DatabaseLocation>('sandbox');
    const [downloading, setDownloading] = useState(false);
    const [downloadError, setDownloadError] = useState<string | null>(null);
    const [connections, setConnections] = useState<DatabaseConnection[] | null>(
        null,
    );
    const [connectionId, setConnectionId] = useState<string | null>(null);
    const [tables, setTables] = useState<DatabaseTable[] | null>(null);
    const [view, setView] = useState<View | null>(null);
    const [search, setSearch] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [tablesError, setTablesError] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const dirty = useRef(false);
    const [listWidth, setListWidth] = useResizableWidth(
        'database-table-list-width',
        LIST_WIDTH,
    );

    const connection = connections?.find((c) => c.id === connectionId) ?? null;
    // MongoDB (DB-002) has collections and a query runner where SQL databases have tables and a SQL runner.
    const mongo = connection?.driver === 'mongodb';
    const runnerName = mongo ? 'Query runner' : 'SQL runner';

    const loadConnections = useCallback(() => {
        setLoading(true);
        databaseApi
            .connections(projectId, where)
            .then((list) => {
                setConnections(list);
                setError(null);
                setConnectionId((current) =>
                    list.some((c) => c.id === current)
                        ? current
                        : ((list.find((c) => !c.error) ?? list[0])?.id ?? null),
                );
            })
            .catch((e: Error) => setError(e.message))
            .finally(() => setLoading(false));
    }, [projectId, where]);

    const loadTables = useCallback(
        (id: string, keepView = false) =>
            databaseApi
                .tables(projectId, id, where)
                .then((list) => {
                    setTables(list);
                    setTablesError(null);

                    if (!keepView) {
                        setView(
                            list[0]
                                ? { kind: 'table', table: list[0].name }
                                : { kind: 'sql' },
                        );
                    }
                })
                .catch((e: Error) => {
                    setTables([]);
                    setTablesError(e.message);
                }),
        [projectId, where],
    );

    // The hosted app's machine answers whether or not the sandbox is running.
    const reachable = where === 'hosted' || running;

    useEffect(() => {
        if (reachable) {
            setConnections(null);
            setError(null);
            loadConnections();
        }
    }, [reachable, loadConnections]);

    useEffect(() => {
        setTables(null);
        setView(null);

        if (connectionId && !connection?.error) {
            void loadTables(connectionId);
        }
        // Reload tables only when the chosen connection changes.
    }, [connectionId, loadTables]);

    const onDirtyChange = useCallback((value: boolean) => {
        dirty.current = value;
    }, []);

    /** Switch what's shown, asking first if the grid has unsaved edits. */
    const show = (next: View) => {
        if (
            dirty.current &&
            !window.confirm('Discard your unsaved changes to this table?')
        ) {
            return;
        }

        dirty.current = false;
        setView(next);
    };

    /** Switch between the sandbox's database and the hosted app's, asking first if the grid has unsaved edits. */
    const switchTo = (next: DatabaseLocation) => {
        if (
            next === where ||
            (dirty.current &&
                !window.confirm('Discard your unsaved changes to this table?'))
        ) {
            return;
        }

        dirty.current = false;
        setConnectionId(null);
        setWhere(next);
    };

    const download = () => {
        if (!connection) {
            return;
        }

        setDownloading(true);
        setDownloadError(null);
        databaseApi
            .download(projectId, connection.id, where)
            .then(({ url }) => {
                window.location.href = url;
            })
            .catch((e: Error) => setDownloadError(e.message))
            .finally(() => setDownloading(false));
    };

    const locations = hosted ? (
        <div
            className="mb-2 inline-flex rounded-lg border p-0.5 text-xs"
            role="radiogroup"
            aria-label="Whose database"
            data-test="db-location"
        >
            {(['sandbox', 'hosted'] as const).map((option) => (
                <button
                    key={option}
                    type="button"
                    role="radio"
                    aria-checked={where === option}
                    onClick={() => switchTo(option)}
                    className={cn(
                        'rounded-md px-2.5 py-1',
                        where === option
                            ? option === 'hosted'
                                ? 'bg-amber-500/15 font-medium text-amber-700 dark:text-amber-400'
                                : 'bg-muted font-medium'
                            : 'text-muted-foreground hover:bg-muted/50',
                    )}
                    data-test={`db-location-${option}`}
                >
                    {option === 'sandbox' ? 'Sandbox' : 'Hosted (live)'}
                </button>
            ))}
        </div>
    ) : null;

    if (!reachable) {
        return (
            <>
                {locations}
                <Empty>
                    The database browser works when the sandbox is running.
                </Empty>
            </>
        );
    }

    if (error) {
        return (
            <>
                {locations}
                <Empty tone="error">{error}</Empty>
            </>
        );
    }

    if (!connections) {
        return (
            <>
                {locations}
                <Empty>
                    {where === 'hosted'
                        ? 'Waking the hosted app and looking for its database…'
                        : "Looking for your app's database…"}
                </Empty>
            </>
        );
    }

    if (connections.length === 0) {
        return (
            <>
                {locations}
                <Empty>
                    <span className="block">No database found yet.</span>
                    <span className="mt-1 block">
                        When your app has one (a SQLite file,{' '}
                        <code>DATABASE_URL</code>, <code>DB_CONNECTION</code> or{' '}
                        <code>MONGODB_URI</code> in <code>.env</code>, or a
                        database container), it shows up here.
                    </span>
                    <Button
                        size="sm"
                        variant="outline"
                        className="mt-4"
                        onClick={loadConnections}
                        disabled={loading}
                    >
                        <RefreshCw className={cn(loading && 'animate-spin')} />{' '}
                        Check again
                    </Button>
                </Empty>
            </>
        );
    }

    const visibleTables = (tables ?? []).filter((table) =>
        table.name.toLowerCase().includes(search.toLowerCase()),
    );

    return (
        <>
            {locations}
            {where === 'hosted' && (
                <p
                    className="mb-2 text-xs text-amber-700 dark:text-amber-400"
                    data-test="db-hosted-note"
                >
                    This is the hosted app’s live data. Changes reach your
                    visitors right away.
                </p>
            )}
            {/* Laid out by the panel's own width: in a narrow pane the table list becomes a dropdown. */}
            <div
                className="@container h-[calc(100vh-16rem)] min-h-[28rem] overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
                data-test="database-panel"
            >
                <div className="flex h-full flex-col @2xl:flex-row">
                    <aside
                        className="flex shrink-0 flex-col border-b border-sidebar-border/70 @2xl:w-(--db-list-width) @2xl:border-b-0 dark:border-sidebar-border"
                        style={
                            {
                                '--db-list-width': `${listWidth}px`,
                            } as React.CSSProperties
                        }
                    >
                        <div className="space-y-2 border-b border-sidebar-border/70 p-2 dark:border-sidebar-border">
                            <div className="flex items-center gap-1">
                                <select
                                    value={connectionId ?? ''}
                                    onChange={(event) => {
                                        if (
                                            dirty.current &&
                                            !window.confirm(
                                                'Discard your unsaved changes to this table?',
                                            )
                                        ) {
                                            return;
                                        }

                                        dirty.current = false;
                                        setConnectionId(event.target.value);
                                    }}
                                    className="h-8 min-w-0 flex-1 rounded-md border border-input bg-transparent px-2 text-xs"
                                    aria-label="Database"
                                    data-test="db-connection"
                                >
                                    {connections.map((c) => (
                                        <option key={c.id} value={c.id}>
                                            {c.label} · {c.summary}
                                        </option>
                                    ))}
                                </select>
                                <Button
                                    size="icon"
                                    variant="ghost"
                                    className="size-8"
                                    aria-label="Refresh databases and tables"
                                    onClick={() => {
                                        loadConnections();

                                        if (
                                            connectionId &&
                                            !connection?.error
                                        ) {
                                            void loadTables(connectionId, true);
                                        }
                                    }}
                                >
                                    <RefreshCw
                                        className={cn(
                                            loading && 'animate-spin',
                                        )}
                                    />
                                </Button>
                                {connection?.driver === 'sqlite' &&
                                    !connection.error && (
                                        <Button
                                            size="icon"
                                            variant="ghost"
                                            className="size-8"
                                            aria-label="Download a copy of this database"
                                            title="Download a copy"
                                            onClick={download}
                                            disabled={downloading}
                                            data-test="db-download"
                                        >
                                            <Download
                                                className={cn(
                                                    downloading &&
                                                        'animate-pulse',
                                                )}
                                            />
                                        </Button>
                                    )}
                            </div>
                            {downloadError && (
                                <p
                                    className="px-1 text-[11px] text-red-600"
                                    data-test="db-download-error"
                                >
                                    {downloadError}
                                </p>
                            )}
                            {connection?.source && (
                                <p
                                    className="truncate px-1 text-[11px] text-muted-foreground"
                                    title={connection.source}
                                >
                                    From {connection.source}
                                </p>
                            )}
                            <select
                                value={
                                    view?.kind === 'table'
                                        ? `table:${view.table}`
                                        : (view?.kind ?? '')
                                }
                                onChange={(event) => {
                                    const value = event.target.value;

                                    show(
                                        value === 'sql'
                                            ? { kind: 'sql' }
                                            : {
                                                  kind: 'table',
                                                  table: value.slice(6),
                                              },
                                    );
                                }}
                                className="h-8 w-full rounded-md border border-input bg-transparent px-2 font-mono text-xs @2xl:hidden"
                                aria-label={mongo ? 'Collection' : 'Table'}
                                data-test="db-table-select"
                            >
                                {(tables ?? []).map((table) => (
                                    <option
                                        key={table.name}
                                        value={`table:${table.name}`}
                                    >
                                        {table.name}
                                        {table.type === 'view' ? ' (view)' : ''}
                                    </option>
                                ))}
                                <option value="sql">{runnerName}</option>
                            </select>
                            <label className="hidden items-center gap-1.5 rounded-md border border-input px-2 @2xl:flex">
                                <Search className="size-3.5 text-muted-foreground" />
                                <input
                                    value={search}
                                    onChange={(event) =>
                                        setSearch(event.target.value)
                                    }
                                    placeholder={
                                        mongo
                                            ? 'Search collections'
                                            : 'Search tables'
                                    }
                                    className="h-7 min-w-0 flex-1 bg-transparent text-xs outline-none"
                                    aria-label={
                                        mongo
                                            ? 'Search collections'
                                            : 'Search tables'
                                    }
                                />
                            </label>
                        </div>

                        <ul
                            className="hidden min-h-0 flex-1 overflow-y-auto p-1 @2xl:block"
                            aria-label={mongo ? 'Collections' : 'Tables'}
                            data-test="db-tables"
                        >
                            {tables === null && !connection?.error && (
                                <li className="px-2 py-1 text-xs text-muted-foreground">
                                    Loading…
                                </li>
                            )}
                            {tables?.length === 0 && !tablesError && (
                                <li className="px-2 py-1 text-xs text-muted-foreground">
                                    {mongo
                                        ? 'No collections yet.'
                                        : 'No tables yet.'}
                                </li>
                            )}
                            {visibleTables.map((table) => {
                                const Icon =
                                    table.type === 'view' ? Eye : Table2;
                                const active =
                                    view?.kind === 'table' &&
                                    view.table === table.name;

                                return (
                                    <li key={table.name}>
                                        <button
                                            type="button"
                                            onClick={() =>
                                                show({
                                                    kind: 'table',
                                                    table: table.name,
                                                })
                                            }
                                            aria-current={
                                                active ? 'page' : undefined
                                            }
                                            className={cn(
                                                'flex w-full items-center gap-2 rounded-md px-2 py-1 text-left font-mono text-xs hover:bg-muted',
                                                active &&
                                                    'bg-muted font-medium',
                                            )}
                                            data-test={`db-table-${table.name}`}
                                        >
                                            <Icon className="size-3.5 shrink-0 text-muted-foreground" />
                                            <span className="truncate">
                                                {table.name}
                                            </span>
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>

                        <div className="hidden border-t border-sidebar-border/70 p-1 @2xl:block dark:border-sidebar-border">
                            <button
                                type="button"
                                onClick={() => show({ kind: 'sql' })}
                                aria-current={
                                    view?.kind === 'sql' ? 'page' : undefined
                                }
                                disabled={!connection || !!connection.error}
                                className={cn(
                                    'flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-xs hover:bg-muted disabled:opacity-50',
                                    view?.kind === 'sql' &&
                                        'bg-muted font-medium',
                                )}
                                data-test="db-open-sql"
                            >
                                <SquareTerminal className="size-3.5 text-muted-foreground" />
                                {runnerName}
                            </button>
                        </div>
                    </aside>
                    <ResizeHandle
                        label={
                            mongo
                                ? 'Resize collection list'
                                : 'Resize table list'
                        }
                        side="left"
                        width={listWidth}
                        limits={LIST_WIDTH}
                        onResize={setListWidth}
                        className="hidden @2xl:block"
                        data-test="db-list-resize"
                    />

                    {connection?.error || tablesError ? (
                        <Empty tone="error" className="m-4 flex-1 self-start">
                            {connection?.error ?? tablesError}
                        </Empty>
                    ) : view?.kind === 'table' && connectionId ? (
                        <TableView
                            key={`${where}:${connectionId}:${view.table}`}
                            projectId={projectId}
                            connection={connectionId}
                            table={view.table}
                            onDirtyChange={onDirtyChange}
                            where={where}
                            mongo={mongo}
                        />
                    ) : view?.kind === 'sql' && connection ? (
                        <SqlRunner
                            key={`${where}:${connection.id}`}
                            where={where}
                            projectId={projectId}
                            connection={connection.id}
                            driver={connection.driver}
                            tables={tables}
                            initialSql={initialQuery(connection.driver, tables)}
                            onRan={() => void loadTables(connection.id, true)}
                        />
                    ) : (
                        <div className="flex flex-1 items-center justify-center text-muted-foreground">
                            <Database className="size-8 opacity-30" />
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}

function Empty({
    children,
    tone,
    className,
}: {
    children: React.ReactNode;
    tone?: 'error';
    className?: string;
}) {
    return (
        <div
            className={cn(
                'rounded-lg border border-dashed p-6 text-center text-sm',
                tone === 'error' ? 'text-red-600' : 'text-muted-foreground',
                className,
            )}
            data-test="database-empty"
        >
            {children}
        </div>
    );
}
