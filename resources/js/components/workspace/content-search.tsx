import { CaseSensitive, ChevronRight, Regex, WholeWord } from 'lucide-react';
import { useEffect, useState } from 'react';
import ProjectFileController from '@/actions/App/Http/Controllers/ProjectFileController';
import FileIcon from '@/components/workspace/file-icon';
import { cn } from '@/lib/utils';

/** What to search the project's files for (FILE-008), and how. */
export type ContentQuery = {
    query: string;
    case: boolean;
    word: boolean;
    regex: boolean;
};

export const EMPTY_CONTENT_QUERY: ContentQuery = {
    query: '',
    case: false,
    word: false,
    regex: false,
};

export type ContentMatch = {
    line: number;
    /** Character offsets of the first match in the whole line. */
    column: [number, number];
    text: string;
    /** Character offsets of each match in `text`. */
    ranges: [number, number][];
};

export type ContentResult = { path: string; matches: ContentMatch[] };

type SearchState = {
    results: ContentResult[];
    truncated: boolean;
    loading: boolean;
    error: string | null;
};

/** How long typing pauses before a search runs. */
const DEBOUNCE_MS = 200;

/**
 * Searches inside the project's files (in `folder`, or everywhere) as the query changes, keeping only the latest
 * answer. `version` re-runs the same search, e.g. after files change.
 */
export function useContentSearch(
    projectId: number,
    search: ContentQuery,
    folder: string,
    enabled: boolean,
    version = 0,
): SearchState {
    const [state, setState] = useState<SearchState>({
        results: [],
        truncated: false,
        loading: false,
        error: null,
    });
    const query = search.query;

    useEffect(() => {
        if (!enabled || query.trim() === '') {
            setState({
                results: [],
                truncated: false,
                loading: false,
                error: null,
            });

            return;
        }

        const controller = new AbortController();
        setState((current) => ({ ...current, loading: true }));

        const timer = window.setTimeout(() => {
            const url = ProjectFileController.search.url(projectId, {
                query: {
                    query,
                    folder: folder || undefined,
                    case: search.case ? 1 : 0,
                    word: search.word ? 1 : 0,
                    regex: search.regex ? 1 : 0,
                },
            });

            fetch(url, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: controller.signal,
            })
                .then(async (response) => {
                    const body = await response.json().catch(() => ({}));

                    if (!response.ok) {
                        throw new Error(
                            body.message ??
                                `Request failed (${response.status})`,
                        );
                    }

                    setState({
                        results: body.results,
                        truncated: body.truncated,
                        loading: false,
                        error: null,
                    });
                })
                .catch((e: Error) => {
                    if (!controller.signal.aborted) {
                        setState({
                            results: [],
                            truncated: false,
                            loading: false,
                            error: e.message,
                        });
                    }
                });
        }, DEBOUNCE_MS);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [
        projectId,
        enabled,
        query,
        folder,
        search.case,
        search.word,
        search.regex,
        version,
    ]);

    return state;
}

/** The "Search in files" box's toggles, like VS Code's: match case, whole word, regular expression. */
export function ContentSearchToggles({
    search,
    onChange,
    disabled,
}: {
    search: ContentQuery;
    onChange: (search: ContentQuery) => void;
    disabled?: boolean;
}) {
    const toggles = [
        { key: 'case', label: 'Match case', Icon: CaseSensitive },
        { key: 'word', label: 'Match whole word', Icon: WholeWord },
        { key: 'regex', label: 'Use regular expression', Icon: Regex },
    ] as const;

    return toggles.map(({ key, label, Icon }) => (
        <button
            key={key}
            type="button"
            title={label}
            aria-label={label}
            aria-pressed={search[key]}
            disabled={disabled}
            onClick={() => onChange({ ...search, [key]: !search[key] })}
            className={cn(
                'shrink-0 rounded p-0.5 text-muted-foreground hover:bg-muted hover:text-foreground disabled:opacity-40',
                search[key] &&
                    'bg-primary/15 text-foreground ring-1 ring-primary/40',
            )}
            data-test={`content-search-${key}`}
        >
            <Icon className="size-3.5" />
        </button>
    ));
}

