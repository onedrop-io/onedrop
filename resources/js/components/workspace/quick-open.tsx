import { useEffect, useMemo, useRef, useState } from 'react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import FileIcon from '@/components/workspace/file-icon';
import { cn } from '@/lib/utils';
import type { WorkspaceEntry } from '@/types';

/** Most results shown at once; typing more narrows them down. */
const MAX_RESULTS = 50;

type Match = { path: string; score: number; positions: number[] };

/** Whether `index` starts a word in `text`: after a separator, or a capital after a lowercase letter. */
function startsWord(text: string, index: number): boolean {
    if (index === 0) {
        return true;
    }

    const before = text[index - 1];

    return (
        '/_-. '.includes(before) ||
        (before === before.toLowerCase() &&
            text[index] !== text[index].toLowerCase())
    );
}

/**
 * Where `needle`'s letters appear in order in `text` (from `offset`), scored so word starts and runs
 * of letters rank higher; null when they don't all appear.
 */
function matchIn(
    text: string,
    needle: string,
    offset: number,
    preferWordStarts: boolean,
): { score: number; positions: number[] } | null {
    const haystack = text.toLowerCase();
    const positions: number[] = [];
    let score = 0;
    let from = offset;

    for (const letter of needle) {
        let index = haystack.indexOf(letter, from);

        if (index === -1) {
            return null;
        }

        const previous = positions.at(-1);

        // Jump ahead to a word start with this letter, unless it continues a run.
        if (
            preferWordStarts &&
            (previous === undefined || index !== previous + 1)
        ) {
            for (
                let next = index;
                next !== -1;
                next = haystack.indexOf(letter, next + 1)
            ) {
                if (startsWord(text, next)) {
                    index = next;
                    break;
                }
            }
        }

        score += 1;
        score += startsWord(text, index) ? 8 : 0;
        score += previous !== undefined && index === previous + 1 ? 5 : 0;
        positions.push(index);
        from = index + 1;
    }

    return { score, positions };
}

/** The better of matching letters as early as possible, or at word starts (which can run out of text). */
function bestMatchIn(
    text: string,
    needle: string,
    offset: number,
): { score: number; positions: number[] } | null {
    const early = matchIn(text, needle, offset, false);
    const atWords = early && matchIn(text, needle, offset, true);

    return atWords && atWords.score > early.score ? atWords : early;
}

/**
 * Files whose path has `query`'s letters in order (spaces ignored), best first: letters in the file's
 * own name beat ones spread over its folders, and shorter paths win ties (FILE-006).
 */
export function quickOpenMatches(
    entries: WorkspaceEntry[],
    query: string,
    recent: string[] = [],
): Match[] {
    const files = entries.filter((entry) => entry.type === 'file');
    const needle = query.toLowerCase().replace(/\s+/g, '');

    if (needle === '') {
        const paths = new Set(files.map((entry) => entry.path));
        const recentFiles = recent.filter((path) => paths.has(path));

        return [
            ...recentFiles,
            ...files
                .map((entry) => entry.path)
                .filter((path) => !recentFiles.includes(path)),
        ]
            .slice(0, MAX_RESULTS)
            .map((path) => ({ path, score: 0, positions: [] }));
    }

    const matches: Match[] = [];

    for (const { path } of files) {
        const nameStart = path.lastIndexOf('/') + 1;
        const inName = bestMatchIn(path, needle, nameStart);
        const match = inName
            ? { ...inName, score: inName.score + 20 }
            : bestMatchIn(path, needle, 0);

        if (match) {
            matches.push({ path, ...match });
        }
    }

    return matches
        .sort(
            (a, b) =>
                b.score - a.score ||
                a.path.length - b.path.length ||
                a.path.localeCompare(b.path),
        )
        .slice(0, MAX_RESULTS);
}

/** `text` with the letters at `positions` (counted from `offset`) in bold. */
function Highlighted({
    text,
    positions,
    offset = 0,
}: {
    text: string;
    positions: Set<number>;
    offset?: number;
}) {
    return (
        <>
            {text.split('').map((letter, index) =>
                positions.has(index + offset) ? (
                    <mark
                        key={index}
                        className="bg-transparent font-semibold text-primary"
                    >
                        {letter}
                    </mark>
                ) : (
                    letter
                ),
            )}
        </>
    );
}

/**
 * Cmd/Ctrl+P: find a file by typing parts of its name or path, and open it (FILE-006).
 */
