import {
    MySQL,
    PostgreSQL,
    SQLite,
    sql as sqlLanguage,
} from '@codemirror/lang-sql';
import type { SQLNamespace } from '@codemirror/lang-sql';
import type { CompletionSource } from '@codemirror/autocomplete';
import type { LanguageSupport } from '@codemirror/language';
import { languages } from '@codemirror/language-data';
import { Prec } from '@codemirror/state';
import { EditorView, keymap } from '@codemirror/view';
import { oneDark } from '@codemirror/theme-one-dark';
import CodeMirror from '@uiw/react-codemirror';
import { Play, Sparkles } from 'lucide-react';
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

const MONGO_METHODS = [
    'find',
    'findOne',
    'aggregate',
    'countDocuments',
    'estimatedDocumentCount',
    'distinct',
    'insertOne',
    'insertMany',
    'updateOne',
    'updateMany',
    'replaceOne',
    'deleteOne',
    'deleteMany',
];
const MONGO_CURSOR_METHODS = [
    'sort',
    'skip',
    'limit',
    'projection',
    'count',
    'toArray',
];
const MONGO_DB_METHODS = [
    'getCollection',
    'getSiblingDB',
    'runCommand',
    'adminCommand',
    'getCollectionNames',
];
const MONGO_LITERALS = [
    'ObjectId',
    'ISODate',
    'NumberLong',
    'NumberDecimal',
    'UUID',
];
const MONGO_OPERATORS = [
    '$eq',
    '$ne',
    '$gt',
    '$gte',
    '$lt',
    '$lte',
    '$in',
    '$nin',
    '$exists',
    '$regex',
    '$and',
    '$or',
    '$not',
    '$elemMatch',
    '$set',
    '$unset',
    '$inc',
    '$push',
    '$pull',
    '$addToSet',
    '$match',
    '$group',
    '$project',
    '$sort',
    '$limit',
    '$skip',
    '$lookup',
    '$unwind',
    '$count',
    '$sum',
    '$avg',
];

/** Commands that only read, so they run on the hosted database without asking (HOST-007). */
const MONGO_READ_ONLY =
    /^\s*(?:use\s+\S+\s*;?\s*)*(?:show\s+\w+\s*;?\s*$|db\.getCollectionNames\(|db\.(?:getSiblingDB\([^)]*\)\.)?(?:getCollection\([^)]*\)|[\w$]+)\.(?:find|findOne|aggregate|count|countDocuments|estimatedDocumentCount|distinct)\()/;

/**
 * Whether a query only reads, so it may run without asking: on the hosted database (HOST-007), or straight after the
 * AI writes it (DB-003).
 */
function readsOnly(query: string, mongo: boolean): boolean {
    return mongo
        ? MONGO_READ_ONLY.test(query) && !/\$(out|merge)\b/.test(query)
        : /^\s*(select|with|pragma|explain|show|describe)\b/i.test(query);
}

/**
 * How mongosh names a collection: db.users, or db.getCollection('my-users') when it isn't a plain name.
 */
export function mongoCollectionReference(collection: string): string {
    return /^[A-Za-z_$][\w$]*$/.test(collection)
        ? `db.${collection}`
        : `db.getCollection('${collection.replace(/\\/g, '\\\\').replace(/'/g, "\\'")}')`;
}

/**
 * Completions for mongosh-style commands (DB-002): collections and database methods after `db.`, collection
 * methods after a collection, cursor methods after a call, query operators after `$`, and otherwise the fields
 * of the collection the command names. Collections are those of the database `use` picks, when it picks one.
 */
