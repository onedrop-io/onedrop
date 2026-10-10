import type { Language } from '@codemirror/language';
import type { ReactNode } from 'react';
import { highlight, useLanguage } from '@/components/code-highlight';
import { cn } from '@/lib/utils';

export type PatchLine = { index: number; text: string; kind: string };
export type Hunk = { number: number; header: string; lines: PatchLine[] };

/** A patch's hunks, each line with its index in the whole patch (what the sandbox's git tool counts). */
export function parseHunks(patch: string): Hunk[] {
    const hunks: Hunk[] = [];

    patch.split('\n').forEach((text, index, all) => {
        if (text.startsWith('@@')) {
            hunks.push({ number: hunks.length, header: text, lines: [] });
        } else if (
            hunks.length > 0 &&
            (index < all.length - 1 || text !== '')
        ) {
            hunks[hunks.length - 1].lines.push({
                index,
                text,
                kind: text[0] ?? ' ',
            });
        }
    });

    return hunks;
}

/** The added and removed lines of a patch. */
export function changeLines(patch: string): PatchLine[] {
    return parseHunks(patch).flatMap((hunk) =>
        hunk.lines.filter((line) => line.kind === '+' || line.kind === '-'),
    );
}

/** Picking single lines, to stage or unstage just those (GIT-009, SCM-001). */
export type PatchPicking = {
    isPicked: (index: number) => boolean;
    toggleLine: (index: number) => void;
};

type Row = {
    line: PatchLine;
    oldNumber: number | null;
    newNumber: number | null;
    /** The part of the line that changed, when it pairs with a line on the other side. */
    changed: [number, number] | null;
    /** A changed line that only differs in spacing, shown as unchanged while whitespace is hidden. */
    spacingOnly: boolean;
};

/**
 * A unified diff's hunks (GIT-012): old and new line numbers, code colored by the file's language, the words that
 * changed within an edited line highlighted, and optionally whitespace-only changes hidden. With `picking`, single
 * changed lines can be picked (GIT-009); `hunkActions` adds buttons to each hunk's header. Git's header lines are left
 * out. Used for a commit's files (GIT-001) and staged and unstaged changes (SCM-001).
 */
export default function PatchView({
    patch,
    truncated,
    path,
    hideWhitespace = false,
    picking = null,
    hunkActions,
    className,
}: {
    patch: string;
    truncated: boolean;
    /** The file's path, to color its code. */
    path?: string;
    hideWhitespace?: boolean;
    picking?: PatchPicking | null;
    hunkActions?: (hunk: Hunk) => ReactNode;
    className?: string;
}) {
    const language = useLanguage(path ?? '', 'filename');
    const hunks = parseHunks(patch);

    return (
        <>
            <div
                className={cn(
                    'overflow-auto bg-muted/30 py-1 font-mono text-xs leading-5',
                    className,
                )}
                data-test="git-diff"
            >
                {hunks.length === 0 ? (
                    <span className="px-3 text-muted-foreground">
                        No text changes (e.g. only permissions changed).
                    </span>
                ) : (
                    <div className="min-w-max">
                        {hunks.map((hunk) => (
                            <HunkView
                                key={hunk.number}
                                hunk={hunk}
                                language={language}
                                hideWhitespace={hideWhitespace}
                                picking={picking}
                                actions={hunkActions?.(hunk)}
                            />
                        ))}
                    </div>
                )}
            </div>
            {truncated && (
                <p className="px-3 py-1.5 text-xs text-muted-foreground">
                    This diff is too large to show in full.
                </p>
            )}
        </>
    );
}