/**
 * Matches grouped by file, each file foldable; clicking a line opens the file there.
 */
export function ContentSearchResults({
    results,
    truncated,
    loading,
    error,
    onOpen,
}: SearchState & {
    onOpen: (path: string, match: ContentMatch) => void;
}) {
    const [folded, setFolded] = useState<Set<string>>(new Set());
    const total = results.reduce(
        (sum, result) => sum + result.matches.length,
        0,
    );

    if (error) {
        return (
            <p
                className="p-3 text-sm text-red-600"
                role="alert"
                data-test="content-search-error"
            >
                {error}
            </p>
        );
    }

    if (results.length === 0) {
        return (
            <p className="p-3 text-sm text-muted-foreground">
                {loading ? 'Searching…' : 'No results.'}
            </p>
        );
    }

    const toggle = (path: string) =>
        setFolded((current) => {
            const next = new Set(current);

            if (!next.delete(path)) {
                next.add(path);
            }

            return next;
        });

    return (
        <div
            className={cn('py-1', loading && 'opacity-60')}
            data-test="content-search-results"
        >
            <p className="px-2 py-1 text-xs text-muted-foreground">
                {total} {total === 1 ? 'result' : 'results'} in {results.length}{' '}
                {results.length === 1 ? 'file' : 'files'}
                {truncated && ' (showing the first ones; narrow the search)'}
            </p>
            <ul>
                {results.map((result) => {
                    const slash = result.path.lastIndexOf('/');
                    const name = result.path.slice(slash + 1);
                    const isOpen = !folded.has(result.path);

                    return (
                        <li key={result.path}>
                            <button
                                type="button"
                                onClick={() => toggle(result.path)}
                                title={result.path}
                                className="flex w-full items-center gap-1.5 rounded py-1 pr-2 pl-1 text-left text-sm hover:bg-muted"
                                data-test={`content-result-${result.path}`}
                            >
                                <ChevronRight
                                    className={cn(
                                        'size-3 shrink-0 text-muted-foreground transition-transform',
                                        isOpen && 'rotate-90',
                                    )}
                                />
                                <FileIcon name={name} />
                                <span className="shrink-0">{name}</span>
                                <span className="min-w-0 flex-1 truncate text-xs text-muted-foreground">
                                    {slash === -1
                                        ? ''
                                        : result.path.slice(0, slash)}
                                </span>
                                <span className="shrink-0 rounded-full bg-muted px-1.5 text-xs text-muted-foreground">
                                    {result.matches.length}
                                </span>
                            </button>
                            {isOpen && (
                                <ul>
                                    {result.matches.map((match, index) => (
                                        <li key={index}>
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    onOpen(result.path, match)
                                                }
                                                title={`${result.path}:${match.line}`}
                                                className="flex w-full items-baseline gap-2 rounded py-0.5 pr-2 pl-7 text-left font-mono text-xs hover:bg-muted"
                                                data-test="content-match"
                                            >
                                                <span className="min-w-0 flex-1 truncate whitespace-pre">
                                                    <Highlighted
                                                        match={match}
                                                    />
                                                </span>
                                                <span className="shrink-0 text-muted-foreground">
                                                    {match.line}
                                                </span>
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}

/** A matching line, leading whitespace dropped, with its matches marked. */
function Highlighted({ match }: { match: ContentMatch }) {
    const indent = Math.min(
        match.text.length - match.text.trimStart().length,
        match.ranges[0]?.[0] ?? 0,
    );
    const parts: React.ReactNode[] = [];
    let at = indent;

    match.ranges.forEach(([from, to], index) => {
        if (from < at) {
            return;
        }

        parts.push(match.text.slice(at, from));
        parts.push(
            <mark
                key={index}
                className="rounded-sm bg-amber-300/60 text-foreground dark:bg-amber-500/40"
            >
                {match.text.slice(from, to)}
            </mark>,
        );
        at = to;
    });
    parts.push(match.text.slice(at));

    return <>{parts}</>;
}
