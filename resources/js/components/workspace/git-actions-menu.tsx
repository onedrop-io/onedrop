import {
    ChevronDown,
    CloudUpload,
    Combine,
    GitCommitHorizontal,
    Undo2,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import ProjectGitController from '@/actions/App/Http/Controllers/ProjectGitController';
import SocialProviderIcon from '@/components/social-provider-icon';
import { Dialog } from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Button } from '@/components/ui/button';
import CombineCommitsDialog from '@/components/workspace/combine-commits-dialog';
import {
    allChanges,
    DEFAULT_BRANCHES,
    gitChanged,
    onGitChanged,
} from '@/components/workspace/git-state';
import type { GitState } from '@/components/workspace/git-state';
import PullRequestDialog from '@/components/workspace/pull-request-dialog';
import { openForCommit } from '@/components/workspace/source-control-view';
import UndoCommitDialog from '@/components/workspace/undo-commit-dialog';
import { jsonRequest } from '@/lib/json-request';
import { openWorkspaceTool } from '@/lib/workspace-view';

type OpenDialog =
    | { kind: 'pull-request' }
    | { kind: 'combine' }
    | { kind: 'undo' };

/**
 * Commit, push and open a pull request from the header (GIT-006), next to Share. Committing opens the Source Control
 * tab (SCM-001), with the cursor in its message box.
 */