function HunkView({
    hunk,
    language,
    hideWhitespace,
    picking,
    actions,
}: {
    hunk: Hunk;
    language: Language | null;
    hideWhitespace: boolean;
    picking: PatchPicking | null;
    actions: ReactNode;
}) {
    const rows = rowsOf(hunk).filter(
        (row) =>
            !hideWhitespace ||
            !row.spacingOnly ||
            // Of a pair that only changed spacing, show the new line (as unchanged).
            row.line.kind === '+',
    );

    return (
        <div data-test="git-actions-hunk">
            <div className="sticky top-0 z-10 flex items-center gap-2 bg-background/95 px-3 py-1 text-sky-600 dark:text-sky-400">
                <span className="min-w-0 flex-1 whitespace-pre">
                    {hunk.header}
                </span>
                {actions}
            </div>
            {rows.map((row) => (
                <RowView
                    key={row.line.index}
                    row={row}
                    language={language}
                    asUnchanged={hideWhitespace && row.spacingOnly}
                    picking={picking}
                />
            ))}
        </div>
    );
}

function RowView({
    row,
    language,
    asUnchanged,
    picking,
}: {
    row: Row;
    language: Language | null;
    asUnchanged: boolean;
    picking: PatchPicking | null;
}) {
    const { line } = row;
    const kind = asUnchanged ? ' ' : line.kind;
    // A line that only changed in spacing reads as unchanged while whitespace is hidden.
    const isChange = !asUnchanged && (line.kind === '+' || line.kind === '-');
    const pickable = !!picking && isChange;
    const picked = pickable && picking.isPicked(line.index);
    const content = line.text.slice(1);

    if (line.kind === '\\') {
        return (
            <div className="flex text-muted-foreground italic">
                <Gutter />
                <span className="px-2">{line.text.slice(2)}</span>
            </div>
        );
    }

    return (
        <div
            role={pickable ? 'checkbox' : undefined}
            aria-checked={pickable ? picked : undefined}
            title={
                pickable
                    ? picked
                        ? 'Picked: click to unpick this line'
                        : 'Click to pick this line'
                    : undefined
            }
            onClick={() => pickable && picking.toggleLine(line.index)}
            className={cn(
                'flex',
                pickable && 'cursor-pointer hover:brightness-125',
                kind === '+'
                    ? 'bg-green-500/10'
                    : kind === '-'
                      ? 'bg-red-500/10'
                      : '',
                picked && 'bg-primary/15 shadow-[inset_3px_0_0] shadow-primary',
            )}
            data-test={isChange ? 'git-actions-line' : undefined}
        >
            <Gutter
                oldNumber={asUnchanged ? null : row.oldNumber}
                newNumber={row.newNumber}
            />
            <span
                className={cn(
                    'pr-3 whitespace-pre',
                    kind === '+'
                        ? 'text-green-700 dark:text-green-400'
                        : kind === '-'
                          ? 'text-red-700 dark:text-red-400'
                          : 'text-muted-foreground',
                )}
            >
                <span className="inline-block w-4 pl-1 select-none">
                    {asUnchanged ? ' ' : line.kind}
                </span>
                <Code
                    text={content}
                    language={language}
                    changed={asUnchanged ? null : row.changed}
                    kind={kind}
                />
            </span>
        </div>
    );
}

function Gutter({
    oldNumber = null,
    newNumber = null,
}: {
    oldNumber?: number | null;
    newNumber?: number | null;
}) {
    return (
        <span className="flex shrink-0 text-right text-muted-foreground/60 select-none">
            <span className="w-10 pr-2">{oldNumber ?? ''}</span>
            <span className="w-10 pr-2">{newNumber ?? ''}</span>
        </span>
    );
}

/** A line's code, colored by its language, with the part that changed marked. */
function Code({
    text,
    language,
    changed,
    kind,
}: {
    text: string;
    language: Language | null;
    changed: [number, number] | null;
    kind: string;
}) {
    const colored = (part: string) =>
        language && part !== '' ? highlight(part, language) : part;

    if (!changed) {
        return <>{colored(text) || ' '}</>;
    }

    const [start, end] = changed;

    return (
        <>
            {colored(text.slice(0, start))}
            <mark
                className={cn(
                    'rounded-sm text-inherit',
                    kind === '+' ? 'bg-green-500/30' : 'bg-red-500/30',
                )}
                data-test="git-diff-word"
            >
                {colored(text.slice(start, end))}
            </mark>
            {colored(text.slice(end))}
        </>
    );
}

