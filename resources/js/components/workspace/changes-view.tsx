import {
    ChevronDown,
    ChevronRight,
    MessageSquare,
    RefreshCw,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import ProjectGitController from '@/actions/App/Http/Controllers/ProjectGitController';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import FileIcon from '@/components/workspace/file-icon';
import { Empty } from '@/components/workspace/git-parts';
import PatchView from '@/components/workspace/patch-view';
import type { Hunk } from '@/components/workspace/patch-view';
import {
    askAbout,
    STATUS_STYLES,
} from '@/components/workspace/source-control-diff';
import { jsonRequest } from '@/lib/json-request';
import { cn } from '@/lib/utils';
import { askAgent } from '@/lib/workspace-view';

type ChangedFile = {
    path: string;
    status: 'A' | 'M' | 'D';
    additions: number | null;
    deletions: number | null;
    binary: boolean;
    /** Null when it's too big to come with the rest: Show loads it. */
    patch: string | null;
    truncated: boolean;
};

type TaskChanges = {
    /** What they're against, e.g. "Against main" or "Since it started from develop". */
    since: string;
    commits: number;
    additions: number;
    deletions: number;
    files: ChangedFile[];
    more_files: boolean;
};

type LoadedDiff = { patch: string; truncated: boolean; binary: boolean };

/** Lockfiles and built files: their diffs start folded, as on GitHub. */
const GENERATED =
    /(^|\/)(package-lock\.json|yarn\.lock|pnpm-lock\.yaml|bun\.lockb?|composer\.lock|Gemfile\.lock|Cargo\.lock|poetry\.lock|uv\.lock|go\.sum)$|\.(min\.(js|css)|map)$/;

/**
 * Everything a task changed, in one scroll (GIT-030): its commits and its uncommitted and new files together, against
 * where it started or its pull request's base, every file's diff open one after another (GIT-012), with a list of the
 * files to jump to. Lockfiles, binary files and very large diffs start folded.
 */
export default function ChangesView({
    projectId,
    running,
    refreshSignal,
    onOpenFile,
}: {
    projectId: number;
    running: boolean;
    /** Changes when the agent or the files did something: read the changes again. */
    refreshSignal: string;
    onOpenFile: (path: string) => void;
}) {
    const [changes, setChanges] = useState<TaskChanges | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const [hideWhitespace, setHideWhitespace] = useState(false);
    /** Files the user folded or opened, against how they start. */
    const [toggled, setToggled] = useState<Set<string>>(() => new Set());
    /** Diffs of files that came without one, loaded when opened. */
    const [loaded, setLoaded] = useState<Record<string, LoadedDiff | string>>(
        {},
    );

    const load = useCallback(() => {
        setLoading(true);

        return jsonRequest<TaskChanges>(
            ProjectGitController.taskChanges.url(projectId),
        )
            .then((next) => {
                setChanges(next);
                setLoaded({});
                setError(null);
            })
            .catch((e: Error) => setError(e.message))
            .finally(() => setLoading(false));
    }, [projectId]);

    useEffect(() => {
        if (running) {
            void load();
        }
    }, [running, load, refreshSignal]);

    const startsFolded = (file: ChangedFile) =>
        file.binary || file.patch === null || GENERATED.test(file.path);
    const isOpen = (file: ChangedFile) =>
        !startsFolded(file) !== toggled.has(file.path);

    const toggle = (file: ChangedFile) => {
        setToggled((current) => {
            const next = new Set(current);

            if (next.has(file.path)) {
                next.delete(file.path);
            } else {
                next.add(file.path);
            }

            return next;
        });

        if (file.patch === null && !file.binary && !loaded[file.path]) {
            jsonRequest<LoadedDiff>(
                ProjectGitController.taskChanges.url(projectId, {
                    query: { path: file.path },
                }),
            )
                .then((diff) =>
                    setLoaded((current) => ({ ...current, [file.path]: diff })),
                )
                .catch((e: Error) =>
                    setLoaded((current) => ({
                        ...current,
                        [file.path]: e.message,
                    })),
                );
        }
    };

    const jumpTo = (path: string) => {
        document
            .getElementById(fileAnchor(path))
            ?.scrollIntoView({ block: 'start' });
    };

    if (!running) {
        return (
            <div className="p-4">
                <Empty>The task's copy of the app isn't running.</Empty>
            </div>
        );
    }

    if (!changes) {
        return (
            <div className="p-4" data-test="changes-view">
                {error ? (
                    <Empty tone="error">{error}</Empty>
                ) : (
                    <p className="text-sm text-muted-foreground">Loading…</p>
                )}
            </div>
        );
    }

    const hunkActions = (path: string) => (hunk: Hunk) => (
        <Button
            type="button"
            size="sm"
            variant="ghost"
            className="h-6 px-2 text-xs text-muted-foreground"
            onClick={() => askAgent(askAbout(path, hunk, 'change'))}
            title="Ask the agent about this part"
            data-test="changes-hunk-ask"
        >
            <MessageSquare className="size-3.5" />
            Ask
        </Button>
    );

    return (
        <div
            className="@container/changes flex h-full min-h-0 min-w-0 flex-col"
            data-test="changes-view"
        >
            <header className="flex flex-wrap items-center gap-x-3 gap-y-1 border-b px-3 py-2 text-xs">
                <span
                    className="font-medium text-foreground"
                    data-test="changes-since"
                >
                    {changes.since}
                </span>
                <span
                    className="text-muted-foreground"
                    data-test="changes-summary"
                >
                    {changes.files.length}
                    {changes.more_files ? '+' : ''}{' '}
                    {changes.files.length === 1 ? 'file' : 'files'}
                    {changes.commits > 0 &&
                        ` · ${changes.commits} ${changes.commits === 1 ? 'commit' : 'commits'}`}
                </span>
                <span className="font-mono">
                    <span className="text-emerald-600 dark:text-emerald-400">
                        +{changes.additions}
                    </span>{' '}
                    <span className="text-red-600 dark:text-red-400">
                        −{changes.deletions}
                    </span>
                </span>
                <Button
                    type="button"
                    size="icon"
                    variant="ghost"
                    className="size-7"
                    aria-label="Refresh"
                    title="Refresh"
                    onClick={() => load()}
                    disabled={loading}
                    data-test="changes-refresh"
                >
                    <RefreshCw
                        className={cn('size-3.5', loading && 'animate-spin')}
                    />
                </Button>
                <span className="flex-1" />
                {changes.files.length > 1 && (
                    <select
                        className="h-7 max-w-48 rounded-md border bg-background px-1 text-xs @3xl/changes:hidden"
                        value=""
                        onChange={(event) => jumpTo(event.target.value)}
                        aria-label="Jump to a file"
                        data-test="changes-jump"
                    >
                        <option value="" disabled>
                            Jump to file…
                        </option>
                        {changes.files.map((file) => (
                            <option key={file.path} value={file.path}>
                                {file.path}
                            </option>
                        ))}
                    </select>
                )}
                <label className="flex items-center gap-1.5 text-muted-foreground">
                    <Checkbox
                        checked={hideWhitespace}
                        onCheckedChange={(checked) =>
                            setHideWhitespace(checked === true)
                        }
                        data-test="changes-hide-whitespace"
                    />
                    Hide whitespace
                </label>
            </header>
            {error && (
                <p className="border-b px-3 py-1.5 text-xs text-red-600 dark:text-red-400">
                    {error}
                </p>
            )}

            {changes.files.length === 0 ? (
                <div className="p-4">
                    <Empty>Nothing changed yet.</Empty>
                </div>
            ) : (
                <div className="flex min-h-0 flex-1">
                    <nav
                        aria-label="Changed files"
                        className="hidden w-60 shrink-0 overflow-y-auto border-r py-1 @3xl/changes:block"
                        data-test="changes-files"
                    >
                        {changes.files.map((file) => (
                            <button
                                key={file.path}
                                type="button"
                                onClick={() => jumpTo(file.path)}
                                title={file.path}
                                className="flex w-full items-center gap-1.5 px-3 py-1 text-left text-xs hover:bg-muted"
                                data-test="changes-file-link"
                            >
                                <FileIcon name={file.path.split('/').pop()!} />
                                <span className="min-w-0 flex-1 truncate">
                                    {file.path.split('/').pop()}
                                </span>
                                <Counts file={file} />
                            </button>
                        ))}
                        {changes.more_files && (
                            <p className="px-3 py-1 text-xs text-muted-foreground">
                                More files aren't listed.
                            </p>
                        )}
                    </nav>
                    <div
                        className="min-w-0 flex-1 overflow-y-auto"
                        data-test="changes-diffs"
                    >
                        {changes.files.map((file) => {
                            const open = isOpen(file);
                            const style =
                                STATUS_STYLES[file.status] ?? STATUS_STYLES.M;
                            const diff =
                                file.patch !== null
                                    ? {
                                          patch: file.patch,
                                          truncated: file.truncated,
                                          binary: false,
                                      }
                                    : loaded[file.path];

                            return (
                                <section
                                    key={file.path}
                                    id={fileAnchor(file.path)}
                                    className="border-b"
                                    aria-label={`Changes to ${file.path}`}
                                    data-test="changes-file"
                                >
                                    <div className="sticky top-0 z-10 flex items-center gap-2 border-b bg-background px-3 py-1.5 text-xs">
                                        <button
                                            type="button"
                                            onClick={() => toggle(file)}
                                            aria-expanded={open}
                                            aria-label={
                                                open
                                                    ? `Fold ${file.path}`
                                                    : `Show ${file.path}`
                                            }
                                            className="rounded p-0.5 text-muted-foreground hover:bg-muted"
                                            data-test="changes-file-toggle"
                                        >
                                            {open ? (
                                                <ChevronDown className="size-4" />
                                            ) : (
                                                <ChevronRight className="size-4" />
                                            )}
                                        </button>
                                        <span
                                            className={cn(
                                                'w-3 shrink-0 text-center font-semibold',
                                                style.className,
                                            )}
                                            title={style.label}
                                        >
                                            {style.letter}
                                        </span>
                                        <button
                                            type="button"
                                            className="min-w-0 flex-1 truncate text-left font-mono underline-offset-4 hover:underline"
                                            title={`Open ${file.path}`}
                                            onClick={() =>
                                                file.status !== 'D' &&
                                                onOpenFile(file.path)
                                            }
                                            data-test="changes-file-path"
                                        >
                                            {file.path}
                                        </button>
                                        <Counts file={file} />
                                    </div>
                                    {open && (
                                        <FileBody
                                            file={file}
                                            diff={diff}
                                            hideWhitespace={hideWhitespace}
                                            hunkActions={hunkActions(file.path)}
                                        />
                                    )}
                                </section>
                            );
                        })}
                        {changes.more_files && (
                            <p className="p-3 text-xs text-muted-foreground">
                                More files changed than are shown here.
                            </p>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}

function FileBody({
    file,
    diff,
    hideWhitespace,
    hunkActions,
}: {
    file: ChangedFile;
    diff: LoadedDiff | string | undefined;
    hideWhitespace: boolean;
    hunkActions: (hunk: Hunk) => ReactNode;
}) {
    if (file.binary || (typeof diff === 'object' && diff.binary)) {
        return (
            <p className="px-3 py-2 text-xs text-muted-foreground">
                Binary file, not shown.
            </p>
        );
    }

    if (typeof diff === 'string') {
        return (
            <p className="px-3 py-2 text-xs text-red-600 dark:text-red-400">
                {diff}
            </p>
        );
    }

    if (!diff) {
        return (
            <p className="px-3 py-2 text-xs text-muted-foreground">Loading…</p>
        );
    }

    if (!diff.patch.includes('@@')) {
        return (
            <p className="px-3 py-2 text-xs text-muted-foreground">
                {file.status === 'A'
                    ? 'Empty file.'
                    : 'No changes to its lines.'}
            </p>
        );
    }

    return (
        <PatchView
            patch={diff.patch}
            truncated={diff.truncated}
            path={file.path}
            hideWhitespace={hideWhitespace}
            hunkActions={hunkActions}
        />
    );
}

function Counts({ file }: { file: ChangedFile }) {
    if (file.additions === null) {
        return null;
    }

    return (
        <span className="shrink-0 font-mono text-[11px]">
            <span className="text-emerald-600 dark:text-emerald-400">
                +{file.additions}
            </span>{' '}
            <span className="text-red-600 dark:text-red-400">
                −{file.deletions}
            </span>
        </span>
    );
}

function fileAnchor(path: string): string {
    return `changes-${path.replace(/[^A-Za-z0-9_-]/g, '_')}`;
}
