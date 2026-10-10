import {
    Bot,
    Check,
    ChevronDown,
    ChevronRight,
    CloudUpload,
    Combine,
    Download,
    EllipsisVertical,
    FileText,
    GitBranchPlus,
    GitMerge,
    History as HistoryIcon,
    Minus,
    Pencil,
    Plus,
    RefreshCw,
    Sparkles,
    Undo2,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { KeyboardEvent, ReactNode } from 'react';
import { toast } from 'sonner';
import ProjectGitController from '@/actions/App/Http/Controllers/ProjectGitController';
import SocialProviderIcon from '@/components/social-provider-icon';
import { Button } from '@/components/ui/button';
import { Dialog } from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import CombineCommitsDialog from '@/components/workspace/combine-commits-dialog';
import FileIcon from '@/components/workspace/file-icon';
import {
    BranchBar,
    CommitDetails,
    Confirm,
    Empty,
    History,
    RemoteCard,
    timeAgo,
} from '@/components/workspace/git-parts';
import type { Commit } from '@/components/workspace/git-parts';
import type { GitHubInfo } from '@/components/workspace/github-connect';
import {
    allChanges,
    DEFAULT_BRANCHES,
    gitChanged,
    onGitChanged,
} from '@/components/workspace/git-state';
import type {
    Change,
    Checkpoint,
    GitRemote,
    GitStatus,
} from '@/components/workspace/git-state';
import PullRequestDialog from '@/components/workspace/pull-request-dialog';
import SourceControlDiff, {
    STATUS_STYLES,
} from '@/components/workspace/source-control-diff';
import UndoCommitDialog from '@/components/workspace/undo-commit-dialog';
import { jsonRequest } from '@/lib/json-request';
import { cn } from '@/lib/utils';
import { onOpenWorkspaceTool, openWorkspaceTool } from '@/lib/workspace-view';

type GitData = {
    status: GitStatus;
    commits: Commit[];
    /** There are older commits than those listed. */
    more: boolean;
    checkpoints: { checkpoints: Checkpoint[]; more: boolean };
    /** The agent commits each turn to the branch (SCM-003). */
    commit_turns: boolean;
    remote: GitRemote | null;
    backed_up_at: string | null;
    github: GitHubInfo;
};

type Selection =
    | { kind: 'change'; path: string; staged: boolean }
    | { kind: 'commit'; sha: string }
    | { kind: 'checkpoint'; checkpoint: Checkpoint };

type Section = 'staged' | 'changes' | 'timeline' | 'history' | 'repository';

type OpenDialog =
    | { kind: 'undo' }
    | { kind: 'combine' }
    | { kind: 'pull-request' };

const COLLAPSED_KEY = 'onedrop.scm-collapsed';

/** Source Control should put the cursor in its message box once it shows (it may not be open yet). */
let focusMessage = false;

/** Show Source Control with the cursor in its message box, ready to commit (the header's Commit button). */
export function openForCommit(): void {
    focusMessage = true;
    openWorkspaceTool('git-commit');
}

const CHECKPOINT_KINDS: Record<string, { label: string; icon: ReactNode }> = {
    turn: { label: 'Agent turn', icon: <Bot className="size-4" /> },
    edits: {
        label: 'Changes outside the agent',
        icon: <Pencil className="size-4" />,
    },
    restore: { label: 'Restore', icon: <HistoryIcon className="size-4" /> },
    apply: { label: 'Applied task', icon: <GitMerge className="size-4" /> },
};

/**
 * Source Control (SCM-001): a workspace tab like VS Code's. The changes on the left, staged and unstaged, with a
 * message box to commit them; the agent's checkpoints (the Timeline, SCM-002), the history and the repository below;
 * the selected file's diff (or a commit's or checkpoint's details) on the right. Whether the agent commits each turn
 * is switched here too (SCM-003).
 */
export default function SourceControlView({
    projectId,
    running,
    working,
    refreshSignal,
    onOpenFile,
}: {
    projectId: number;
    running: boolean;
    /** The agent is running a task. */
    working: boolean;
    /** Changes when files may have changed (the agent's activity, the file watcher). */
    refreshSignal: string;
    onOpenFile: (path: string) => void;
}) {
    const [data, setData] = useState<GitData | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [notice, setNotice] = useState<string | null>(() =>
        takeParam('github_error'),
    );
    // Back from GitHub (`?github=connect`): open the Connect to GitHub dialog again, once.
    const [returnedFromGitHub] = useState(
        () => takeParam('github') === 'connect',
    );
    const [selection, setSelection] = useState<Selection | null>(null);
    const [message, setMessage] = useState('');
    const [committing, setCommitting] = useState(false);
    const [drafting, setDrafting] = useState(false);
    // The new branch's name while committing on a new branch, or null for the current one.
    const [newBranch, setNewBranch] = useState<string | null>(null);
    const [collapsed, setCollapsed] = useState<Set<Section>>(() => {
        try {
            return new Set(
                JSON.parse(
                    localStorage.getItem(COLLAPSED_KEY) ?? '["history"]',
                ),
            );
        } catch {
            return new Set(['history']);
        }
    });
    const [discarding, setDiscarding] = useState<{
        paths: string[] | null;
    } | null>(null);
    const [restoring, setRestoring] = useState<{
        checkpoint: Checkpoint;
        before: boolean;
    } | null>(null);
    const [dialog, setDialog] = useState<OpenDialog | null>(null);
    const pendingDialog = useRef<OpenDialog | null>(null);
    const [loadingCheckpoints, setLoadingCheckpoints] = useState(false);
    const messageBox = useRef<HTMLTextAreaElement>(null);
    const newBranchBox = useRef<HTMLInputElement>(null);
    // The toast following a push until it's done.
    const pushToast = useRef<string | number | null>(null);

    const load = useCallback(
        () =>
            jsonRequest<GitData>(ProjectGitController.index.url(projectId))
                .then((loaded) => {
                    setData(loaded);
                    setError(null);
                })
                .catch((e: Error) => setError(e.message)),
        [projectId],
    );

    // Reload when files may have changed: the agent finishing (a new checkpoint), the Shell, the Files panel.
    useEffect(() => {
        if (!running) {
            return;
        }

        const timer = setTimeout(load, 300);

        return () => clearTimeout(timer);
    }, [load, running, working, refreshSignal]);

    // The header's git menu changed the repository (a push, an undo): catch up.
    useEffect(
        () =>
            onGitChanged((changed) =>
                changed.status
                    ? setData((current) =>
                          current
                              ? { ...current, ...(changed as Partial<GitData>) }
                              : current,
                      )
                    : void load(),
            ),
        [load],
    );

    // The header's Commit button: put the cursor in the message box, now or once it's there.
    const loaded = data !== null;

    useEffect(() => {
        const focus = () => {
            if (focusMessage && messageBox.current) {
                focusMessage = false;
                setTimeout(() => messageBox.current?.focus(), 50);
            }
        };

        focus();

        return onOpenWorkspaceTool(focus);
    }, [loaded]);

    const syncStatus = data?.remote?.sync_status ?? null;
    const syncing = syncStatus === 'pushing' || syncStatus === 'pulling';

    useEffect(() => {
        if (!syncing) {
            return;
        }

        const timer = setInterval(load, 2000);

        return () => clearInterval(timer);
    }, [load, syncing]);

    // Say how a push started here went once it's done.
    useEffect(() => {
        if (pushToast.current === null || syncing || !data) {
            return;
        }

        if (syncStatus === 'failed') {
            toast.error(data.remote?.sync_error ?? 'The push failed.', {
                id: pushToast.current,
            });
        } else {
            toast.success('Pushed', { id: pushToast.current });
        }

        pushToast.current = null;
        gitChanged({ remote: data.remote, status: data.status });
    }, [data, syncStatus, syncing]);

    const toggleSection = (section: Section) =>
        setCollapsed((current) => {
            const next = new Set(current);

            if (!next.delete(section)) {
                next.add(section);
            }

            localStorage.setItem(COLLAPSED_KEY, JSON.stringify([...next]));

            return next;
        });

    if (!running) {
        return (
            <Centered>
                <Empty>Source Control works when the sandbox is running.</Empty>
            </Centered>
        );
    }

    if (error && !data) {
        return (
            <Centered>
                <Empty tone="error">{error}</Empty>
            </Centered>
        );
    }

    if (!data) {
        return (
            <Centered>
                <Empty>Loading…</Empty>
            </Centered>
        );
    }

    const { status } = data;
    const conflicts = status.changes.filter((change) => change.status === 'U');
    const unstaged = status.changes.filter((change) => change.status !== 'U');
    const everything = allChanges(status);
    const nothingStaged = status.staged.length === 0;
    const toCommit = nothingStaged ? everything.length : status.staged.length;
    const remote = data.remote;
    const ahead = status.tracking?.ahead ?? null;
    const behind = status.tracking?.behind ?? 0;
    const canPush = !!remote && !!status.head && ahead !== 0 && !syncing;
    const branch = status.branch;
    const bases = status.branches.filter((other) => other !== branch);
    const combinable = remote ? (status.unpushed ?? 0) : 0;
    const lastCommit = data.commits[0] ?? null;
    const canUndo =
        !working &&
        !syncing &&
        !!lastCommit &&
        (data.commits.length > 1 || data.more) &&
        (status.unpushed == null || status.unpushed > 0);
    const pullRequestProblem =
        remote?.host !== 'github.com'
            ? 'Needs a GitHub repository'
            : !branch || DEFAULT_BRANCHES.includes(branch) || bases.length === 0
              ? 'Commit on a new branch first'
              : ahead !== 0
                ? 'Push the branch first'
                : null;

    /** Take what a change returned into the view, and tell the header. */
    const apply = (changed: Partial<GitData>) => {
        setData((current) => (current ? { ...current, ...changed } : current));
        setNotice(null);
        gitChanged(changed);

        // A file that has nothing left to show in the list it was picked from is deselected.
        if (changed.status && selection?.kind === 'change') {
            const list = selection.staged
                ? changed.status.staged
                : changed.status.changes;

            if (!list.some((change) => change.path === selection.path)) {
                const other = (
                    selection.staged
                        ? changed.status.changes
                        : changed.status.staged
                ).some((change) => change.path === selection.path);

                setSelection(
                    other ? { ...selection, staged: !selection.staged } : null,
                );
            }
        }
    };

    /** Send a change and take what comes back into the view. */
    const send = (
        url: string,
        body: unknown,
        method: 'POST' | 'PUT' | 'DELETE' | 'PATCH' = 'POST',
    ) =>
        jsonRequest<Partial<GitData>>(url, body, method)
            .then((changed) => {
                apply(changed);

                return true;
            })
            .catch((e: Error) => {
                setNotice(e.message);

                return false;
            });

    const stage = (paths: string[] | null) =>
        send(ProjectGitController.stage.url(projectId), { paths });
    const unstage = (paths: string[] | null) =>
        send(ProjectGitController.unstage.url(projectId), { paths });

    const pushBranch = () => {
        const id = toast.loading('Pushing…');

        return jsonRequest<Pick<GitData, 'remote'>>(
            ProjectGitController.push.url(projectId),
            {},
        )
            .then((changed) => {
                pushToast.current = id;
                apply(changed);
            })
            .catch((e: Error) => toast.error(e.message, { id }));
    };

    const pull = () =>
        send(ProjectGitController.pull.url(projectId), {}).then(
            (ok) => ok && toast.message('Pulling…'),
        );

    const commit = (push: boolean) => {
        if (committing || toCommit === 0) {
            return;
        }

        setCommitting(true);
        jsonRequest<Pick<GitData, 'status' | 'commits'> & { message: string }>(
            ProjectGitController.commit.url(projectId),
            {
                message: message.trim() || null,
                branch: newBranch?.trim() || null,
            },
        )
            .then((changed) => {
                apply({ status: changed.status, commits: changed.commits });
                setMessage('');
                setNewBranch(null);
                toast.success(`Committed “${changed.message.split('\n')[0]}”`);

                if (push && remote) {
                    void pushBranch();
                }
            })
            .catch((e: Error) => setNotice(e.message))
            .finally(() => setCommitting(false));
    };

    const draft = () => {
        setDrafting(true);
        jsonRequest<{ message: string }>(
            ProjectGitController.draftMessage.url(projectId),
            {},
        )
            .then((drafted) => {
                setMessage(drafted.message);
                messageBox.current?.focus();
            })
            .catch((e: Error) => setNotice(e.message))
            .finally(() => setDrafting(false));
    };

    const showMoreCheckpoints = () => {
        setLoadingCheckpoints(true);
        jsonRequest<GitData['checkpoints']>(
            ProjectGitController.checkpoints.url(projectId, {
                query: { offset: data.checkpoints.checkpoints.length },
            }),
        )
            .then((older) =>
                setData((current) =>
                    current
                        ? {
                              ...current,
                              checkpoints: {
                                  checkpoints: [
                                      ...current.checkpoints.checkpoints,
                                      ...older.checkpoints,
                                  ],
                                  more: older.more,
                              },
                          }
                        : current,
                ),
            )
            .catch((e: Error) => setNotice(e.message))
            .finally(() => setLoadingCheckpoints(false));
    };

    const selectedChange =
        selection?.kind === 'change'
            ? ((selection.staged ? status.staged : status.changes).find(
                  (change) => change.path === selection.path,
              ) ?? null)
            : null;

    const openDialog = (next: OpenDialog) => {
        pendingDialog.current = next;
    };

    // ↑/↓ move through the files in the lists, showing each one's diff.
    const moveThroughRows = (event: KeyboardEvent<HTMLElement>) => {
        if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') {
            return;
        }

        const rows = [
            ...event.currentTarget.querySelectorAll<HTMLButtonElement>(
                '[data-scm-row]',
            ),
        ];
        const index = rows.indexOf(document.activeElement as HTMLButtonElement);

        if (index === -1) {
            return;
        }

        event.preventDefault();
        const next =
            rows[
                Math.min(
                    rows.length - 1,
                    Math.max(0, index + (event.key === 'ArrowDown' ? 1 : -1)),
                )
            ];
        next.focus();
        next.click();
    };

    const changeRows = (changes: Change[], staged: boolean) =>
        changes.map((change) => (
            <ChangeRow
                key={`${staged}:${change.path}`}
                change={change}
                staged={staged}
                selected={
                    selection?.kind === 'change' &&
                    selection.path === change.path &&
                    selection.staged === staged
                }
                working={working}
                onSelect={() =>
                    setSelection({ kind: 'change', path: change.path, staged })
                }
                onOpen={() => onOpenFile(change.path)}
                onStage={() =>
                    staged ? unstage([change.path]) : stage([change.path])
                }
                onDiscard={() => setDiscarding({ paths: [change.path] })}
            />
        ));

    return (
        <div
            className="@container/scm flex h-full min-h-0 bg-background"
            data-test="source-control"
        >
            <aside
                className={cn(
                    'flex min-h-0 w-full flex-col @3xl/scm:w-80 @3xl/scm:shrink-0 @3xl/scm:border-r @6xl/scm:w-96',
                    selection && '@max-3xl/scm:hidden',
                )}
            >
                <div className="flex items-center gap-1 border-b px-2 py-1.5">
                    <BranchBar
                        projectId={projectId}
                        status={status}
                        disabled={working}
                        send={send}
                    />
                    <div className="flex-1" />
                    {remote && (
                        <>
                            <Button
                                variant="ghost"
                                size="sm"
                                className="h-7 gap-1 px-1.5 text-xs"
                                disabled={syncing || !status.head || working}
                                onClick={pull}
                                title={
                                    behind > 0
                                        ? `Pull ${behind} new ${behind === 1 ? 'commit' : 'commits'}`
                                        : 'Pull'
                                }
                                data-test="git-pull"
                            >
                                <Download className="size-3.5" />
                                {behind > 0 && behind}
                            </Button>
                            <Button
                                variant="ghost"
                                size="sm"
                                className="h-7 gap-1 px-1.5 text-xs"
                                disabled={!canPush}
                                onClick={() => void pushBranch()}
                                title={
                                    syncStatus === 'pushing'
                                        ? 'Pushing…'
                                        : ahead === null
                                          ? 'Push the branch'
                                          : `Push ${ahead} ${ahead === 1 ? 'commit' : 'commits'}`
                                }
                                data-test="git-push"
                            >
                                <CloudUpload className="size-3.5" />
                                {syncStatus === 'pushing'
                                    ? '…'
                                    : ahead !== null && ahead > 0 && ahead}
                            </Button>
                        </>
                    )}
                    <Button
                        variant="ghost"
                        size="icon"
                        className="size-7"
                        aria-label="Refresh"
                        onClick={() => void load()}
                        data-test="git-refresh"
                    >
                        <RefreshCw className="size-3.5" />
                    </Button>
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                variant="ghost"
                                size="icon"
                                className="size-7"
                                aria-label="More source control actions"
                                data-test="scm-menu"
                            >
                                <EllipsisVertical className="size-4" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent
                            align="end"
                            className="w-60"
                            // Open the dialog only after the closing menu lets go of focus, so its input keeps it.
                            onCloseAutoFocus={(event) => {
                                if (pendingDialog.current) {
                                    event.preventDefault();
                                    setDialog(pendingDialog.current);
                                    pendingDialog.current = null;
                                }
                            }}
                        >
                            <DropdownMenuItem
                                disabled={unstaged.length === 0}
                                onSelect={() => stage(null)}
                                data-test="scm-stage-all"
                            >
                                <Plus className="size-4" />
                                Stage all changes
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                disabled={nothingStaged}
                                onSelect={() => unstage(null)}
                                data-test="scm-unstage-all"
                            >
                                <Minus className="size-4" />
                                Unstage all changes
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                disabled={working || unstaged.length === 0}
                                onSelect={() => setDiscarding({ paths: null })}
                                data-test="git-discard-all"
                            >
                                <Undo2 className="size-4" />
                                Discard all changes
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            {lastCommit && (
                                <DropdownMenuItem
                                    disabled={!canUndo}
                                    onSelect={() =>
                                        openDialog({ kind: 'undo' })
                                    }
                                    data-test="scm-undo"
                                >
                                    <Undo2 className="size-4" />
                                    <span className="flex min-w-0 flex-col">
                                        Undo last commit
                                        <span className="truncate text-xs text-muted-foreground">
                                            {lastCommit.subject}
                                        </span>
                                    </span>
                                </DropdownMenuItem>
                            )}
                            {combinable > 1 && (
                                <DropdownMenuItem
                                    disabled={
                                        working || syncing || !nothingStaged
                                    }
                                    onSelect={() =>
                                        openDialog({ kind: 'combine' })
                                    }
                                    data-test="scm-combine"
                                >
                                    <Combine className="size-4" />
                                    <span className="flex flex-col">
                                        Combine {combinable} commits
                                        {!nothingStaged && (
                                            <span className="text-xs text-muted-foreground">
                                                Commit or unstage what's staged
                                                first
                                            </span>
                                        )}
                                    </span>
                                </DropdownMenuItem>
                            )}
                            <DropdownMenuItem
                                disabled={pullRequestProblem !== null}
                                onSelect={() =>
                                    openDialog({ kind: 'pull-request' })
                                }
                                data-test="scm-pr"
                            >
                                <SocialProviderIcon
                                    provider="github"
                                    className="size-4"
                                />
                                <span className="flex flex-col">
                                    Create PR
                                    {pullRequestProblem && (
                                        <span className="text-xs text-muted-foreground">
                                            {pullRequestProblem}
                                        </span>
                                    )}
                                </span>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>

                <div
                    className="min-h-0 flex-1 overflow-y-auto"
                    onKeyDown={moveThroughRows}
                >
                    <div className="space-y-2 p-2">
                        <div className="relative">
                            <textarea
                                ref={messageBox}
                                value={message}
                                onChange={(event) =>
                                    setMessage(event.target.value)
                                }
                                onKeyDown={(event) => {
                                    if (
                                        event.key === 'Enter' &&
                                        (event.metaKey || event.ctrlKey)
                                    ) {
                                        event.preventDefault();
                                        commit(false);
                                    }
                                }}
                                rows={Math.min(
                                    8,
                                    Math.max(2, message.split('\n').length),
                                )}
                                placeholder={`Message (⌘⏎ to commit on "${newBranch?.trim() || branch || 'main'}"). Leave it blank and AI writes one.`}
                                className="block w-full resize-none rounded-md border border-input bg-transparent px-2.5 py-1.5 pr-8 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring/50"
                                data-test="scm-message"
                            />
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="absolute top-1 right-1 size-6 text-muted-foreground"
                                aria-label="Write a message with AI"
                                title="Write a message with AI"
                                disabled={drafting || toCommit === 0}
                                onClick={draft}
                                data-test="scm-draft"
                            >
                                <Sparkles
                                    className={cn(
                                        'size-3.5',
                                        drafting && 'animate-pulse',
                                    )}
                                />
                            </Button>
                        </div>
                        {newBranch !== null && (
                            <div className="flex items-center gap-1">
                                <GitBranchPlus className="size-4 shrink-0 text-muted-foreground" />
                                <Input
                                    ref={newBranchBox}
                                    autoFocus
                                    value={newBranch}
                                    onChange={(event) =>
                                        setNewBranch(event.target.value)
                                    }
                                    onKeyDown={(event) =>
                                        event.key === 'Escape' &&
                                        setNewBranch(null)
                                    }
                                    placeholder="New branch name"
                                    className="h-7 text-sm"
                                    data-test="scm-new-branch"
                                />
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    className="h-7"
                                    onClick={() => setNewBranch(null)}
                                >
                                    Cancel
                                </Button>
                            </div>
                        )}
                        <div className="flex">
                            <Button
                                size="sm"
                                className="flex-1 rounded-r-none"
                                disabled={
                                    working ||
                                    committing ||
                                    toCommit === 0 ||
                                    newBranch?.trim() === ''
                                }
                                onClick={() => commit(false)}
                                title={
                                    working
                                        ? 'Wait for the agent to finish'
                                        : nothingStaged
                                          ? 'Nothing is staged: commit every change'
                                          : 'Commit the staged changes'
                                }
                                data-test="scm-commit"
                            >
                                <Check className="size-4" />
                                {committing
                                    ? 'Committing…'
                                    : nothingStaged && toCommit > 0
                                      ? `Commit all (${toCommit})`
                                      : `Commit${toCommit > 0 ? ` (${toCommit})` : ''}`}
                            </Button>
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button
                                        size="sm"
                                        className="rounded-l-none border-l border-primary-foreground/20 px-2"
                                        disabled={
                                            working ||
                                            committing ||
                                            toCommit === 0
                                        }
                                        aria-label="More ways to commit"
                                        data-test="scm-commit-menu"
                                    >
                                        <ChevronDown className="size-4" />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent
                                    align="end"
                                    // The new branch's name box keeps the focus the closing menu would take back.
                                    onCloseAutoFocus={(event) => {
                                        if (newBranchBox.current) {
                                            event.preventDefault();
                                            newBranchBox.current.focus();
                                        }
                                    }}
                                >
                                    {remote && (
                                        <DropdownMenuItem
                                            disabled={syncing}
                                            onSelect={() => commit(true)}
                                            data-test="scm-commit-push"
                                        >
                                            <CloudUpload className="size-4" />
                                            Commit & push
                                        </DropdownMenuItem>
                                    )}
                                    <DropdownMenuItem
                                        disabled={!status.head}
                                        onSelect={() => setNewBranch('')}
                                        data-test="scm-commit-branch"
                                    >
                                        <GitBranchPlus className="size-4" />
                                        Commit on new branch…
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>
                        {branch &&
                            DEFAULT_BRANCHES.includes(branch) &&
                            !newBranch && (
                                <p className="text-xs text-muted-foreground">
                                    On the default branch.
                                </p>
                            )}
                        {notice && (
                            <p
                                className="text-sm text-red-600"
                                data-test="git-notice"
                            >
                                {notice}
                            </p>
                        )}
                        {working && (
                            <p
                                className="text-xs text-muted-foreground"
                                data-test="git-working"
                            >
                                The agent is working. Its changes are saved as a
                                checkpoint when it finishes
                                {data.commit_turns ? ', and committed' : ''}.
                            </p>
                        )}
                        {status.state && (
                            <p
                                className="text-xs text-amber-600"
                                data-test="git-state"
                            >
                                In the middle of a{' '}
                                {status.state === 'merging'
                                    ? 'merge'
                                    : 'rebase'}
                                .{' '}
                                {status.state === 'merging'
                                    ? 'Resolve the conflicts, stage the files, then commit to finish it, or ask the agent.'
                                    : 'Ask the agent to finish it, or use the Shell.'}
                            </p>
                        )}
                    </div>

                    {conflicts.length > 0 && (
                        <SectionList
                            title="Merge changes"
                            count={conflicts.length}
                            open
                            testId="scm-conflicts"
                        >
                            {changeRows(conflicts, false)}
                        </SectionList>
                    )}

                    {status.staged.length > 0 && (
                        <SectionList
                            title="Staged changes"
                            count={status.staged.length}
                            open={!collapsed.has('staged')}
                            onToggle={() => toggleSection('staged')}
                            actions={
                                <RowAction
                                    label="Unstage all changes"
                                    onClick={() => unstage(null)}
                                    testId="scm-unstage-all-inline"
                                >
                                    <Minus className="size-3.5" />
                                </RowAction>
                            }
                            testId="scm-staged"
                        >
                            {changeRows(status.staged, true)}
                        </SectionList>
                    )}

                    <SectionList
                        title="Changes"
                        count={unstaged.length}
                        more={status.more_changes}
                        open={!collapsed.has('changes')}
                        onToggle={() => toggleSection('changes')}
                        actions={
                            unstaged.length > 0 && (
                                <>
                                    <RowAction
                                        label="Discard all changes"
                                        disabled={working}
                                        onClick={() =>
                                            setDiscarding({ paths: null })
                                        }
                                        testId="scm-discard-all-inline"
                                    >
                                        <Undo2 className="size-3.5" />
                                    </RowAction>
                                    <RowAction
                                        label="Stage all changes"
                                        onClick={() => stage(null)}
                                        testId="scm-stage-all-inline"
                                    >
                                        <Plus className="size-3.5" />
                                    </RowAction>
                                </>
                            )
                        }
                        testId="git-changes"
                    >
                        {unstaged.length === 0 ? (
                            <p
                                className="px-3 py-1.5 text-xs text-muted-foreground"
                                data-test="scm-no-changes"
                            >
                                {everything.length === 0
                                    ? 'No changes.'
                                    : 'Everything is staged.'}
                            </p>
                        ) : (
                            changeRows(unstaged, false)
                        )}
                    </SectionList>

                    <SectionList
                        title="Timeline"
                        count={data.checkpoints.checkpoints.length}
                        more={data.checkpoints.more}
                        open={!collapsed.has('timeline')}
                        onToggle={() => toggleSection('timeline')}
                        hint="Every agent turn, saved privately: restore any of them"
                        testId="scm-timeline"
                    >
                        {data.checkpoints.checkpoints.length === 0 ? (
                            <p className="px-3 py-1.5 text-xs text-muted-foreground">
                                Each agent turn is saved here as a checkpoint.
                            </p>
                        ) : (
                            data.checkpoints.checkpoints.map((checkpoint) => (
                                <CheckpointRow
                                    key={checkpoint.sha}
                                    checkpoint={checkpoint}
                                    selected={
                                        selection?.kind === 'checkpoint' &&
                                        selection.checkpoint.sha ===
                                            checkpoint.sha
                                    }
                                    onSelect={() =>
                                        setSelection({
                                            kind: 'checkpoint',
                                            checkpoint,
                                        })
                                    }
                                />
                            ))
                        )}
                        {data.checkpoints.more && (
                            <Button
                                variant="ghost"
                                size="sm"
                                className="w-full"
                                disabled={loadingCheckpoints}
                                onClick={showMoreCheckpoints}
                                data-test="scm-timeline-more"
                            >
                                {loadingCheckpoints ? 'Loading…' : 'Show more'}
                            </Button>
                        )}
                    </SectionList>

                    <SectionList
                        title="History"
                        count={data.commits.length}
                        more={data.more}
                        open={!collapsed.has('history')}
                        onToggle={() => toggleSection('history')}
                        testId="scm-history"
                    >
                        <History
                            projectId={projectId}
                            commits={data.commits}
                            more={data.more}
                            head={status.head}
                            undoable={canUndo}
                            disabled={working}
                            selected={
                                selection?.kind === 'commit'
                                    ? selection.sha
                                    : null
                            }
                            onSelect={(picked) =>
                                setSelection({
                                    kind: 'commit',
                                    sha: picked.sha,
                                })
                            }
                            send={send}
                            onUndone={(changed) =>
                                apply({
                                    status: changed.status as GitStatus,
                                    commits: changed.commits as Commit[],
                                })
                            }
                        />
                    </SectionList>

                    <SectionList
                        title="Repository"
                        open={!collapsed.has('repository')}
                        onToggle={() => toggleSection('repository')}
                        testId="scm-repository"
                    >
                        <div className="space-y-4 px-3 pt-1 pb-4">
                            <label
                                className="flex items-start gap-3 rounded-lg border p-3"
                                data-test="scm-commit-turns"
                            >
                                <span className="min-w-0 flex-1">
                                    <span className="block text-sm font-medium">
                                        Commit after each agent turn
                                    </span>
                                    <span className="block text-xs text-muted-foreground">
                                        {data.commit_turns
                                            ? `The agent commits its changes to ${branch ?? 'the branch'} after every turn, with your message as the commit message.`
                                            : 'The agent leaves its changes here for you to review, stage and commit. Each turn is still saved in the Timeline and backed up, and never pushed.'}
                                    </span>
                                </span>
                                <Switch
                                    checked={data.commit_turns}
                                    label="Commit after each agent turn"
                                    testId="scm-commit-turns-switch"
                                    onChange={(checked) =>
                                        void send(
                                            ProjectGitController.settings.url(
                                                projectId,
                                            ),
                                            { commit_turns: checked },
                                            'PATCH',
                                        )
                                    }
                                />
                            </label>

                            {data.github.problems.length > 0 && (
                                <div
                                    className="space-y-2 rounded-lg border border-amber-500/40 bg-amber-500/5 p-3 text-sm"
                                    data-test="git-github-problems"
                                >
                                    <p className="font-medium">
                                        The GitHub App needs attention (only
                                        admins see this)
                                    </p>
                                    <ul className="list-disc space-y-1 pl-5 text-muted-foreground">
                                        {data.github.problems.map((problem) => (
                                            <li key={problem}>{problem}</li>
                                        ))}
                                    </ul>
                                    {data.github.settings_url && (
                                        <a
                                            href={data.github.settings_url}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="inline-block underline underline-offset-4"
                                        >
                                            Open the app's permissions on GitHub
                                        </a>
                                    )}
                                </div>
                            )}

                            <RemoteCard
                                projectId={projectId}
                                remote={remote}
                                status={status}
                                github={data.github}
                                autoOpenGitHub={returnedFromGitHub && !notice}
                                onReload={() => void load()}
                                send={send}
                            />

                            <p className="text-xs text-muted-foreground">
                                The history and the Timeline are backed up
                                outside the sandbox
                                {data.backed_up_at
                                    ? ` (last backup ${timeAgo(data.backed_up_at)})`
                                    : ''}
                                . Dependencies, caches and{' '}
                                <code className="font-mono">.env</code> secrets
                                are never committed.
                            </p>
                        </div>
                    </SectionList>
                </div>
            </aside>

            <main
                className={cn(
                    'min-h-0 min-w-0 flex-1 overflow-hidden',
                    !selection && '@max-3xl/scm:hidden',
                )}
            >
                {selectedChange && selection?.kind === 'change' ? (
                    <SourceControlDiff
                        key={`${selection.staged}:${selection.path}`}
                        projectId={projectId}
                        change={selectedChange}
                        staged={selection.staged}
                        working={working}
                        onStatus={(changed) => apply({ status: changed })}
                        onOpenFile={onOpenFile}
                        onStageFile={() =>
                            selection.staged
                                ? unstage([selection.path])
                                : stage([selection.path])
                        }
                        onDiscardFile={() =>
                            setDiscarding({ paths: [selection.path] })
                        }
                        onClose={() => setSelection(null)}
                    />
                ) : selection?.kind === 'commit' ? (
                    <DetailPane onBack={() => setSelection(null)}>
                        <CommitDetails
                            projectId={projectId}
                            sha={selection.sha}
                        />
                    </DetailPane>
                ) : selection?.kind === 'checkpoint' ? (
                    <DetailPane onBack={() => setSelection(null)}>
                        <CommitDetails
                            projectId={projectId}
                            sha={selection.checkpoint.sha}
                            actions={
                                <div className="flex shrink-0 gap-2">
                                    {selection.checkpoint.restorable_before && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            disabled={working}
                                            onClick={() =>
                                                setRestoring({
                                                    checkpoint:
                                                        selection.checkpoint,
                                                    before: true,
                                                })
                                            }
                                            data-test="scm-restore-before"
                                        >
                                            <Undo2 className="size-4" />
                                            Restore before this
                                        </Button>
                                    )}
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        disabled={working}
                                        onClick={() =>
                                            setRestoring({
                                                checkpoint:
                                                    selection.checkpoint,
                                                before: false,
                                            })
                                        }
                                        data-test="scm-restore"
                                    >
                                        <HistoryIcon className="size-4" />
                                        Restore this
                                    </Button>
                                </div>
                            }
                        />
                    </DetailPane>
                ) : (
                    <Centered>
                        <div className="max-w-sm space-y-2 text-center text-sm text-muted-foreground">
                            <p className="font-medium text-foreground">
                                {everything.length === 0
                                    ? 'No changes'
                                    : `${everything.length}${status.more_changes ? '+' : ''} changed ${everything.length === 1 ? 'file' : 'files'}`}
                            </p>
                            <p>
                                Pick a file to see its changes and stage parts
                                of it, or a checkpoint in the Timeline to see
                                what an agent turn did.
                            </p>
                        </div>
                    </Centered>
                )}
            </main>

            {discarding && (
                <Confirm
                    title={
                        discarding.paths === null
                            ? 'Discard all changes?'
                            : 'Discard changes to this file?'
                    }
                    description={
                        discarding.paths === null
                            ? 'Every unstaged change goes back to how it was staged or last committed, and new files are deleted. Staged changes stay. The Timeline keeps the files as they were.'
                            : `${discarding.paths[0]} goes back to how it was staged or last committed (or is deleted if it's new). The Timeline keeps the file as it was.`
                    }
                    action="Discard"
                    onConfirm={() =>
                        send(ProjectGitController.discard.url(projectId), {
                            paths: discarding.paths,
                        })
                    }
                    onClose={() => setDiscarding(null)}
                />
            )}
            {restoring && (
                <Confirm
                    title={
                        restoring.before
                            ? 'Restore the files from before this?'
                            : 'Restore the files to this checkpoint?'
                    }
                    description={`Every file goes back to how it was ${restoring.before ? 'just before' : 'at'} "${restoring.checkpoint.subject}". Nothing is committed: the difference shows as changes, and what's staged stays staged. The files as they are now are saved in the Timeline first, so you can come back.`}
                    action="Restore"
                    onConfirm={() =>
                        send(
                            ProjectGitController.restoreCheckpoint.url(
                                projectId,
                            ),
                            {
                                sha: restoring.checkpoint.sha,
                                before: restoring.before,
                            },
                        ).then((ok) => {
                            if (ok) {
                                toast.success('Restored');
                            }

                            return ok;
                        })
                    }
                    onClose={() => setRestoring(null)}
                />
            )}

            <Dialog
                open={dialog !== null}
                onOpenChange={(open) => !open && setDialog(null)}
            >
                {dialog?.kind === 'undo' && lastCommit && (
                    <UndoCommitDialog
                        projectId={projectId}
                        commit={lastCommit}
                        onClose={() => setDialog(null)}
                        onUndone={(changed) => {
                            apply({
                                status: changed.status as GitStatus,
                                commits: changed.commits as Commit[],
                            });
                            setDialog(null);
                        }}
                    />
                )}
                {dialog?.kind === 'combine' && (
                    <CombineCommitsDialog
                        projectId={projectId}
                        working={working}
                        onClose={() => setDialog(null)}
                        onCombined={(changed) => {
                            apply(changed as Partial<GitData>);
                            setDialog(null);
                        }}
                    />
                )}
                {dialog?.kind === 'pull-request' && branch && (
                    <PullRequestDialog
                        projectId={projectId}
                        branch={branch}
                        bases={bases}
                        onClose={() => setDialog(null)}
                    />
                )}
            </Dialog>
        </div>
    );
}

