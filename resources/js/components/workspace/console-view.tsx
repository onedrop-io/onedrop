import { Search } from 'lucide-react';
import {
    useDeferredValue,
    useEffect,
    useLayoutEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import ProjectLogController from '@/actions/App/Http/Controllers/ProjectLogController';
import { highlightLog } from '@/components/log-highlight';
import { Input } from '@/components/ui/input';
import { parseLogSearch } from '@/lib/log-search';
import { cn } from '@/lib/utils';

/** Keep the view light: only the most recent output is kept on screen. */
const MAX_CHARS = 200_000;

/** An escape cut off at the end of a read; the rest comes with the next one. */
// eslint-disable-next-line no-control-regex
const PARTIAL_ESCAPE = /\x1b(?:\[[0-9;?]*)?$/;

/**
 * The output kept: whole lines only once trimmed, with how many lines were dropped from the front, so a line
 * keeps its number (and its place when shown in context) as older output goes.
 */
type Output = { text: string; dropped: number };

/**
 * Follows the app dev server's output while visible, in colour (components/log-highlight.tsx), narrowed to
 * the lines passing the search (lib/log-search.ts). Clicking a matching line clears the search and shows it
 * among its neighbours. It stays scrolled to the newest line unless the user scrolls up.
 */
export default function ConsoleView({
    projectId,
    active,
    running,
    clearSignal,
}: {
    projectId: number;
    active: boolean;
    running: boolean;
    /** Increment to clear the view. */
    clearSignal: number;
}) {
    const [output, setOutput] = useState<Output>({ text: '', dropped: 0 });
    const [error, setError] = useState<string | null>(null);
    const [query, setQuery] = useState('');
    const [contextLine, setContextLine] = useState<number | null>(null);
    const offset = useRef<number | null>(null);
    const scroller = useRef<HTMLPreElement>(null);
    const atBottom = useRef(true);

    const [lastClear, setLastClear] = useState(clearSignal);

    if (lastClear !== clearSignal) {
        setLastClear(clearSignal);
        setOutput(({ text, dropped }) => ({
            text: '',
            dropped: dropped + lineCount(text),
        }));
        setContextLine(null);
    }

    const deferredQuery = useDeferredValue(query);
    const search = useMemo(
        () => parseLogSearch(deferredQuery),
        [deferredQuery],
    );
    const shown = useMemo(
        () =>
            highlightLog(output.text.replace(PARTIAL_ESCAPE, ''), search).map(
                (line) => ({ ...line, index: line.index + output.dropped }),
            ),
        [output, search],
    );

    useEffect(() => {
        if (!active || !running) {
            return;
        }

        let cancelled = false;

        const poll = async () => {
            const query =
                offset.current === null ? {} : { offset: offset.current };
            const response = await fetch(
                ProjectLogController.index.url(projectId, { query }),
                {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                },
            ).catch(() => null);

            if (cancelled || !response) {
                return;
            }

            const body = await response.json().catch(() => ({}));

            if (!response.ok) {
                setError(body.message ?? 'Could not read the log.');

                return;
            }

            setError(null);
            offset.current = body.offset;

            if (body.content) {
                setOutput((current) => append(current, body.content));
            }
        };

        void poll();
        const timer = window.setInterval(() => void poll(), 1000);

        return () => {
            cancelled = true;
            window.clearInterval(timer);
        };
    }, [active, running, projectId]);

    useLayoutEffect(() => {
        const element = scroller.current;

        if (!element) {
            return;
        }

        if (contextLine !== null) {
            element
                .querySelector(`[data-line="${contextLine}"]`)
                ?.scrollIntoView({ block: 'center' });
        } else if (atBottom.current) {
            element.scrollTop = element.scrollHeight;
        }
    }, [shown, contextLine]);

    if (!running) {
        return <Notice>The console starts when the sandbox is running.</Notice>;
    }

    const changeQuery = (next: string) => {
        setContextLine(null);
        atBottom.current = true;
        setQuery(next);
    };

    const showInContext = (index: number) => {
        atBottom.current = false;
        setContextLine(index);
        setQuery('');
    };

    return (
        <div className="flex min-h-0 flex-1 flex-col">
            <div className="flex items-center gap-2 border-b p-1.5">
                <div className="relative flex-1">
                    <Search className="pointer-events-none absolute top-1/2 left-2 size-3.5 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        type="search"
                        value={query}
                        onChange={(event) => changeQuery(event.target.value)}
                        placeholder='Search: words, "a phrase", -exclude, a OR b'
                        aria-label="Search console"
                        className="h-7 pl-7 text-xs"
                        data-test="console-search"
                    />
                </div>
                {search && (
                    <span
                        className="shrink-0 pr-1 text-xs text-muted-foreground"
                        data-test="console-matches"
                    >
                        {shown.length === 1
                            ? '1 line'
                            : `${shown.length} lines`}
                    </span>
                )}
            </div>
            <pre
                ref={scroller}
                onScroll={(event) => {
                    const element = event.currentTarget;
                    atBottom.current =
                        element.scrollHeight -
                            element.scrollTop -
                            element.clientHeight <
                        40;
                }}
                className="flex-1 overflow-auto bg-neutral-950 p-3 font-mono text-xs leading-5 whitespace-pre-wrap text-neutral-200"
                data-test="console-output"
            >
                {error ? (
                    <span className="text-red-400">{error}</span>
                ) : !output.text ? (
                    <span className="text-neutral-500">No output yet.</span>
                ) : shown.length ? (
                    shown.map((line) => (
                        <div
                            key={line.index}
                            data-line={line.index}
                            onClick={
                                search
                                    ? () => showInContext(line.index)
                                    : undefined
                            }
                            title={
                                search
                                    ? 'Show among the lines around it'
                                    : undefined
                            }
                            className={cn(
                                'min-h-lh',
                                search && 'cursor-pointer hover:bg-white/5',
                                contextLine === line.index && 'bg-amber-400/15',
                            )}
                        >
                            {line.nodes}
                        </div>
                    ))
                ) : (
                    <span className="text-neutral-500">No lines match.</span>
                )}
            </pre>
        </div>
    );
}

/** The output with `content` added, trimmed to whole lines within MAX_CHARS. */
function append({ text, dropped }: Output, content: string): Output {
    const next = text + content;

    if (next.length <= MAX_CHARS) {
        return { text: next, dropped };
    }

    const cut = next.indexOf('\n', next.length - MAX_CHARS);

    if (cut === -1) {
        return {
            text: next.slice(-MAX_CHARS),
            dropped: dropped + lineCount(next),
        };
    }

    return {
        text: next.slice(cut + 1),
        dropped: dropped + lineCount(next.slice(0, cut + 1)),
    };
}

/** Lines in finished text: one per newline. */
function lineCount(text: string): number {
    return text.split('\n').length - 1;
}

export function Notice({ children }: { children: React.ReactNode }) {
    return (
        <p className="p-4 text-sm text-muted-foreground" data-test="tab-notice">
            {children}
        </p>
    );
}
