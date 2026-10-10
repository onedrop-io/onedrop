import {
    ArrowLeft,
    FileText,
    MessageSquare,
    Minus,
    Plus,
    Undo2,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import ProjectGitController from '@/actions/App/Http/Controllers/ProjectGitController';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import type { Change, GitStatus } from '@/components/workspace/git-state';
import PatchView from '@/components/workspace/patch-view';
import type { Hunk } from '@/components/workspace/patch-view';
import { jsonRequest } from '@/lib/json-request';
import { cn } from '@/lib/utils';
import { askAgent } from '@/lib/workspace-view';

type ChangeDiff = {
    path: string;
    staged: boolean;
    patch: string;
    /** A hash of the whole patch: staging parts of it checks the file is still as shown. */
    hash: string | null;
    truncated: boolean;
    binary: boolean;
    /** A new folder's files, instead of a patch. */
    files: string[] | null;
};

export const STATUS_STYLES: Record<
    string,
    { label: string; letter: string; className: string }
> = {
    M: { label: 'Modified', letter: 'M', className: 'text-amber-500' },
    A: { label: 'Added', letter: 'A', className: 'text-emerald-500' },
    '?': { label: 'New', letter: 'U', className: 'text-emerald-500' },
    D: { label: 'Deleted', letter: 'D', className: 'text-red-500' },
    R: { label: 'Renamed', letter: 'R', className: 'text-sky-500' },
    U: { label: 'Conflicted', letter: '!', className: 'text-red-500' },
};

/** The chat message asking the agent about one hunk of a file (GIT-011), before the user's question. */
export function askAbout(path: string, hunk: Hunk): string {
    const lines = [hunk.header, ...hunk.lines.map((line) => line.text)]
        .join('\n')
        .replaceAll('```', "'''");
    const excerpt = lines.length > 4000 ? `${lines.slice(0, 4000)}\n…` : lines;

    return `About this uncommitted change to \`${path}\`:\n\n\`\`\`diff\n${excerpt}\n\`\`\`\n\n`;
}

/**
 * One file's staged or unstaged changes, beside Source Control's lists (SCM-001): stage, unstage or discard the whole
 * file, one hunk, or the lines picked (GIT-009), and send a hunk to the agent to ask about (GIT-011).
 */
export default function SourceControlDiff({
    projectId,
    change,
    staged,
    working,
    onStatus,
    onOpenFile,
    onStageFile,
    onDiscardFile,
    onClose,
}: {
    projectId: number;
    change: Change;
    /** Show what's staged of it, rather than what isn't. */
    staged: boolean;
    working: boolean;
    /** Staging or discarding part of it changed the repository. */
    onStatus: (status: GitStatus) => void;
    onOpenFile: (path: string) => void;
    /** Stage (or, when staged, unstage) the whole file. */
    onStageFile: () => void;
    onDiscardFile: () => void;
    onClose: () => void;
}) {
    const [diff, setDiff] = useState<ChangeDiff | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [hideWhitespace, setHideWhitespace] = useState(false);
    const [picked, setPicked] = useState<Set<number>>(() => new Set());
    // The hunk waiting for "Discard" to be confirmed.
    const [confirming, setConfirming] = useState<number | null>(null);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        let cancelled = false;
        setError(null);

        jsonRequest<ChangeDiff>(
            ProjectGitController.changeDiff.url(projectId, {
                query: { path: change.path, staged: staged ? 1 : 0 },
            }),
        )
            .then((loaded) => {
                if (!cancelled) {
                    setDiff(loaded);
                    setPicked(new Set());
                    setConfirming(null);
                }
            })
            .catch((e: Error) => !cancelled && setError(e.message));

        return () => {
            cancelled = true;
        };
        // Counts that moved mean the file changed: show it again.
    }, [projectId, change.path, staged, change.additions, change.deletions]);

    const style = STATUS_STYLES[change.status] ?? STATUS_STYLES.M;
    // Parts of a file go by the diff as the sandbox sees it: all of it, with spacing shown, of an edited text file.
    const partial =
        !!diff?.hash &&
        !diff.truncated &&
        !hideWhitespace &&
        change.status === 'M';
    const discardable = partial && !staged && !working;

    const send = (url: string, body: Record<string, unknown>) => {
        setBusy(true);
        setError(null);

        return jsonRequest<{ status: GitStatus }>(url, body)
            .then((changed) => onStatus(changed.status))
            .catch((e: Error) => setError(e.message))
            .finally(() => {
                setBusy(false);
                setConfirming(null);
            });
    };

    const stageLines = (part: { hunk: number } | { lines: number[] }) =>
        send(ProjectGitController.stageLines.url(projectId), {
            path: change.path,
            hash: diff?.hash,
            staged,
            ...part,
        });

    const hunkActions = (hunk: Hunk) => (
        <>
            <Button
                type="button"
                size="sm"
                variant="ghost"
                className="h-6 px-2 text-xs text-muted-foreground"
                onClick={() => askAgent(askAbout(change.path, hunk))}
                title="Ask the agent about this part"
                data-test="git-actions-hunk-ask"
            >
                <MessageSquare className="size-3.5" />
                Ask
            </Button>
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
                            disabled={busy}
                            onClick={() =>
                                send(
                                    ProjectGitController.discardHunk.url(
                                        projectId,
                                    ),
                                    {
                                        path: change.path,
                                        hash: diff?.hash,
                                        hunk: hunk.number,
                                    },
                                )
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
                            onClick={() => setConfirming(null)}
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
                        onClick={() => setConfirming(hunk.number)}
                        title="Put this part back as it's staged, or as it was in the last commit"
                        data-test="git-actions-hunk-discard"
                    >
                        <Undo2 className="size-3.5" />
                        Discard
                    </Button>
                ))}
            {partial && (
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    className="h-6 px-2 text-xs text-muted-foreground"
                    disabled={busy}
                    onClick={() => stageLines({ hunk: hunk.number })}
                    data-test={staged ? 'scm-hunk-unstage' : 'scm-hunk-stage'}
                >
                    {staged ? (
                        <Minus className="size-3.5" />
                    ) : (
                        <Plus className="size-3.5" />
                    )}
                    {staged ? 'Unstage' : 'Stage'}
                </Button>
            )}
        </>
    );

    return (
        <section
            className="flex h-full min-h-0 min-w-0 flex-col"
            aria-label={`${staged ? 'Staged changes' : 'Changes'} to ${change.path}`}
            data-test="scm-diff"
        >
            <header className="flex items-center gap-2 border-b px-3 py-2 text-xs">
                <Button
                    type="button"
                    size="icon"
                    variant="ghost"
                    className="size-7 shrink-0 @3xl/scm:hidden"
                    aria-label="Back to the changes"
                    onClick={onClose}
                    data-test="scm-diff-back"
                >
                    <ArrowLeft className="size-4" />
                </Button>
                <span className={cn('font-semibold', style.className)}>
                    {style.label}
                </span>
                <button
                    type="button"
                    className="min-w-0 flex-1 truncate text-left font-mono underline-offset-4 hover:underline"
                    title={`Open ${change.path}`}
                    onClick={() => onOpenFile(change.path)}
                    data-test="scm-diff-path"
                >
                    {change.path}
                </button>
                <span className="shrink-0 rounded bg-muted px-1.5 py-0.5 text-muted-foreground">
                    {staged ? 'Staged' : 'Not staged'}
                </span>
                {!!diff?.patch && (
                    <label className="flex shrink-0 cursor-pointer items-center gap-1.5 text-muted-foreground max-sm:hidden">
                        <Checkbox
                            checked={hideWhitespace}
                            onCheckedChange={(checked) =>
                                setHideWhitespace(checked === true)
                            }
                            data-test="git-actions-hide-whitespace"
                        />
                        Hide whitespace
                    </label>
                )}
                {change.status !== 'D' && (
                    <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        className="size-7 shrink-0"
                        aria-label="Open the file"
                        title="Open the file"
                        onClick={() => onOpenFile(change.path)}
                    >
                        <FileText className="size-4" />
                    </Button>
                )}
                {!staged && change.status !== 'U' && (
                    <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        className="size-7 shrink-0"
                        aria-label="Discard changes to this file"
                        title="Discard changes"
                        disabled={working}
                        onClick={onDiscardFile}
                        data-test="scm-diff-discard"
                    >
                        <Undo2 className="size-4" />
                    </Button>
                )}
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    className="h-7 shrink-0"
                    onClick={onStageFile}
                    data-test={staged ? 'scm-diff-unstage' : 'scm-diff-stage'}
                >
                    {staged ? (
                        <Minus className="size-4" />
                    ) : (
                        <Plus className="size-4" />
                    )}
                    {staged
                        ? 'Unstage file'
                        : change.status === 'U'
                          ? 'Mark resolved'
                          : 'Stage file'}
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
                ) : (
                    <PatchView
                        patch={diff.patch}
                        truncated={diff.truncated}
                        path={change.path}
                        hideWhitespace={hideWhitespace}
                        picking={
                            partial
                                ? {
                                      isPicked: (index) => picked.has(index),
                                      toggleLine: (index) =>
                                          setPicked((current) => {
                                              const next = new Set(current);

                                              if (!next.delete(index)) {
                                                  next.add(index);
                                              }

                                              return next;
                                          }),
                                  }
                                : null
                        }
                        hunkActions={hunkActions}
                        className="min-h-full overflow-visible bg-transparent"
                    />
                )}
            </div>
            {picked.size > 0 ? (
                <footer
                    className="flex items-center gap-2 border-t px-3 py-1.5 text-xs"
                    data-test="scm-picked"
                >
                    <span className="flex-1 text-muted-foreground">
                        {picked.size} {picked.size === 1 ? 'line' : 'lines'}{' '}
                        picked
                    </span>
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        className="h-7"
                        onClick={() => setPicked(new Set())}
                    >
                        Clear
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        className="h-7"
                        disabled={busy}
                        onClick={() => stageLines({ lines: [...picked] })}
                        data-test="scm-lines-stage"
                    >
                        {staged ? 'Unstage lines' : 'Stage lines'}
                    </Button>
                </footer>
            ) : partial ? (
                <p className="border-t px-3 py-1.5 text-xs text-muted-foreground">
                    Click lines to pick them, or {staged ? 'unstage' : 'stage'}{' '}
                    a whole part.
                </p>
            ) : (
                hideWhitespace &&
                change.status === 'M' && (
                    <p className="border-t px-3 py-1.5 text-xs text-muted-foreground">
                        Show whitespace to stage or discard parts.
                    </p>
                )
            )}
        </section>
    );
}