/** A one-time query parameter (from coming back from GitHub): read it, and take it out of the address bar. */
function takeParam(name: string): string | null {
    if (typeof window === 'undefined') {
        return null;
    }

    const params = new URLSearchParams(window.location.search);
    const value = params.get(name);

    if (value !== null) {
        params.delete(name);
        const query = params.toString();
        window.history.replaceState(
            window.history.state,
            '',
            window.location.pathname + (query ? `?${query}` : ''),
        );
    }

    return value;
}

function Centered({ children }: { children: ReactNode }) {
    return (
        <div className="flex h-full items-center justify-center p-6">
            {children}
        </div>
    );
}

/** A commit's or checkpoint's details on the right, with a way back on a narrow pane. */
function DetailPane({
    onBack,
    children,
}: {
    onBack: () => void;
    children: ReactNode;
}) {
    return (
        <div className="h-full overflow-y-auto" data-test="scm-detail">
            <Button
                variant="ghost"
                size="sm"
                className="m-2 mb-0 @3xl/scm:hidden"
                onClick={onBack}
            >
                <ChevronRight className="size-4 rotate-180" />
                Back
            </Button>
            {children}
        </div>
    );
}

/** A collapsible list in the left column, with a count and actions shown on hover. */
function SectionList({
    title,
    count,
    more = false,
    open,
    onToggle,
    actions,
    hint,
    testId,
    children,
}: {
    title: string;
    count?: number;
    more?: boolean;
    open: boolean;
    onToggle?: () => void;
    actions?: ReactNode;
    hint?: string;
    testId: string;
    children: ReactNode;
}) {
    return (
        <section className="group/section" data-test={testId}>
            <div className="sticky top-0 z-10 flex items-center gap-1 bg-background/95 px-1 py-0.5 backdrop-blur">
                <button
                    type="button"
                    onClick={onToggle}
                    disabled={!onToggle}
                    aria-expanded={open}
                    title={hint}
                    className="flex min-w-0 flex-1 items-center gap-1 rounded px-1 py-1 text-left text-xs font-semibold tracking-wide text-muted-foreground uppercase hover:text-foreground"
                >
                    <ChevronRight
                        className={cn(
                            'size-3.5 shrink-0 transition-transform',
                            open && 'rotate-90',
                        )}
                    />
                    <span className="truncate">{title}</span>
                </button>
                <span className="flex opacity-0 group-hover/section:opacity-100 focus-within:opacity-100">
                    {actions}
                </span>
                {count !== undefined && count > 0 && (
                    <span
                        className="rounded-full bg-muted px-1.5 text-xs text-muted-foreground tabular-nums"
                        data-test={`${testId}-count`}
                    >
                        {count}
                        {more ? '+' : ''}
                    </span>
                )}
            </div>
            {open && <div className="pb-2">{children}</div>}
        </section>
    );
}

