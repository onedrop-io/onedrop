import { GitBranch, GitBranchPlus, Undo2, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent, KeyboardEvent } from 'react';
import { toast } from 'sonner';
import ProjectGitController from '@/actions/App/Http/Controllers/ProjectGitController';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { DEFAULT_BRANCHES } from '@/components/workspace/git-state';
import type { Change, GitState } from '@/components/workspace/git-state';
import PatchView from '@/components/workspace/patch-view';
import { jsonRequest } from '@/lib/json-request';
import { cn } from '@/lib/utils';

const STATUS_STYLES: Record<string, { label: string; className: string }> = {
    M: { label: 'Modified', className: 'text-amber-500' },
    A: { label: 'Added', className: 'text-emerald-500' },
    '?': { label: 'New', className: 'text-emerald-500' },
    D: { label: 'Deleted', className: 'text-red-500' },
    R: { label: 'Renamed', className: 'text-sky-500' },
    U: { label: 'Conflicted', className: 'text-red-500' },
};

/**
 * Review the changes and commit them (GIT-006): which files, on this branch or a new one, and a message
 * (left blank, the project's AI writes one).
 */
export default function CommitDialog({
    projectId,
    branch,
    changes,
    moreChanges,
    push,
    working,
    onCancel,
    onCommitted,
    onStatusChanged,
}: {
    projectId: number;
    branch: string | null;
    changes: Change[];
    moreChanges: boolean;
    /** Push the branch after committing. */
    push: boolean;
    working: boolean;
    onCancel: () => void;
    onCommitted: (changed: Pick<GitState, 'status'>) => void;
    /** The changes moved without a commit (a hunk was discarded). */
    onStatusChanged: (status: GitState['status']) => void;
}) {
    const [message, setMessage] = useState('');
    const [choosing, setChoosing] = useState(false);
    const [excluded, setExcluded] = useState<Set<string>>(() => new Set());
    // The new branch's name while committing on a new branch, or null for the current one.
    const [newBranch, setNewBranch] = useState<string | null>(null);
    const [committing, setCommitting] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const messageField = useRef<HTMLTextAreaElement>(null);
    const branchField = useRef<HTMLInputElement>(null);
    // The file whose diff is open beside the list (the changes explorer), or null for the compact dialog.
    const [openPath, setOpenPath] = useState<string | null>(null);
    const rows = useRef(new Map<string, HTMLButtonElement>());
    // Diffs already loaded, so moving between files doesn't fetch them again.
    const [diffs, setDiffs] = useState<Record<string, ChangeDiff>>({});
    // Lines left out of files committed in part (GIT-009): their indexes in each file's patch.
    const [lineExclusions, setLineExclusions] = useState<
        Record<string, Set<number>>
    >({});
    const openChange = changes.find((change) => change.path === openPath);

    /** How much of a file goes in: all of it, none, or some of its lines. */
    const inclusion = (change: Change): 'all' | 'none' | 'some' => {
        const left = lineExclusions[change.path];

        if (excluded.has(change.path)) {
            return 'none';
        }

        if (!left || left.size === 0) {
            return 'all';
        }

        const lines = changeLines(diffs[change.path]?.patch ?? '');

        return lines.every((line) => left.has(line.index)) ? 'none' : 'some';
    };
    const chosen = changes.filter((change) => inclusion(change) !== 'none');
    /** A file's lines added and removed, counting only the chosen lines of a file committed in part. */
    const countsOf = (change: Change) => {
        const left = lineExclusions[change.path];

        if (!left || left.size === 0 || !diffs[change.path]) {
            return change;
        }

        const lines = changeLines(diffs[change.path].patch).filter(
            (line) => !left.has(line.index),
        );

        return {
            ...change,
            additions: lines.filter((line) => line.kind === '+').length,
            deletions: lines.filter((line) => line.kind === '-').length,
        };
    };
    const counted = (key: 'additions' | 'deletions') =>
        chosen.reduce(
            (total, change) => total + (countsOf(change)[key] ?? 0),
            0,
        );
    const partlyChosen = Object.values(lineExclusions).some(
        (left) => left.size > 0,
    );
    const hasCounts = changes.some((change) => change.additions != null);
    const onDefault = !!branch && DEFAULT_BRANCHES.includes(branch);
    const namingBranch = newBranch !== null;
    const blankBranch = namingBranch && newBranch.trim() === '';

    // Revealed by "Commit on new branch": type its name straight away.
    useEffect(() => {
        if (namingBranch) {
            branchField.current?.focus();
        }
    }, [namingBranch]);

    /** Tick or untick a whole file (a file in part is unticked). */
    const toggle = (path: string) => {
        const change = changes.find((other) => other.path === path);
        const leaveOut = !!change && inclusion(change) !== 'none';

        setExcluded((current) => {
            const next = new Set(current);

            if (leaveOut) {
                next.add(path);
            } else {
                next.delete(path);
            }

            return next;
        });
        setLineExclusions((current) => omitKey(current, path));
    };

    /** Change which lines of a file are left out; a file that was unticked starts with all of them left out. */
    const changeLineExclusions = (
        path: string,
        update: (left: Set<number>) => void,
    ) => {
        const wasExcluded = excluded.has(path);
        const next = new Set(
            wasExcluded
                ? changeLines(diffs[path]?.patch ?? '').map(
                      (line) => line.index,
                  )
                : lineExclusions[path],
        );

        update(next);
        setLineExclusions((current) => ({ ...current, [path]: next }));
        setChoosing(true);

        if (wasExcluded) {
            setExcluded((current) => {
                const without = new Set(current);
                without.delete(path);

                return without;
            });
        }
    };

    const loaded = (path: string, diff: ChangeDiff) =>
        setDiffs((current) => ({ ...current, [path]: diff }));

    /** A hunk was discarded: forget the file's old diff and choices, and take the new list of changes. */
    const discarded = (path: string, status: GitState['status']) => {
        setDiffs((current) => omitKey(current, path));
        setLineExclusions((current) => omitKey(current, path));
        onStatusChanged(status);

        if (!status.changes.some((change) => change.path === path)) {
            setOpenPath(null);
        }
    };

    const submit = (event?: FormEvent) => {
        event?.preventDefault();

        if (chosen.length === 0 || blankBranch || committing) {
            return;
        }

        setCommitting(true);
        setError(null);
        jsonRequest<Pick<GitState, 'status'> & { message: string }>(
            ProjectGitController.commit.url(projectId),
            {
                message: message.trim() || null,
                // Only the chosen files when some are left out ("more" changes beyond the list are left out too).
                paths:
                    excluded.size > 0 || partlyChosen
                        ? chosen
                              .filter((change) => inclusion(change) === 'all')
                              .map((change) => change.path)
                        : null,
                partials: chosen
                    .filter((change) => inclusion(change) === 'some')
                    .map((change) => ({
                        path: change.path,
                        hash: diffs[change.path].hash,
                        excluded: [...lineExclusions[change.path]],
                    })),
                branch: newBranch?.trim() || null,
            },
        )
            .then((changed) => {
                toast.success(`Committed “${changed.message}”`);
                onCommitted(changed);
            })
            .catch((e: Error) => setError(e.message))
            .finally(() => setCommitting(false));
    };

    const action = push ? 'Commit & push' : 'Commit';

    /** ↑/↓ move between files (showing each one's diff when the explorer is open); Space ticks one while editing. */
    const onRowKeyDown = (event: KeyboardEvent, index: number) => {
        const step =
            event.key === 'ArrowDown' ? 1 : event.key === 'ArrowUp' ? -1 : 0;

        if (step !== 0) {
            event.preventDefault();
            const next = changes[index + step];

            if (next) {
                rows.current.get(next.path)?.focus();

                if (openPath !== null) {
                    setOpenPath(next.path);
                }
            }
        } else if (event.key === ' ' && choosing) {
            event.preventDefault();
            toggle(changes[index].path);
        }
    };

    return (
        <DialogContent
            className={cn(
                'transition-[max-width]',
                openChange ? 'sm:max-w-6xl' : 'sm:max-w-xl',
            )}
            onOpenAutoFocus={(event) => {
                event.preventDefault();
                messageField.current?.focus();
            }}
            // Esc closes the open diff first, then the dialog.
            onEscapeKeyDown={(event) => {
                if (openPath !== null) {
                    event.preventDefault();
                    rows.current.get(openPath)?.focus();
                    setOpenPath(null);
                }
            }}
            data-test="git-actions-dialog"
        >
            <form onSubmit={submit} className="grid min-w-0 gap-5">
                <DialogHeader>
                    <DialogTitle>Commit changes</DialogTitle>
                    <DialogDescription>
                        Review what goes into the commit
                        {push ? " before it's pushed" : ''}. Leave the message
                        blank and AI writes one.
                    </DialogDescription>
                </DialogHeader>

                <div
                    className={cn(
                        'grid min-w-0 gap-4',
                        openChange &&
                            'md:grid-cols-[minmax(0,24rem)_minmax(0,1fr)]',
                    )}
                >
                    <div className="min-w-0 space-y-3 rounded-xl border bg-muted/30 p-4">
                        <div className="flex h-8 items-center gap-3 text-sm">
                            <span className="text-muted-foreground">
                                Branch
                            </span>
                            {newBranch === null ? (
                                <>
                                    <span
                                        className="flex min-w-0 items-center gap-1.5 font-medium"
                                        data-test="git-actions-branch"
                                    >
                                        <GitBranch className="size-3.5 shrink-0 text-muted-foreground" />
                                        <span className="truncate">
                                            {branch ?? 'Detached'}
                                        </span>
                                    </span>
                                    <span className="flex-1" />
                                    {onDefault && (
                                        <span
                                            className="text-xs text-amber-500"
                                            data-test="git-actions-default-branch"
                                        >
                                            Default branch
                                        </span>
                                    )}
                                </>
                            ) : (
                                <>
                                    <Input
                                        ref={branchField}
                                        value={newBranch}
                                        onChange={(event) =>
                                            setNewBranch(event.target.value)
                                        }
                                        placeholder="new-branch-name"
                                        className="h-8 flex-1 font-mono"
                                        data-test="git-actions-new-branch"
                                    />
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        onClick={() => setNewBranch(null)}
                                    >
                                        Keep {branch ?? 'branch'}
                                    </Button>
                                </>
                            )}
                        </div>

                        <div className="flex items-center text-sm">
                            <span className="flex-1 text-muted-foreground">
                                Files{' '}
                                <span data-test="git-actions-file-count">
                                    {excluded.size > 0 || partlyChosen
                                        ? `${chosen.length} of ${changes.length}`
                                        : `${changes.length}${moreChanges ? '+' : ''}`}
                                </span>
                            </span>
                            {/* Sandboxes whose git tool predates choosing files report no line counts, and would commit them all. */}
                            {hasCounts && (
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    className="h-7"
                                    onClick={() => setChoosing((open) => !open)}
                                    data-test="git-actions-edit-files"
                                >
                                    {choosing ? 'Done' : 'Edit'}
                                </Button>
                            )}
                        </div>

                        <ul
                            className={cn(
                                'divide-y divide-border/50 overflow-y-auto rounded-lg border bg-background/60',
                                openChange ? 'max-h-[26rem]' : 'max-h-60',
                            )}
                            data-test="git-actions-files"
                        >
                            {changes.map((change, index) => {
                                const style =
                                    STATUS_STYLES[change.status] ??
                                    STATUS_STYLES.M;
                                const slash = change.path
                                    .replace(/\/$/, '')
                                    .lastIndexOf('/');
                                const included = inclusion(change);
                                const open = change.path === openPath;

                                return (
                                    <li
                                        key={change.path}
                                        className={cn(
                                            'flex items-center font-mono text-xs',
                                            open && 'bg-muted',
                                            included === 'none' && 'opacity-50',
                                        )}
                                        data-test="git-actions-file"
                                    >
                                        {choosing && (
                                            <Checkbox
                                                checked={
                                                    included === 'some'
                                                        ? 'indeterminate'
                                                        : included === 'all'
                                                }
                                                onCheckedChange={() =>
                                                    toggle(change.path)
                                                }
                                                className="ml-3"
                                                aria-label={`Include ${change.path}`}
                                                data-test="git-actions-file-toggle"
                                            />
                                        )}
                                        <button
                                            type="button"
                                            ref={(row) => {
                                                if (row) {
                                                    rows.current.set(
                                                        change.path,
                                                        row,
                                                    );
                                                } else {
                                                    rows.current.delete(
                                                        change.path,
                                                    );
                                                }
                                            }}
                                            onClick={() =>
                                                setOpenPath(
                                                    open ? null : change.path,
                                                )
                                            }
                                            onKeyDown={(event) =>
                                                onRowKeyDown(event, index)
                                            }
                                            aria-pressed={open}
                                            title={`Show the changes to ${change.path}`}
                                            className="flex min-w-0 flex-1 items-center gap-2 px-3 py-1.5 text-left outline-none hover:bg-muted/50 focus-visible:bg-muted/50"
                                            data-test="git-actions-file-open"
                                        >
                                            <span
                                                className={cn(
                                                    'w-3 shrink-0 text-center font-semibold',
                                                    style.className,
                                                )}
                                                title={style.label}
                                            >
                                                {change.status === '?'
                                                    ? 'U'
                                                    : change.status === 'U'
                                                      ? '!'
                                                      : change.status}
                                            </span>
                                            <span className="flex min-w-0 flex-1">
                                                <span className="truncate text-muted-foreground">
                                                    {change.path.slice(
                                                        0,
                                                        slash + 1,
                                                    )}
                                                </span>
                                                <span className="shrink-0">
                                                    {change.path.slice(
                                                        slash + 1,
                                                    )}
                                                </span>
                                            </span>
                                            {included === 'some' && (
                                                <span
                                                    className="shrink-0 text-muted-foreground"
                                                    data-test="git-actions-file-partly"
                                                >
                                                    part
                                                </span>
                                            )}
                                            <LineCounts
                                                change={countsOf(change)}
                                            />
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>

                        {hasCounts && (
                            <p
                                className="text-right font-mono text-xs"
                                data-test="git-actions-totals"
                            >
                                <span className="text-emerald-500">
                                    +{counted('additions')}
                                </span>{' '}
                                <span className="text-red-500">
                                    −{counted('deletions')}
                                </span>
                            </p>
                        )}
                    </div>

                    {openChange && (
                        <ChangeDiffPane
                            key={openChange.path}
                            projectId={projectId}
                            change={openChange}
                            diff={diffs[openChange.path] ?? null}
                            onLoaded={(diff) => loaded(openChange.path, diff)}
                            // Parts can be picked once the sandbox's git tool can commit them (it hashes each diff).
                            leftOut={
                                excluded.has(openChange.path)
                                    ? 'all'
                                    : (lineExclusions[openChange.path] ?? null)
                            }
                            onLeaveOut={(update) =>
                                changeLineExclusions(openChange.path, update)
                            }
                            working={working}
                            onDiscarded={(status) =>
                                discarded(openChange.path, status)
                            }
                            onClose={() => {
                                rows.current.get(openChange.path)?.focus();
                                setOpenPath(null);
                            }}
                        />
                    )}
                </div>

                <div className="space-y-2">
                    <label
                        htmlFor="git-actions-message"
                        className="text-sm font-medium"
                    >
                        Commit message{' '}
                        <span className="font-normal text-muted-foreground">
                            (optional)
                        </span>
                    </label>
                    <textarea
                        id="git-actions-message"
                        ref={messageField}
                        rows={3}
                        value={message}
                        onChange={(event) => setMessage(event.target.value)}
                        onKeyDown={(event) => {
                            if (
                                event.key === 'Enter' &&
                                (event.metaKey || event.ctrlKey)
                            ) {
                                submit();
                            }
                        }}
                        placeholder="Leave blank to have AI write it"
                        className="w-full resize-y rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        data-test="git-actions-message"
                    />
                </div>

                {error && (
                    <p
                        className="text-sm text-red-600"
                        data-test="git-actions-error"
                    >
                        {error}
                    </p>
                )}
                {working && (
                    <p className="text-sm text-muted-foreground">
                        The agent is working. Commit once it finishes.
                    </p>
                )}

                <DialogFooter className="gap-2">
                    <Button type="button" variant="ghost" onClick={onCancel}>
                        Cancel
                    </Button>
                    {newBranch === null && (
                        <Button
                            type="button"
                            variant="outline"
                            disabled={committing}
                            onClick={() => setNewBranch('')}
                            data-test="git-actions-on-new-branch"
                        >
                            <GitBranchPlus className="size-4" />
                            Commit on new branch
                        </Button>
                    )}
                    <Button
                        type="submit"
                        disabled={
                            chosen.length === 0 ||
                            blankBranch ||
                            committing ||
                            working
                        }
                        data-test="git-actions-submit"
                    >
                        {committing
                            ? message.trim() === ''
                                ? 'Writing the message…'
                                : 'Committing…'
                            : newBranch !== null
                              ? `${action} to new branch`
                              : action}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    );
}

function LineCounts({ change }: { change: Change }) {
    if (change.binary) {
        return <span className="shrink-0 text-muted-foreground">binary</span>;
    }

    if (change.additions == null) {
        return null;
    }

    return (
        <span className="shrink-0 tabular-nums">
            <span className="text-emerald-500">+{change.additions}</span>{' '}
            <span className="text-red-500">−{change.deletions ?? 0}</span>
        </span>
    );
}

type ChangeDiff = {
    patch: string;
    /** The whole patch's hash, so committing or discarding part of it can check it's what was shown. */
    hash: string | null;
    truncated: boolean;
    binary: boolean;
    /** A new folder's files, instead of a patch. */
    files: string[] | null;
};

type PatchLine = { index: number; text: string; kind: string };
type Hunk = { number: number; header: string; lines: PatchLine[] };

/** A patch's hunks, each line with its index in the whole patch (what the sandbox's git tool counts). */
function parseHunks(patch: string): Hunk[] {
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
function changeLines(patch: string): PatchLine[] {
    return parseHunks(patch).flatMap((hunk) =>
        hunk.lines.filter((line) => line.kind === '+' || line.kind === '-'),
    );
}

/** Files whose lines can be picked: edited or new text files (not deleted, renamed or binary ones). */
const PARTIAL_STATUSES = ['M', 'A', '?'];

/**
 * The open file's changes since the last commit, beside the list of files (GIT-006). Hunks and single lines can be
 * left out of the commit, and a hunk of an edited file discarded (GIT-009).
 */
function ChangeDiffPane({
    projectId,
    change,
    diff,
    onLoaded,
    leftOut,
    onLeaveOut,
    working,
    onDiscarded,
    onClose,
}: {
    projectId: number;
    change: Change;
    diff: ChangeDiff | null;
    onLoaded: (diff: ChangeDiff) => void;
    /** The lines left out of the commit: "all" for an unticked file, or null for none. */
    leftOut: Set<number> | 'all' | null;
    onLeaveOut: (update: (left: Set<number>) => void) => void;
    working: boolean;
    onDiscarded: (status: GitState['status']) => void;
    onClose: () => void;
}) {
    const [error, setError] = useState<string | null>(null);
    // The hunk waiting for "Discard" to be confirmed, and the one being discarded.
    const [confirming, setConfirming] = useState<number | null>(null);
    const [discarding, setDiscarding] = useState(false);

    // The latest callback, so a new one each render doesn't fetch the diff again.
    const onLoadedRef = useRef(onLoaded);
    onLoadedRef.current = onLoaded;

    useEffect(() => {
        if (diff) {
            return;
        }

        let cancelled = false;

        jsonRequest<ChangeDiff>(
            ProjectGitController.changeDiff.url(projectId, {
                query: { path: change.path },
            }),
        )
            .then((loadedDiff) => !cancelled && onLoadedRef.current(loadedDiff))
            .catch((e: Error) => !cancelled && setError(e.message));

        return () => {
            cancelled = true;
        };
    }, [projectId, change.path, diff]);

    const style = STATUS_STYLES[change.status] ?? STATUS_STYLES.M;
    const pickable =
        !!diff?.hash &&
        !diff.truncated &&
        PARTIAL_STATUSES.includes(change.status);
    const discardable =
        !!diff?.hash && !diff.truncated && change.status === 'M' && !working;
    const isLeftOut = (index: number) =>
        leftOut === 'all' || (leftOut?.has(index) ?? false);

    const discard = (hunk: number) => {
        setDiscarding(true);
        setError(null);
        jsonRequest<Pick<GitState, 'status'>>(
            ProjectGitController.discardHunk.url(projectId),
            { path: change.path, hash: diff?.hash, hunk },
        )
            .then((changed) => {
                toast.success('Discarded that part');
                onDiscarded(changed.status);
            })
            .catch((e: Error) => setError(e.message))
            .finally(() => {
                setDiscarding(false);
                setConfirming(null);
            });
    };

    return (
        <section
            className="flex h-[32rem] min-w-0 flex-col overflow-hidden rounded-xl border"
            aria-label={`Changes to ${change.path}`}
            data-test="git-actions-diff"
        >
            <header className="flex items-center gap-2 border-b px-3 py-2 font-mono text-xs">
                <span className={cn('font-semibold', style.className)}>
                    {style.label}
                </span>
                <span
                    className="min-w-0 flex-1 truncate"
                    title={change.path}
                    data-test="git-actions-diff-path"
                >
                    {change.path}
                </span>
                <LineCounts change={change} />
                <Button
                    type="button"
                    size="icon"
                    variant="ghost"
                    className="size-7"
                    aria-label="Close the diff"
                    onClick={onClose}
                    data-test="git-actions-diff-close"
                >
                    <X className="size-4" />
                </Button>
            </header>
            {error && (
                <p
                    className="border-b px-3 py-2 text-xs text-red-600"
                    data-test="git-actions-diff-error"
                >
                    {error}
                </p>
            )}
            <div className="min-h-0 flex-1 overflow-auto">
                {!diff ? (
                    !error && (
                        <p className="p-3 text-xs text-muted-foreground">
                            Loading…
                        </p>
                    )
                ) : diff.binary ? (
                    <p className="p-3 text-xs text-muted-foreground">
                        Binary file: there are no lines to show.
                    </p>
                ) : diff.files ? (
                    <div className="p-3 text-xs">
                        <p className="mb-2 text-muted-foreground">
                            New folder with {diff.files.length}
                            {diff.truncated ? '+' : ''}{' '}
                            {diff.files.length === 1 ? 'file' : 'files'}:
                        </p>
                        <ul className="space-y-0.5 font-mono">
                            {diff.files.map((file) => (
                                <li key={file} className="truncate">
                                    {file}
                                </li>
                            ))}
                        </ul>
                    </div>
                ) : !pickable && !discardable ? (
                    <PatchView
                        patch={diff.patch}
                        truncated={diff.truncated}
                        className="min-h-full overflow-visible bg-transparent"
                    />
                ) : (
                    <div
                        className="min-h-full bg-muted/30 py-1 font-mono text-xs leading-5"
                        data-test="git-diff"
                    >
                        {parseHunks(diff.patch).map((hunk) => {
                            const changed = hunk.lines.filter(
                                (line) =>
                                    line.kind === '+' || line.kind === '-',
                            );
                            const left = changed.filter((line) =>
                                isLeftOut(line.index),
                            ).length;

                            return (
                                <div
                                    key={hunk.number}
                                    data-test="git-actions-hunk"
                                >
                                    <div className="sticky top-0 z-10 flex items-center gap-2 bg-background/95 px-3 py-1 text-sky-600 dark:text-sky-400">
                                        {pickable && (
                                            <Checkbox
                                                checked={
                                                    left === 0
                                                        ? true
                                                        : left ===
                                                            changed.length
                                                          ? false
                                                          : 'indeterminate'
                                                }
                                                onCheckedChange={() =>
                                                    onLeaveOut((set) => {
                                                        changed.forEach(
                                                            (line) =>
                                                                left === 0
                                                                    ? set.add(
                                                                          line.index,
                                                                      )
                                                                    : set.delete(
                                                                          line.index,
                                                                      ),
                                                        );
                                                    })
                                                }
                                                aria-label="Include this part"
                                                data-test="git-actions-hunk-toggle"
                                            />
                                        )}
                                        <span className="min-w-0 flex-1 truncate whitespace-pre">
                                            {hunk.header}
                                        </span>
                                        {discardable &&
                                            (confirming === hunk.number ? (
                                                <>
                                                    <span className="text-muted-foreground">
                                                        Discard this part?
                                                    </span>
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant="destructive"
                                                        className="h-6 px-2 text-xs"
                                                        disabled={discarding}
                                                        onClick={() =>
                                                            discard(hunk.number)
                                                        }
                                                        data-test="git-actions-hunk-discard-confirm"
                                                    >
                                                        Discard
                                                    </Button>
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant="ghost"
                                                        className="h-6 px-2 text-xs"
                                                        onClick={() =>
                                                            setConfirming(null)
                                                        }
                                                    >
                                                        Keep
                                                    </Button>
                                                </>
                                            ) : (
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="ghost"
                                                    className="h-6 px-2 text-xs text-muted-foreground"
                                                    onClick={() =>
                                                        setConfirming(
                                                            hunk.number,
                                                        )
                                                    }
                                                    title="Put this part back as it was in the last commit"
                                                    data-test="git-actions-hunk-discard"
                                                >
                                                    <Undo2 className="size-3.5" />
                                                    Discard
                                                </Button>
                                            ))}
                                    </div>
                                    {hunk.lines.map((line) => {
                                        const isChange =
                                            line.kind === '+' ||
                                            line.kind === '-';
                                        const out =
                                            isChange && isLeftOut(line.index);

                                        return (
                                            <div
                                                key={line.index}
                                                role={
                                                    pickable && isChange
                                                        ? 'checkbox'
                                                        : undefined
                                                }
                                                aria-checked={
                                                    pickable && isChange
                                                        ? !out
                                                        : undefined
                                                }
                                                title={
                                                    pickable && isChange
                                                        ? out
                                                            ? 'Left out: click to include this line'
                                                            : 'Click to leave this line out'
                                                        : undefined
                                                }
                                                onClick={() =>
                                                    pickable &&
                                                    isChange &&
                                                    onLeaveOut((set) => {
                                                        if (
                                                            !set.delete(
                                                                line.index,
                                                            )
                                                        ) {
                                                            set.add(line.index);
                                                        }
                                                    })
                                                }
                                                className={cn(
                                                    'px-3 whitespace-pre',
                                                    pickable &&
                                                        isChange &&
                                                        'cursor-pointer hover:brightness-125',
                                                    line.kind === '+'
                                                        ? 'bg-green-500/10 text-green-700 dark:text-green-400'
                                                        : line.kind === '-'
                                                          ? 'bg-red-500/10 text-red-700 dark:text-red-400'
                                                          : 'text-muted-foreground',
                                                    out &&
                                                        'bg-transparent line-through opacity-40',
                                                )}
                                                data-test={
                                                    isChange
                                                        ? 'git-actions-line'
                                                        : undefined
                                                }
                                            >
                                                {line.text || ' '}
                                            </div>
                                        );
                                    })}
                                </div>
                            );
                        })}
                    </div>
                )}
            </div>
            {pickable && (
                <p className="border-t px-3 py-1.5 text-xs text-muted-foreground">
                    Click lines or untick parts to leave them out of this
                    commit.
                </p>
            )}
        </section>
    );
}

/** A copy of the record without one key. */
function omitKey<T>(record: Record<string, T>, key: string): Record<string, T> {
    const copy = { ...record };
    delete copy[key];

    return copy;
}