function mongoCompletions(tables: DatabaseTable[]): CompletionSource {
    return (context) => {
        const text = context.state.doc.toString();
        const before = context.state.sliceDoc(0, context.pos);
        const database = /^\s*use\s+([^\s;]+)/m.exec(text)?.[1];
        const collections = tables.filter(
            (table) =>
                !table.database ||
                table.name === table.collection ||
                !database ||
                table.database === database,
        );
        const word = context.matchBefore(/[\w$]*/);

        if (!word) {
            return null;
        }

        const option = (label: string, type: string, detail?: string) => ({
            label,
            type,
            detail,
            boost: 1,
        });
        const result = (options: ReturnType<typeof option>[]) => ({
            from: word.from,
            options,
            validFor: /^[\w$]*$/,
        });

        if (/\bdb\.[\w$]*$/.test(before)) {
            return result([
                ...collections.map((table) => {
                    const name = table.collection ?? table.name;
                    const reference = mongoCollectionReference(name);

                    return {
                        ...option(
                            name,
                            'class',
                            table.type === 'view' ? 'view' : 'collection',
                        ),
                        apply: reference.slice(3),
                    };
                }),
                ...MONGO_DB_METHODS.map((name) => option(name, 'method')),
            ]);
        }

        if (
            /\bdb\.(?:[A-Za-z_$][\w$]*|getCollection\([^)]*\))\s*\.[\w$]*$/.test(
                before,
            )
        ) {
            return result(MONGO_METHODS.map((name) => option(name, 'method')));
        }

        if (/\)\s*\.[\w$]*$/.test(before)) {
            return result(
                MONGO_CURSOR_METHODS.map((name) => option(name, 'method')),
            );
        }

        if (word.from === word.to && !context.explicit) {
            return null;
        }

        if (word.text.startsWith('$')) {
            return result(
                MONGO_OPERATORS.map((name) => option(name, 'keyword')),
            );
        }

        const named =
            /\bdb\.(?:getCollection\((['"])(.+?)\1\)|([A-Za-z_$][\w$]*))\s*\./.exec(
                text,
            );
        const collection = named?.[2] ?? named?.[3];
        const fields =
            collections.find(
                (table) => (table.collection ?? table.name) === collection,
            )?.columns ?? [];

        return result([
            ...fields.map((name) => option(name, 'property', collection)),
            ...MONGO_LITERALS.map((name) => option(name, 'function')),
        ]);
    };
}

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
    const mongo = driver === 'mongodb';
    const [sql, setSql] = useState(initialSql);
    // MongoDB commands are JavaScript; its highlighting is loaded when first needed.
    const [javascript, setJavascript] = useState<LanguageSupport | null>(null);

    useEffect(() => {
        if (!mongo) {
            return;
        }

        let cancelled = false;
        void languages
            .find((language) => language.name === 'JavaScript')
            ?.load()
            .then((support) => !cancelled && setJavascript(support));

        return () => {
            cancelled = true;
        };
    }, [mongo]);
    const [result, setResult] = useState<DatabaseQueryResult | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [running, setRunning] = useState(false);
    const [request, setRequest] = useState('');
    const [writing, setWriting] = useState(false);
    const [writeError, setWriteError] = useState<string | null>(null);
    const [awaitingRun, setAwaitingRun] = useState(false);

    const run = async (query: string = sql) => {
        if (!query.trim() || running) {
            return;
        }

        setAwaitingRun(false);

        // On the hosted app's database, anything that may change it is confirmed first (HOST-007).
        if (
            where === 'hosted' &&
            !readsOnly(query, mongo) &&
            !window.confirm(
                'Run this on the hosted app’s live database? Your visitors’ data changes too.',
            )
        ) {
            return;
        }

        setRunning(true);

        try {
            setResult(
                await databaseApi.query(projectId, connection, query, where),
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

    /** Have the project's AI write the query (DB-003): it replaces the editor's text, and runs if it only reads. */
    const writeQuery = async () => {
        if (!request.trim() || writing) {
            return;
        }

        setWriting(true);
        setWriteError(null);
        setAwaitingRun(false);

        try {
            const query = await databaseApi.writeQuery(
                projectId,
                { connection, driver, request, current: sql },
                where,
            );

            setSql(query);

            if (readsOnly(query, mongo)) {
                await run(query);
            } else {
                setResult(null);
                setError(null);
                setAwaitingRun(true);
            }
        } catch (e) {
            setWriteError((e as Error).message);
        } finally {
            setWriting(false);
        }
    };

    // The keymap is built once, so it calls the latest run through a ref.
    const runRef = useRef(run);
    useEffect(() => {
        runRef.current = run;
    });

    const extensions = useMemo(
        () => [
            ...(driver === 'mongodb'
                ? javascript
                    ? [
                          javascript,
                          javascript.language.data.of({
                              autocomplete: mongoCompletions(tables ?? []),
                          }),
                      ]
                    : []
                : [
                      sqlLanguage({
                          dialect: dialects[driver],
                          schema: completionSchema(tables ?? [], driver),
                          defaultSchema:
                              driver === 'pgsql' ? 'public' : undefined,
                      }),
                      dialects[driver].language.data.of({
                          autocomplete: mentionedTableColumns(tables ?? []),
                      }),
                  ]),
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
                'aria-label': mongo ? 'Query' : 'SQL',
                'data-test': 'db-sql',
            }),
        ],
        [driver, mongo, tables, javascript],
    );
    const rowWord = mongo ? 'document' : 'row';

    return (
        <div
            className="flex min-h-0 min-w-0 flex-1 flex-col"
            data-test="db-sql-runner"
        >
            <div className="border-b border-sidebar-border/70 p-3 dark:border-sidebar-border">
                <form
                    className="mb-2 flex items-center gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        void writeQuery();
                    }}
                    data-test="db-ask-ai"
                >
                    <label className="flex h-8 min-w-0 flex-1 items-center gap-2 rounded-md border border-input px-2 focus-within:ring-2 focus-within:ring-ring/50">
                        <Sparkles className="size-3.5 shrink-0 text-muted-foreground" />
                        <input
                            value={request}
                            onChange={(event) => setRequest(event.target.value)}
                            placeholder={
                                mongo
                                    ? 'Describe what to find, e.g. users who signed up this week'
                                    : 'Describe what to find, e.g. orders over $100 from last month'
                            }
                            className="min-w-0 flex-1 bg-transparent text-xs outline-none"
                            aria-label="Describe the query for AI to write"
                            data-test="db-ask-ai-input"
                            disabled={writing}
                        />
                    </label>
                    <Button
                        type="submit"
                        size="sm"
                        variant="secondary"
                        disabled={writing || !request.trim()}
                        data-test="db-ask-ai-submit"
                    >
                        {writing ? 'Writing…' : 'Write query'}
                    </Button>
                </form>
                {awaitingRun && (
                    <p
                        className="mb-2 text-xs text-amber-700 dark:text-amber-400"
                        data-test="db-ask-ai-review"
                    >
                        This query changes data, so it hasn’t run. Check it,
                        then press Run.
                    </p>
                )}
                {writeError && (
                    <p
                        className="mb-2 text-xs text-red-600"
                        data-test="db-ask-ai-error"
                    >
                        {writeError}
                    </p>
                )}
                <CodeMirror
                    value={sql}
                    onChange={setSql}
                    extensions={extensions}
                    theme={resolvedAppearance === 'dark' ? oneDark : 'light'}
                    minHeight="7.5rem"
                    maxHeight="40vh"
                    placeholder={
                        mongo
                            ? "db.users.find({ name: 'Ada' })"
                            : 'select * from users limit 10'
                    }
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
                        onClick={() => void run()}
                        disabled={running || !sql.trim()}
                        data-test="db-run"
                    >
                        <Play /> {running ? 'Running…' : 'Run'}
                    </Button>
                    <span className="text-xs text-muted-foreground">
                        {mongo
                            ? '⌘/Ctrl+Enter to run. One command at a time (put “use <database>” on a line before it to pick one); changes are saved immediately.'
                            : '⌘/Ctrl+Enter to run. One statement at a time; changes are saved immediately.'}
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
                        Done. {result.affected ?? 0} {rowWord}
                        {result.affected === 1 ? '' : 's'} affected in{' '}
                        {result.duration_ms} ms.
                    </p>
                )}
                {result && result.columns.length > 0 && (
                    <>
                        <p
                            className="px-3 py-1.5 text-xs text-muted-foreground"
                            data-test="db-sql-summary"
                        >
                            {result.rows.length.toLocaleString()} {rowWord}
                            {result.rows.length === 1 ? '' : 's'}
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