function RowAction({
    label,
    onClick,
    disabled = false,
    testId,
    children,
}: {
    label: string;
    onClick: () => void;
    disabled?: boolean;
    testId?: string;
    children: ReactNode;
}) {
    return (
        <Button
            type="button"
            variant="ghost"
            size="icon"
            className="size-6 text-muted-foreground hover:text-foreground"
            aria-label={label}
            title={label}
            disabled={disabled}
            onClick={(event) => {
                event.stopPropagation();
                onClick();
            }}
            data-test={testId}
        >
            {children}
        </Button>
    );
}

/** One file in the changes: its name and folder, state and lines, and hover actions to open, discard or (un)stage it. */
function ChangeRow({
    change,
    staged,
    selected,
    working,
    onSelect,
    onOpen,
    onStage,
    onDiscard,
}: {
    change: Change;
    staged: boolean;
    selected: boolean;
    working: boolean;
    onSelect: () => void;
    onOpen: () => void;
    onStage: () => void;
    onDiscard: () => void;
}) {
    const style = STATUS_STYLES[change.status] ?? STATUS_STYLES.M;
    const slash = change.path.replace(/\/$/, '').lastIndexOf('/');
    const name = change.path.slice(slash + 1);
    const folder = slash === -1 ? '' : change.path.slice(0, slash);

    return (
        <div
            className={cn(
                'group flex items-center gap-1 pr-1 hover:bg-muted/50',
                selected && 'bg-muted',
            )}
            data-test="git-change"
        >
            <button
                type="button"
                onClick={onSelect}
                aria-current={selected}
                className="flex min-w-0 flex-1 items-center gap-2 py-1 pl-6 text-left text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-inset"
                title={`${change.path} · ${style.label}`}
                data-scm-row
                data-test="git-change-open"
            >
                <FileIcon name={name} isDir={change.path.endsWith('/')} />
                <span
                    className={cn(
                        'truncate',
                        change.status === 'D' && 'line-through',
                    )}
                >
                    {name}
                </span>
                <span className="min-w-0 flex-1 truncate text-xs text-muted-foreground">
                    {folder}
                </span>
                {change.additions != null && change.deletions != null && (
                    <span className="shrink-0 font-mono text-[11px] group-hover:hidden">
                        <span className="text-green-600">
                            +{change.additions}
                        </span>{' '}
                        <span className="text-red-600">
                            −{change.deletions}
                        </span>
                    </span>
                )}
            </button>
            <span className="hidden shrink-0 group-focus-within:flex group-hover:flex">
                {change.status !== 'D' && (
                    <RowAction label="Open file" onClick={onOpen}>
                        <FileText className="size-3.5" />
                    </RowAction>
                )}
                {!staged && change.status !== 'U' && (
                    <RowAction
                        label="Discard changes"
                        disabled={working}
                        onClick={onDiscard}
                        testId="git-change-discard"
                    >
                        <Undo2 className="size-3.5" />
                    </RowAction>
                )}
                <RowAction
                    label={
                        staged
                            ? 'Unstage'
                            : change.status === 'U'
                              ? 'Mark resolved (stage)'
                              : 'Stage'
                    }
                    onClick={onStage}
                    testId={staged ? 'git-change-unstage' : 'git-change-stage'}
                >
                    {staged ? (
                        <Minus className="size-3.5" />
                    ) : (
                        <Plus className="size-3.5" />
                    )}
                </RowAction>
            </span>
            <span
                title={style.label}
                className={cn(
                    'w-4 shrink-0 text-center font-mono text-xs',
                    style.className,
                )}
            >
                {style.letter}
            </span>
        </div>
    );
}