/**
 * A hunk's lines with their old and new line numbers, pairing each run of removed lines with the added lines after
 * it to find what changed within them.
 */
function rowsOf(hunk: Hunk): Row[] {
    const match = /^@@ -(\d+)(?:,\d+)? \+(\d+)/.exec(hunk.header);
    let oldNumber = match ? Number(match[1]) : 0;
    let newNumber = match ? Number(match[2]) : 0;
    const rows: Row[] = hunk.lines.map((line) => {
        const row: Row = {
            line,
            oldNumber:
                line.kind === '+' || line.kind === '\\' ? null : oldNumber,
            newNumber:
                line.kind === '-' || line.kind === '\\' ? null : newNumber,
            changed: null,
            spacingOnly: false,
        };

        if (line.kind !== '+' && line.kind !== '\\') {
            oldNumber++;
        }

        if (line.kind !== '-' && line.kind !== '\\') {
            newNumber++;
        }

        return row;
    });

    for (let i = 0; i < rows.length;) {
        if (rows[i].line.kind !== '-') {
            i++;
            continue;
        }

        const removed: Row[] = [];
        const added: Row[] = [];

        while (rows[i]?.line.kind === '-' || rows[i]?.line.kind === '\\') {
            if (rows[i].line.kind === '-') {
                removed.push(rows[i]);
            }

            i++;
        }

        while (rows[i]?.line.kind === '+' || rows[i]?.line.kind === '\\') {
            if (rows[i].line.kind === '+') {
                added.push(rows[i]);
            }

            i++;
        }

        removed.forEach((before, pair) => {
            const after = added[pair];

            if (!after) {
                return;
            }

            const [oldText, newText] = [
                before.line.text.slice(1),
                after.line.text.slice(1),
            ];

            if (oldText.replace(/\s+/g, '') === newText.replace(/\s+/g, '')) {
                before.spacingOnly = after.spacingOnly = true;
            }

            const ranges = changedRanges(oldText, newText);

            if (ranges) {
                [before.changed, after.changed] = ranges;
            }
        });
    }

    // Blank lines added or removed on their own are spacing too.
    rows.forEach((row) => {
        if (
            (row.line.kind === '+' || row.line.kind === '-') &&
            row.line.text.slice(1).trim() === '' &&
            !row.spacingOnly
        ) {
            row.spacingOnly = true;
        }
    });

    return rows;
}

/**
 * Where two versions of a line differ: the span between their common start and end, by whole words. Null when
 * they share nothing (the whole line changed) or nothing changed.
 */
function changedRanges(
    before: string,
    after: string,
): [[number, number], [number, number]] | null {
    const tokens = (text: string) => text.match(/\w+|\s+|[^\w\s]/g) ?? [];
    const a = tokens(before);
    const b = tokens(after);
    let prefix = 0;

    while (prefix < a.length && prefix < b.length && a[prefix] === b[prefix]) {
        prefix++;
    }

    let suffix = 0;

    while (
        suffix < a.length - prefix &&
        suffix < b.length - prefix &&
        a[a.length - 1 - suffix] === b[b.length - 1 - suffix]
    ) {
        suffix++;
    }

    if (prefix === 0 && suffix === 0) {
        return null;
    }

    const length = (parts: string[]) => parts.join('').length;
    const start = length(a.slice(0, prefix));
    const range = (parts: string[]): [number, number] => [
        start,
        length(parts) - length(parts.slice(parts.length - suffix)),
    ];
    const [oldRange, newRange] = [range(a), range(b)];

    if (oldRange[0] === oldRange[1] && newRange[0] === newRange[1]) {
        return null;
    }

    return [oldRange, newRange];
}