export default function GitActionsMenu({
    projectId,
    running,
    working,
}: {
    projectId: number;
    running: boolean;
    /** The agent is running a task: it commits when it finishes. */
    working: boolean;
}) {
    const [git, setGit] = useState<GitState | null>(null);
    const [dialog, setDialog] = useState<OpenDialog | null>(null);
    const pendingDialog = useRef<OpenDialog | null>(null);
    // The toast following a push until it's done.
    const pushToast = useRef<string | number | null>(null);

    const load = useCallback(
        () =>
            jsonRequest<GitState>(ProjectGitController.index.url(projectId))
                .then(setGit)
                .catch(() => setGit(null)),
        [projectId],
    );

    useEffect(() => {
        if (running) {
            void load();
        }
        // Reload when the agent finishes: its turn leaves changes, or a new commit.
    }, [load, running, working]);

    // Source Control changed the repository (a commit, staging): catch up.
    useEffect(
        () =>
            onGitChanged((changed) =>
                changed.status
                    ? setGit((current) =>
                          current ? { ...current, ...changed } : current,
                      )
                    : void load(),
            ),
        [load],
    );

    /** Take what a change made here returned, and tell Source Control. */
    const apply = (changed: Partial<GitState>) => {
        setGit((current) => (current ? { ...current, ...changed } : current));
        gitChanged(changed);
    };

    const syncStatus = git?.remote?.sync_status ?? null;
    const syncing = syncStatus === 'pushing' || syncStatus === 'pulling';

    useEffect(() => {
        if (!syncing) {
            return;
        }

        const timer = setInterval(load, 2000);

        return () => clearInterval(timer);
    }, [load, syncing]);

    // Say how the push went once it's done.
    useEffect(() => {
        if (pushToast.current === null || syncing) {
            return;
        }

        if (syncStatus === 'failed') {
            toast.error(git?.remote?.sync_error ?? 'The push failed.', {
                id: pushToast.current,
            });
        } else {
            toast.success('Pushed', { id: pushToast.current });
        }

        pushToast.current = null;
        gitChanged({});
    }, [git, syncStatus, syncing]);

    const pushBranch = () => {
        const id = toast.loading('Pushing…');

        return jsonRequest<Pick<GitState, 'remote'>>(
            ProjectGitController.push.url(projectId),
            {},
        )
            .then((changed) => {
                pushToast.current = id;
                apply(changed);
            })
            .catch((e: Error) => toast.error(e.message, { id }));
    };

    const status = git?.status;
    const remote = git?.remote ?? null;
    const changes = status ? allChanges(status).length : 0;
    const ahead = status?.tracking?.ahead ?? null;
    // Never pushed (no tracking yet) counts as something to push.
    const unpushed = !!status?.head && ahead !== 0;
    const busy = !running || !git || working || syncing;
    const canCommit = !!running && !!git && changes > 0;
    const canPush = !busy && !!remote && unpushed;
    const branch = status?.branch ?? null;
    const bases = (status?.branches ?? []).filter((other) => other !== branch);
    // Why a pull request can't be opened yet, or null when it can (GIT-007).
    const pullRequestProblem = !running
        ? "The sandbox isn't running"
        : remote?.host !== 'github.com'
          ? 'Needs a GitHub repository'
          : !branch || DEFAULT_BRANCHES.includes(branch) || bases.length === 0
            ? 'Commit on a new branch first'
            : ahead !== 0
              ? 'Push the branch first'
              : null;

    // More than one commit waiting to be pushed can be combined into one first (GIT-008).
    const combinable = remote ? (status?.unpushed ?? 0) : 0;
    // The last commit can be undone when it isn't pushed yet and isn't the first (GIT-010).
    const lastCommit = git?.commits[0] ?? null;
    const canUndo =
        !busy &&
        !!lastCommit &&
        (git?.commits.length ?? 0) > 1 &&
        (status?.unpushed == null || status.unpushed > 0);

    const openDialog = (next: OpenDialog) => {
        pendingDialog.current = next;
    };

    const primary = () => {
        if (changes > 0 || !canPush) {
            openForCommit();
        } else {
            void pushBranch();
        }
    };

    const title = !running
        ? 'Git works when the sandbox is running.'
        : changes > 0
          ? `Review and commit ${changes} changed ${changes === 1 ? 'file' : 'files'} in Source Control`
          : canPush
            ? 'Push the branch'
            : 'Open Source Control';

    return (
        <>
            <div className="flex" data-test="git-actions">
                <Button
                    size="sm"
                    variant="outline"
                    className="rounded-r-none border-r-0"
                    disabled={!running}
                    onClick={primary}
                    title={title}
                    data-test="git-actions-primary"
                >
                    <CloudUpload className="size-4" />
                    {/* Icon only on a phone, so the project's name has room (LAYOUT-007). */}
                    <span className="max-sm:sr-only">
                        {syncStatus === 'pushing'
                            ? 'Pushing…'
                            : remote
                              ? 'Commit & push'
                              : 'Commit'}
                    </span>
                    {changes > 0 && (
                        <span
                            className="rounded-full bg-primary px-1.5 text-[11px] leading-4 text-primary-foreground tabular-nums"
                            data-test="git-actions-count"
                        >
                            {changes}
                        </span>
                    )}
                </Button>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button
                            size="sm"
                            variant="outline"
                            className="rounded-l-none px-2"
                            aria-label="More git actions"
                            onClick={() => running && void load()}
                            data-test="git-actions-menu"
                        >
                            <ChevronDown className="size-4" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        align="end"
                        className="w-56"
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
                            disabled={!canCommit}
                            onSelect={() => openForCommit()}
                            data-test="git-actions-commit"
                        >
                            <GitCommitHorizontal className="size-4" />
                            Commit…
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            disabled={remote ? !canPush : !running}
                            onSelect={() =>
                                remote
                                    ? void pushBranch()
                                    : openWorkspaceTool('git')
                            }
                            data-test="git-actions-push"
                        >
                            <CloudUpload className="size-4" />
                            {remote ? 'Push' : 'Connect a repository…'}
                        </DropdownMenuItem>
                        {lastCommit && (
                            <DropdownMenuItem
                                disabled={!canUndo}
                                onSelect={() => openDialog({ kind: 'undo' })}
                                data-test="git-actions-undo"
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
                                    busy || (status?.staged.length ?? 0) > 0
                                }
                                onSelect={() => openDialog({ kind: 'combine' })}
                                data-test="git-actions-combine"
                            >
                                <Combine className="size-4" />
                                <span className="flex flex-col">
                                    Combine {combinable} commits
                                    {(status?.staged.length ?? 0) > 0 && (
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
                            data-test="git-actions-pr"
                        >
                            <SocialProviderIcon
                                provider="github"
                                className="size-4"
                            />
                            <span className="flex flex-col">
                                Create PR
                                {pullRequestProblem && (
                                    <span
                                        className="text-xs text-muted-foreground"
                                        data-test="git-actions-pr-problem"
                                    >
                                        {pullRequestProblem}
                                    </span>
                                )}
                            </span>
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>

            <Dialog
                open={dialog !== null}
                onOpenChange={(open) => !open && setDialog(null)}
            >
                {dialog?.kind === 'combine' && (
                    <CombineCommitsDialog
                        projectId={projectId}
                        working={working}
                        onClose={() => setDialog(null)}
                        onCombined={(changed) => {
                            apply(changed);
                            setDialog(null);
                        }}
                    />
                )}
                {dialog?.kind === 'undo' && lastCommit && (
                    <UndoCommitDialog
                        projectId={projectId}
                        commit={lastCommit}
                        onClose={() => setDialog(null)}
                        onUndone={(changed) => {
                            apply(changed);
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
        </>
    );
}