/** One checkpoint in the Timeline: what made it, when, and the lines it changed. */
function CheckpointRow({
    checkpoint,
    selected,
    onSelect,
}: {
    checkpoint: Checkpoint;
    selected: boolean;
    onSelect: () => void;
}) {
    const kind = CHECKPOINT_KINDS[checkpoint.kind] ?? CHECKPOINT_KINDS.turn;

    return (
        <button
            type="button"
            onClick={onSelect}
            aria-current={selected}
            className={cn(
                'flex w-full items-start gap-2 px-3 py-1.5 text-left hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-inset',
                selected && 'bg-muted',
            )}
            title={kind.label}
            data-scm-row
            data-test="scm-checkpoint"
        >
            <span className="mt-0.5 text-muted-foreground">{kind.icon}</span>
            <span className="min-w-0 flex-1">
                <span className="block truncate text-sm">
                    {checkpoint.subject}
                </span>
                <span className="block truncate text-xs text-muted-foreground">
                    <time
                        dateTime={checkpoint.date}
                        title={new Date(checkpoint.date).toLocaleString()}
                    >
                        {timeAgo(checkpoint.date)}
                    </time>
                    {' · '}
                    {checkpoint.files}{' '}
                    {checkpoint.files === 1 ? 'file' : 'files'}{' '}
                    <span className="font-mono text-green-600">
                        +{checkpoint.additions}
                    </span>{' '}
                    <span className="font-mono text-red-600">
                        −{checkpoint.deletions}
                    </span>
                </span>
            </span>
        </button>
    );
}
