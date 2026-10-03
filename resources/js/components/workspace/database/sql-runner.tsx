import {
    MySQL,
    PostgreSQL,
    SQLite,
    sql as sqlLanguage,
} from '@codemirror/lang-sql';
import type { SQLNamespace } from '@codemirror/lang-sql';
import type { CompletionSource } from '@codemirror/autocomplete';
import { Prec } from '@codemirror/state';
import { EditorView, keymap } from '@codemirror/view';
import { oneDark } from '@codemirror/theme-one-dark';
import CodeMirror from '@uiw/react-codemirror';
import { Play } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import CellValue from '@/components/workspace/database/cell-value';
import { useAppearance } from '@/hooks/use-appearance';
import { databaseApi } from '@/lib/database-api';
import type { DatabaseLocation } from '@/lib/database-api';
import type {
    DatabaseConnection,
    DatabaseQueryResult,
    DatabaseTable,
} from '@/types';

const dialects = { sqlite: SQLite, pgsql: PostgreSQL, mysql: MySQL };

/**
 * Tables and their columns in the shape the SQL autocomplete expects.
 * PostgreSQL tables outside `public` are listed as "schema.table".
 */
function completionSchema(
    tables: DatabaseTable[],
    driver: DatabaseConnection['driver'],
): SQLNamespace {
    const schema: Record<string, string[] | Record<string, string[]>> = {};

    for (const table of tables) {
        const dot = driver === 'pgsql' ? table.name.indexOf('.') : -1;

        if (dot === -1) {
            schema[table.name] = table.columns;
        } else {
            const namespace = table.name.slice(0, dot);
            const existing = schema[namespace];
            schema[namespace] = {
                ...(Array.isArray(existing) ? {} : existing),
                [table.name.slice(dot + 1)]: table.columns,
            };
        }
    }

    return schema;
}

/**
 * Complete column names of the tables the statement mentions, without
 * needing a "table." prefix (lang-sql handles prefixed names).
 */
function mentionedTableColumns(tables: DatabaseTable[]): CompletionSource {
    return (context) => {
        const word = context.matchBefore(/\w*/);

        if (
            !word ||
            (word.from === word.to && !context.explicit) ||
            context.state.sliceDoc(word.from - 1, word.from) === '.'
        ) {
            return null;
        }

        const mentioned = new Set(
            (context.state.doc.toString().match(/[\w.]+/g) ?? []).map((name) =>
                name.toLowerCase(),
            ),
        );
        const columns = new Map<string, string>();

        for (const table of tables) {
            if (mentioned.has(table.name.toLowerCase())) {
                for (const column of table.columns) {
                    if (!columns.has(column)) {
                        columns.set(column, table.name);
                    }
                }
            }
        }

        return {
            from: word.from,
            options: [...columns].map(([label, table]) => ({
                label,
                detail: table,
                type: 'property',
                boost: 1,
            })),
            validFor: /^\w*$/,
        };
    };
}

/**
 * Run one SQL statement against the selected database and show its result.
 * The editor highlights SQL in the database's dialect and completes
 * keywords, table and column names.
 */