export default function QuickOpen({
    open,
    onOpenChange,
    entries,
    recent,
    loading,
    notice,
    onOpenFile,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    entries: WorkspaceEntry[];
    /** Files opened lately, newest first; listed first before anything is typed. */
    recent: string[];
    loading: boolean;
    /** Shown instead of results, e.g. while the sandbox isn't running. */
    notice?: string | null;
    onOpenFile: (path: string) => void;
}) {
    const [query, setQuery] = useState('');
    const [active, setActive] = useState(0);
    const list = useRef<HTMLUListElement>(null);
    const matches = useMemo(
        () => quickOpenMatches(entries, query, recent),
        [entries, query, recent],
    );

    useEffect(() => {
        if (open) {
            setQuery('');
            setActive(0);
        }
    }, [open]);

    useEffect(() => {
        list.current
            ?.querySelector(`[data-index="${active}"]`)
            ?.scrollIntoView({ block: 'nearest' });
    }, [active]);

    const choose = (path: string) => {
        onOpenChange(false);
        onOpenFile(path);
    };

    const onKeyDown = (event: React.KeyboardEvent) => {
        if (event.key === 'ArrowDown' || (event.ctrlKey && event.key === 'n')) {
            event.preventDefault();
            setActive((index) => Math.min(index + 1, matches.length - 1));
        } else if (
            event.key === 'ArrowUp' ||
            (event.ctrlKey && event.key === 'p')
        ) {
            event.preventDefault();
            setActive((index) => Math.max(index - 1, 0));
        } else if (event.key === 'Enter' && matches[active]) {
            event.preventDefault();
            choose(matches[active].path);
        }
    };

    const empty =
        notice ??
        (matches.length > 0
            ? null
            : query.trim() !== ''
              ? 'No files match.'
              : loading
                ? 'Loading…'
                : 'No files yet.');

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className="top-[12%] translate-y-0 gap-0 overflow-hidden p-0 sm:max-w-xl [&>button:last-child]:hidden"
                data-test="quick-open"
            >
                <DialogTitle className="sr-only">Go to file</DialogTitle>
                <DialogDescription className="sr-only">
                    Type part of a file's name or path, then press Enter to open
                    it.
                </DialogDescription>
                <input
                    autoFocus
                    value={query}
                    onChange={(event) => {
                        setQuery(event.target.value);
                        setActive(0);
                    }}
                    onKeyDown={onKeyDown}
                    placeholder="Go to file…"
                    aria-label="File name or path"
                    role="combobox"
                    aria-expanded
                    aria-controls="quick-open-results"
                    aria-activedescendant={
                        matches[active] ? `quick-open-${active}` : undefined
                    }
                    className="h-12 border-b bg-transparent px-4 text-sm outline-none placeholder:text-muted-foreground"
                    data-test="quick-open-input"
                />
                {empty ? (
                    <p className="px-4 py-6 text-center text-sm text-muted-foreground">
                        {empty}
                    </p>
                ) : (
                    <ul
                        ref={list}
                        id="quick-open-results"
                        role="listbox"
                        className="max-h-[min(24rem,60vh)] overflow-y-auto p-1"
                    >
                        {matches.map((match, index) => {
                            const slash = match.path.lastIndexOf('/');
                            const name = match.path.slice(slash + 1);
                            const folder =
                                slash === -1 ? '' : match.path.slice(0, slash);
                            const positions = new Set(match.positions);

                            return (
                                <li
                                    key={match.path}
                                    id={`quick-open-${index}`}
                                    role="option"
                                    aria-selected={index === active}
                                    data-index={index}
                                    data-test={`quick-open-${match.path}`}
                                    onMouseMove={() => setActive(index)}
                                    onClick={() => choose(match.path)}
                                    className={cn(
                                        'flex cursor-pointer items-center gap-2 rounded px-3 py-1.5 text-sm',
                                        index === active && 'bg-muted',
                                    )}
                                >
                                    <FileIcon name={name} isDir={false} />
                                    <span className="shrink-0">
                                        <Highlighted
                                            text={name}
                                            positions={positions}
                                            offset={slash + 1}
                                        />
                                    </span>
                                    {folder && (
                                        <span className="truncate text-xs text-muted-foreground/70">
                                            <Highlighted
                                                text={folder}
                                                positions={positions}
                                            />
                                        </span>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                )}
            </DialogContent>
        </Dialog>
    );
}
