import {
    Database,
    Eye,
    RefreshCw,
    Search,
    SquareTerminal,
    Table2,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import SqlRunner from '@/components/workspace/database/sql-runner';
import TableView from '@/components/workspace/database/table-view';
import { databaseApi } from '@/lib/database-api';
import { cn } from '@/lib/utils';
import type { DatabaseConnection, DatabaseTable } from '@/types';

type View = { kind: 'table'; table: string } | { kind: 'sql' };

/**
 * Browse and edit the app's own database, like Drizzle Studio:
 * tables on the left, an editable grid or a SQL runner on the right.
 */
export default function DatabasePanel({
    projectId,
    running,
}: {
    projectId: number;
    running: boolean;
}) {
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

    const connection = connections?.find((c) => c.id === connectionId) ?? null;

    const loadConnections = useCallback(() => {
        setLoading(true);
        databaseApi
            .connections(projectId)
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
    }, [projectId]);

    const loadTables = useCallback(
        (id: string, keepView = false) =>
            databaseApi
                .tables(projectId, id)
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
        [projectId],
    );

    useEffect(() => {
        if (running) {
            loadConnections();
        }
    }, [running, loadConnections]);

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

    if (!running) {
        return (
            <Empty>
                The database browser works when the sandbox is running.
            </Empty>
        );
    }

    if (error) {
        return <Empty tone="error">{error}</Empty>;
    }

    if (!connections) {
        return <Empty>Looking for your app's database…</Empty>;
    }

    if (connections.length === 0) {
        return (
            <Empty>
                <span className="block">No database found yet.</span>
                <span className="mt-1 block">
                    When your app has one (a SQLite file, or{' '}
                    <code>DATABASE_URL</code> or <code>DB_CONNECTION</code> in{' '}
                    <code>.env</code>), it shows up here.
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
        );
    }

    const visibleTables = (tables ?? []).filter((table) =>
        table.name.toLowerCase().includes(search.toLowerCase()),
    );

    return (
        // Laid out by the panel's own width: in a narrow pane the table list becomes a dropdown.
        <div
            className="@container h-[calc(100vh-16rem)] min-h-[28rem] overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
            data-test="database-panel"
        >
            <div className="flex h-full flex-col @2xl:flex-row">
                <aside className="flex shrink-0 flex-col border-b border-sidebar-border/70 @2xl:w-56 @2xl:border-r @2xl:border-b-0 dark:border-sidebar-border">
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

                                    if (connectionId && !connection?.error) {
                                        void loadTables(connectionId, true);
                                    }
                                }}
                            >
                                <RefreshCw
                                    className={cn(loading && 'animate-spin')}
                                />
                            </Button>
                        </div>
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
                            aria-label="Table"
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
                            <option value="sql">SQL runner</option>
                        </select>
                        <label className="hidden items-center gap-1.5 rounded-md border border-input px-2 @2xl:flex">
                            <Search className="size-3.5 text-muted-foreground" />
                            <input
                                value={search}
                                onChange={(event) =>
                                    setSearch(event.target.value)
                                }
                                placeholder="Search tables"
                                className="h-7 min-w-0 flex-1 bg-transparent text-xs outline-none"
                                aria-label="Search tables"
                            />
                        </label>
                    </div>

                    <ul
                        className="hidden min-h-0 flex-1 overflow-y-auto p-1 @2xl:block"
                        aria-label="Tables"
                        data-test="db-tables"
                    >
                        {tables === null && !connection?.error && (
                            <li className="px-2 py-1 text-xs text-muted-foreground">
                                Loading…
                            </li>
                        )}
                        {tables?.length === 0 && !tablesError && (
                            <li className="px-2 py-1 text-xs text-muted-foreground">
                                No tables yet.
                            </li>
                        )}
                        {visibleTables.map((table) => {
                            const Icon = table.type === 'view' ? Eye : Table2;
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
                                            active && 'bg-muted font-medium',
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
                                view?.kind === 'sql' && 'bg-muted font-medium',
                            )}
                            data-test="db-open-sql"
                        >
                            <SquareTerminal className="size-3.5 text-muted-foreground" />
                            SQL runner
                        </button>
                    </div>
                </aside>

                {connection?.error || tablesError ? (
                    <Empty tone="error" className="m-4 flex-1 self-start">
                        {connection?.error ?? tablesError}
                    </Empty>
                ) : view?.kind === 'table' && connectionId ? (
                    <TableView
                        key={`${connectionId}:${view.table}`}
                        projectId={projectId}
                        connection={connectionId}
                        table={view.table}
                        onDirtyChange={onDirtyChange}
                    />
                ) : view?.kind === 'sql' && connection ? (
                    <SqlRunner
                        key={connection.id}
                        projectId={projectId}
                        connection={connection.id}
                        driver={connection.driver}
                        tables={tables}
                        initialSql={
                            tables?.[0]
                                ? `select * from ${tables[0].name} limit 50`
                                : ''
                        }
                        onRan={() => void loadTables(connection.id, true)}
                    />
                ) : (
                    <div className="flex flex-1 items-center justify-center text-muted-foreground">
                        <Database className="size-8 opacity-30" />
                    </div>
                )}
            </div>
        </div>
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