export default function SqlRunner({
    projectId,
    connection,
    driver,
    tables,
    initialSql,
    onRan,
    where = 'sandbox',
}: {
    projectId: number;
    where?: DatabaseLocation;
    connection: string;
    driver: DatabaseConnection['driver'];
    tables: DatabaseTable[] | null;
    initialSql: string;
    onRan: () => void;
}) {
    const { resolvedAppearance } = useAppearance();
    const [sql, setSql] = useState(initialSql);
    const [result, setResult] = useState<DatabaseQueryResult | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [running, setRunning] = useState(false);

    const run = async () => {
        if (!sql.trim() || running) {
            return;
        }

        // On the hosted app's database, anything that may change it is confirmed first (HOST-007).
        if (
            where === 'hosted' &&
            !/^\s*(select|with|pragma|explain|show|describe)\b/i.test(sql) &&
            !window.confirm(
                'Run this on the hosted app’s live database? Your visitors’ data changes too.',
            )
        ) {
            return;
        }

        setRunning(true);

        try {
            setResult(
                await databaseApi.query(projectId, connection, sql, where),
            );
            setError(null);
            onRan();
        } catch (e) {
            setResult(null);
            setError((e as Error).message);
        } finally {
            setRunning(false);
        }
    };

    // The keymap is built once, so it calls the latest run through a ref.
    const runRef = useRef(run);
    useEffect(() => {
        runRef.current = run;
    });

    const extensions = useMemo(
        () => [
            sqlLanguage({
                dialect: dialects[driver],
                schema: completionSchema(tables ?? [], driver),
                defaultSchema: driver === 'pgsql' ? 'public' : undefined,
            }),
            dialects[driver].language.data.of({
                autocomplete: mentionedTableColumns(tables ?? []),
            }),
            Prec.highest(
                keymap.of([
                    {
                        key: 'Mod-Enter',
                        preventDefault: true,
                        run: () => {
                            void runRef.current();

                            return true;
                        },
                    },
                ]),
            ),
            EditorView.contentAttributes.of({
                'aria-label': 'SQL',
                'data-test': 'db-sql',
            }),
        ],
        [driver, tables],
    );

    return (
        <div
            className="flex min-h-0 min-w-0 flex-1 flex-col"
            data-test="db-sql-runner"
        >
            <div className="border-b border-sidebar-border/70 p-3 dark:border-sidebar-border">
                <CodeMirror
                    value={sql}
                    onChange={setSql}
                    extensions={extensions}
                    theme={resolvedAppearance === 'dark' ? oneDark : 'light'}
                    minHeight="7.5rem"
                    maxHeight="40vh"
                    placeholder="select * from users limit 10"
                    indentWithTab={false}
                    basicSetup={{
                        lineNumbers: false,
                        foldGutter: false,
                        highlightActiveLine: false,
                        highlightActiveLineGutter: false,
                    }}
                    className="overflow-hidden rounded-md border border-input text-xs focus-within:ring-2 focus-within:ring-ring/50 [&_.cm-editor]:bg-transparent! [&_.cm-focused]:outline-none!"
                />
                <div className="mt-2 flex items-center gap-3">
                    <Button
                        size="sm"
                        onClick={run}
                        disabled={running || !sql.trim()}
                        data-test="db-run"
                    >
                        <Play /> {running ? 'Running…' : 'Run'}
                    </Button>
                    <span className="text-xs text-muted-foreground">
                        ⌘/Ctrl+Enter to run. One statement at a time; changes
                        are saved immediately.
                    </span>
                </div>
            </div>

            <div
                className="min-h-0 flex-1 overflow-auto"
                data-test="db-sql-result"
            >
                {error && (
                    <p
                        className="p-4 font-mono text-xs text-red-600"
                        data-test="db-sql-error"
                    >
                        {error}
                    </p>
                )}
                {result && result.columns.length === 0 && (
                    <p className="p-4 text-sm text-muted-foreground">
                        Done. {result.affected ?? 0}{' '}
                        {result.affected === 1 ? 'row' : 'rows'} affected in{' '}
                        {result.duration_ms} ms.
                    </p>
                )}
                {result && result.columns.length > 0 && (
                    <>
                        <p
                            className="px-3 py-1.5 text-xs text-muted-foreground"
                            data-test="db-sql-summary"
                        >
                            {result.rows.length.toLocaleString()}{' '}
                            {result.rows.length === 1 ? 'row' : 'rows'}
                            {result.truncated && ' (first rows only)'} in{' '}
                            {result.duration_ms} ms
                        </p>
                        <table className="min-w-full border-separate border-spacing-0 text-xs">
                            <thead className="sticky top-0 bg-background">
                                <tr>
                                    {result.columns.map((column, index) => (
                                        <th
                                            key={index}
                                            className="border-y border-r border-sidebar-border/70 px-3 py-1.5 text-left font-medium whitespace-nowrap dark:border-sidebar-border"
                                        >
                                            {column}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {result.rows.map((row, rowIndex) => (
                                    <tr key={rowIndex}>
                                        {row.map((value, index) => (
                                            <td
                                                key={index}
                                                className="max-w-80 truncate border-r border-b border-sidebar-border/70 px-3 py-1.5 font-mono whitespace-nowrap dark:border-sidebar-border"
                                            >
                                                <CellValue value={value} />
                                            </td>
                                        ))}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </>
                )}
            </div>
        </div>
    );
}
