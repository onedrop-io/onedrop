import {
    Bot,
    Check,
    ChevronDown,
    CloudUpload,
    Copy,
    Download,
    EllipsisVertical,
    GitBranch,
    Plus,
    Search,
    Undo2,
    User,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import ProjectGitController from '@/actions/App/Http/Controllers/ProjectGitController';
import SocialProviderIcon from '@/components/social-provider-icon';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { GitHubConnect } from '@/components/workspace/github-connect';
import type { GitHubInfo } from '@/components/workspace/github-connect';
import type { GitRemote, GitStatus } from '@/components/workspace/git-state';
import PatchView from '@/components/workspace/patch-view';
import UndoCommitDialog from '@/components/workspace/undo-commit-dialog';
import type { UndoResult } from '@/components/workspace/undo-commit-dialog';
import { jsonRequest } from '@/lib/json-request';
import { cn } from '@/lib/utils';

/**
 * The parts of Source Control (SCM-001) that aren't the changes: the branch menu, the remote, the history and a
 * commit's details. A commit's details also show one of the agent's checkpoints (SCM-002).
 */

export type Commit = {
    sha: string;
    subject: string;
    author: string;
    email: string;
    date: string;
    agent: boolean;
};

type Status = GitStatus;

type Remote = GitRemote;

export type Send = (
    url: string,
    body: unknown,
    method?: 'POST' | 'PUT' | 'DELETE' | 'PATCH',
) => Promise<boolean>;

/** The current branch, with a menu to switch to another or create one from the current commit. */
export function BranchBar({
    projectId,
    status,
    disabled,
    send,
}: {
    projectId: number;
    status: Status;
    disabled: boolean;
    send: Send;
}) {
    const [creating, setCreating] = useState(false);
    const [name, setName] = useState('');

    const create = (event: FormEvent) => {
        event.preventDefault();
        void send(ProjectGitController.switch.url(projectId), {
            branch: name.trim(),
            create: true,
        }).then((ok) => {
            if (ok) {
                setCreating(false);
                setName('');
            }
        });
    };

    if (creating) {
        return (
            <form onSubmit={create} className="flex min-w-0 flex-1 gap-1">
                <Input
                    autoFocus
                    value={name}
                    onChange={(event) => setName(event.target.value)}
                    onKeyDown={(event) =>
                        event.key === 'Escape' && setCreating(false)
                    }
                    placeholder="New branch name"
                    className="h-7 min-w-0 flex-1 text-sm"
                    data-test="git-branch-name"
                />
                <Button
                    type="submit"
                    size="sm"
                    className="h-7"
                    disabled={name.trim() === ''}
                    data-test="git-branch-create"
                >
                    Create
                </Button>
            </form>
        );
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="sm"
                    className="h-7 min-w-0 gap-1 px-2 font-medium"
                    disabled={disabled || !status.head}
                    title={
                        status.head
                            ? 'Switch branch'
                            : 'Branches work once the project has its first commit'
                    }
                    data-test="git-branch"
                >
                    <GitBranch className="size-4 shrink-0 text-muted-foreground" />
                    <span className="truncate">
                        {status.branch ?? (status.head ? 'Detached' : 'main')}
                    </span>
                    <ChevronDown className="size-3.5 shrink-0 text-muted-foreground" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start">
                {status.branches.map((branch) => (
                    <DropdownMenuItem
                        key={branch}
                        onSelect={() =>
                            branch !== status.branch &&
                            send(ProjectGitController.switch.url(projectId), {
                                branch,
                            })
                        }
                        data-test={`git-branch-${branch}`}
                    >
                        <Check
                            className={cn(
                                'size-4',
                                branch !== status.branch && 'invisible',
                            )}
                        />
                        {branch}
                    </DropdownMenuItem>
                ))}
                <DropdownMenuSeparator />
                <DropdownMenuItem
                    onSelect={() => setCreating(true)}
                    data-test="git-branch-new"
                >
                    <Plus className="size-4" />
                    New branch…
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/** The connected remote with push and pull, or ways to connect one. */
export function RemoteCard({
    projectId,
    remote,
    status,
    github,
    autoOpenGitHub,
    onReload,
    send,
}: {
    projectId: number;
    remote: Remote | null;
    status: Status;
    github: GitHubInfo;
    autoOpenGitHub: boolean;
    onReload: () => void;
    send: Send;
}) {
    const [form, setForm] = useState<'github' | 'existing' | null>(null);

    if (!remote) {
        return (
            <div
                className="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
                data-test="git-no-remote"
            >
                {github.configured ? (
                    <GitHubConnect
                        projectId={projectId}
                        github={github}
                        hasCommits={status.head !== null}
                        autoOpen={autoOpenGitHub}
                        onConnected={onReload}
                        onOtherHost={() => setForm('existing')}
                    />
                ) : (
                    <>
                        <p className="text-sm text-muted-foreground">
                            No remote repository yet. Push your changes to a new
                            GitHub repository, or to one you have on GitHub,
                            GitLab, Forgejo or any HTTPS git host.
                        </p>
                        <div className="mt-3 flex flex-wrap gap-2">
                            <Button
                                size="sm"
                                onClick={() => setForm('github')}
                                data-test="git-create-github"
                            >
                                Create on GitHub
                            </Button>
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => setForm('existing')}
                                data-test="git-connect-existing"
                            >
                                Connect existing
                            </Button>
                        </div>
                    </>
                )}
                {form && (
                    <RemoteForm
                        kind={form}
                        projectId={projectId}
                        send={send}
                        onDone={() => setForm(null)}
                    />
                )}
            </div>
        );
    }

    const syncing =
        remote.sync_status === 'pushing' || remote.sync_status === 'pulling';
    const ahead = status.tracking?.ahead ?? null;
    const behind = status.tracking?.behind ?? 0;

    return (
        <div
            className="space-y-3 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
            data-test="git-remote"
        >
            <div className="flex items-center gap-3">
                <div className="min-w-0 flex-1">
                    <p className="flex items-center gap-1.5 truncate text-sm font-medium">
                        {remote.github_app && (
                            <SocialProviderIcon
                                provider="github"
                                className="size-4 shrink-0"
                            />
                        )}
                        <a
                            href={remote.url.replace(/\.git$/, '')}
                            target="_blank"
                            rel="noreferrer"
                            className="underline-offset-4 hover:underline"
                        >
                            {remote.url
                                .replace(/^https:\/\//, '')
                                .replace(/\.git$/, '')}
                        </a>
                    </p>
                    <p
                        className="text-xs text-muted-foreground"
                        data-test="git-remote-state"
                    >
                        {remote.sync_status === 'pushing'
                            ? 'Pushing…'
                            : remote.sync_status === 'pulling'
                              ? 'Pulling…'
                              : ahead === null
                                ? 'Not pushed yet'
                                : ahead === 0 && behind === 0
                                  ? `Up to date${remote.synced_at ? ` · synced ${timeAgo(remote.synced_at)}` : ''}`
                                  : [
                                        ahead > 0 && `${ahead} to push`,
                                        behind > 0 && `${behind} to pull`,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                    </p>
                </div>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button
                            size="icon"
                            variant="ghost"
                            className="size-8"
                            aria-label="Remote options"
                            data-test="git-remote-menu"
                        >
                            <EllipsisVertical />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem
                            onSelect={() =>
                                send(
                                    ProjectGitController.disconnect.url(
                                        projectId,
                                    ),
                                    {},
                                    'DELETE',
                                )
                            }
                            data-test="git-disconnect"
                        >
                            Disconnect (keeps the repository)
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
            <div className="flex gap-2">
                <Button
                    size="sm"
                    disabled={syncing || !status.head}
                    onClick={() =>
                        send(ProjectGitController.push.url(projectId), {})
                    }
                    data-test="git-push"
                >
                    <CloudUpload className="size-4" />
                    Push
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    disabled={syncing || !status.head}
                    onClick={() =>
                        send(ProjectGitController.pull.url(projectId), {})
                    }
                    data-test="git-pull"
                >
                    <Download className="size-4" />
                    Pull
                </Button>
            </div>
            {remote.sync_status === 'failed' && remote.sync_error && (
                <p className="text-sm text-red-600" data-test="git-sync-error">
                    {remote.sync_error}
                </p>
            )}
        </div>
    );
}

function RemoteForm({
    kind,
    projectId,
    send,
    onDone,
}: {
    kind: 'github' | 'existing';
    projectId: number;
    send: Send;
    onDone: () => void;
}) {
    const [name, setName] = useState('');
    const [url, setUrl] = useState('');
    const [username, setUsername] = useState('');
    const [token, setToken] = useState('');
    const [isPrivate, setIsPrivate] = useState(true);
    const [saving, setSaving] = useState(false);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        setSaving(true);

        const request =
            kind === 'github'
                ? send(ProjectGitController.github.url(projectId), {
                      name: name.trim(),
                      token,
                      private: isPrivate,
                  })
                : send(
                      ProjectGitController.connect.url(projectId),
                      {
                          url: url.trim(),
                          username: username.trim() || null,
                          token,
                      },
                      'PUT',
                  );

        void request
            .then((ok) => ok && onDone())
            .finally(() => setSaving(false));
    };

    return (
        <form
            onSubmit={submit}
            className="mt-4 space-y-3 border-t border-sidebar-border/70 pt-4 dark:border-sidebar-border"
            data-test="git-remote-form"
        >
            {kind === 'github' ? (
                <Field label="Repository name">
                    <Input
                        autoFocus
                        value={name}
                        onChange={(event) => setName(event.target.value)}
                        placeholder="my-app"
                        data-test="git-repo-name"
                    />
                </Field>
            ) : (
                <>
                    <Field label="Repository URL">
                        <Input
                            autoFocus
                            value={url}
                            onChange={(event) => setUrl(event.target.value)}
                            placeholder="https://github.com/you/my-app.git"
                            data-test="git-remote-url"
                        />
                    </Field>
                    <Field label="Username (optional; some hosts need it)">
                        <Input
                            value={username}
                            onChange={(event) =>
                                setUsername(event.target.value)
                            }
                            data-test="git-remote-username"
                        />
                    </Field>
                </>
            )}
            <Field
                label={
                    kind === 'github'
                        ? 'GitHub token (with repository access)'
                        : 'Access token (can read and write the repository)'
                }
            >
                <Input
                    type="password"
                    value={token}
                    onChange={(event) => setToken(event.target.value)}
                    autoComplete="off"
                    data-test="git-remote-token"
                />
            </Field>
            {kind === 'github' && (
                <label className="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        checked={isPrivate}
                        onChange={(event) => setIsPrivate(event.target.checked)}
                    />
                    Private repository
                </label>
            )}
            <p className="text-xs text-muted-foreground">
                The token is stored encrypted by the app builder and used only
                to push and pull. It never goes into the sandbox.
            </p>
            <div className="flex gap-2">
                <Button
                    type="submit"
                    size="sm"
                    disabled={
                        saving ||
                        token === '' ||
                        (kind === 'github'
                            ? name.trim() === ''
                            : url.trim() === '')
                    }
                    data-test="git-remote-save"
                >
                    {kind === 'github' ? 'Create and push' : 'Connect'}
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    onClick={onDone}
                >
                    Cancel
                </Button>
            </div>
        </form>
    );
}

/**
 * The branch's commits, the agent's and people's, searchable, a page at a time. Clicking one shows its details beside
 * the list; its menu restores it (GIT-003), or undoes the newest (GIT-010).
 */
export function History({
    projectId,
    commits: latest,
    more: latestMore,
    head,
    undoable,
    disabled,
    selected,
    onSelect,
    send,
    onUndone,
}: {
    projectId: number;
    commits: Commit[];
    more: boolean;
    head: string | null;
    /** The newest commit can be undone (GIT-010). */
    undoable: boolean;
    disabled: boolean;
    selected: string | null;
    onSelect: (commit: Commit) => void;
    send: Send;
    onUndone: (changed: UndoResult) => void;
}) {
    const [restoring, setRestoring] = useState<Commit | null>(null);
    const [undoing, setUndoing] = useState<Commit | null>(null);
    const [query, setQuery] = useState('');
    // Search results, or older pages of the history; null shows the latest commits.
    const [page, setPage] = useState<{
        commits: Commit[];
        more: boolean;
    } | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const searching = query.trim() !== '';

    // New commits (a turn, a commit, a restore) show the latest history again, unless searching.
    useEffect(() => {
        if (!searching) {
            setPage(null);
        }
    }, [latest, searching]);

    useEffect(() => {
        if (!searching) {
            setPage(null);
            setError(null);

            return;
        }

        let cancelled = false;
        const timer = setTimeout(() => {
            setLoading(true);
            jsonRequest<{ commits: Commit[]; more: boolean }>(
                ProjectGitController.log.url(projectId, {
                    query: { query: query.trim() },
                }),
            )
                .then((found) => {
                    if (!cancelled) {
                        setPage(found);
                        setError(null);
                    }
                })
                .catch((e: Error) => !cancelled && setError(e.message))
                .finally(() => !cancelled && setLoading(false));
        }, 250);

        return () => {
            cancelled = true;
            clearTimeout(timer);
        };
    }, [projectId, query, searching]);

    const shown = page ?? { commits: latest, more: latestMore };
    const commits = shown.commits;

    const showMore = () => {
        setLoading(true);
        jsonRequest<{ commits: Commit[]; more: boolean }>(
            ProjectGitController.log.url(projectId, {
                query: {
                    ...(searching ? { query: query.trim() } : {}),
                    offset: commits.length,
                },
            }),
        )
            .then((older) =>
                setPage({
                    commits: [...commits, ...older.commits],
                    more: older.more,
                }),
            )
            .catch((e: Error) => setError(e.message))
            .finally(() => setLoading(false));
    };

    return (
        <div className="space-y-1" data-test="git-history">
            {(latest.length > 0 || searching) && (
                <div className="relative px-2 pb-1">
                    <Search className="pointer-events-none absolute top-1/2 left-4.5 size-3.5 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        type="search"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder="Search history"
                        aria-label="Search the history by message, author or commit id"
                        className="h-7 pl-7 text-sm"
                        data-test="git-history-search"
                    />
                </div>
            )}
            {error && <p className="px-3 text-sm text-red-600">{error}</p>}
            {commits.length === 0 ? (
                <p className="px-3 py-2 text-sm text-muted-foreground">
                    {searching
                        ? loading || page === null
                            ? 'Searching…'
                            : `No commits match "${query.trim()}".`
                        : 'No commits yet.'}
                </p>
            ) : (
                <ol data-test="git-commits">
                    {commits.map((commit) => (
                        <li
                            key={commit.sha}
                            className={cn(
                                'group flex items-start gap-1 pr-1 hover:bg-muted/50',
                                selected === commit.sha && 'bg-muted',
                            )}
                            data-test="git-commit-row"
                        >
                            <button
                                type="button"
                                onClick={() => onSelect(commit)}
                                aria-current={selected === commit.sha}
                                className="flex min-w-0 flex-1 items-start gap-2 px-3 py-1.5 text-left focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                data-test="git-commit-toggle"
                            >
                                <span className="mt-0.5 text-muted-foreground">
                                    {commit.agent ? (
                                        <Bot
                                            className="size-4"
                                            aria-label="Agent"
                                        />
                                    ) : (
                                        <User
                                            className="size-4"
                                            aria-label="Person"
                                        />
                                    )}
                                </span>
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-sm">
                                        {commit.subject}
                                    </span>
                                    <span className="block truncate text-xs text-muted-foreground">
                                        {commit.agent ? 'Agent' : commit.author}{' '}
                                        ·{' '}
                                        <time
                                            dateTime={commit.date}
                                            title={new Date(
                                                commit.date,
                                            ).toLocaleString()}
                                        >
                                            {timeAgo(commit.date)}
                                        </time>{' '}
                                        ·{' '}
                                        <span className="font-mono">
                                            {commit.sha.slice(0, 7)}
                                        </span>
                                    </span>
                                </span>
                            </button>
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button
                                        size="icon"
                                        variant="ghost"
                                        className="mt-1 size-7 opacity-0 group-hover:opacity-100 focus-visible:opacity-100 data-[state=open]:opacity-100"
                                        aria-label={`More options for ${commit.subject}`}
                                        data-test="git-commit-menu"
                                    >
                                        <EllipsisVertical />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    {commit.sha === head ? (
                                        // The current version: nothing to restore, but it can be undone.
                                        <DropdownMenuItem
                                            disabled={disabled || !undoable}
                                            onSelect={() => setUndoing(commit)}
                                            data-test="git-undo"
                                        >
                                            <Undo2 className="size-4" />
                                            Undo this commit
                                        </DropdownMenuItem>
                                    ) : (
                                        <DropdownMenuItem
                                            disabled={disabled}
                                            onSelect={() =>
                                                setRestoring(commit)
                                            }
                                            data-test="git-restore"
                                        >
                                            <Undo2 className="size-4" />
                                            Restore this version
                                        </DropdownMenuItem>
                                    )}
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </li>
                    ))}
                </ol>
            )}
            {shown.more && (
                <Button
                    variant="ghost"
                    size="sm"
                    className="w-full"
                    disabled={loading}
                    onClick={showMore}
                    data-test="git-history-more"
                >
                    {loading ? 'Loading…' : 'Show more'}
                </Button>
            )}

            <Dialog
                open={undoing !== null}
                onOpenChange={(isOpen) => !isOpen && setUndoing(null)}
            >
                {undoing && (
                    <UndoCommitDialog
                        projectId={projectId}
                        commit={undoing}
                        onClose={() => setUndoing(null)}
                        onUndone={(changed) => {
                            onUndone(changed);
                            setPage(null);
                            setUndoing(null);
                        }}
                    />
                )}
            </Dialog>
            {restoring && (
                <Confirm
                    title="Restore this version?"
                    description={`The app goes back to how it was at "${restoring.subject}". This is saved as a new commit, so nothing is lost: you can restore today's version the same way. Uncommitted changes are kept in the Timeline first.`}
                    action="Restore"
                    onConfirm={() =>
                        send(ProjectGitController.restore.url(projectId), {
                            sha: restoring.sha,
                        })
                    }
                    onClose={() => setRestoring(null)}
                />
            )}
        </div>
    );
}

type CommitFile = {
    path: string;
    status: string;
    additions: number | null;
    deletions: number | null;
    binary: boolean;
};

type CommitDetail = Commit & {
    body: string;
    parents: string[];
    files: CommitFile[];
    more_files: boolean;
};

export const FILE_STATUS: Record<string, string> = {
    A: 'Added',
    M: 'Modified',
    D: 'Deleted',
    T: 'Type changed',
};

/** A commit's (or a checkpoint's) full message, author, date, id and changed files, each opening its diff. */
export function CommitDetails({
    projectId,
    sha,
    actions,
}: {
    projectId: number;
    sha: string;
    /** Buttons beside the subject, e.g. restoring a checkpoint. */
    actions?: ReactNode;
}) {
    const [detail, setDetail] = useState<CommitDetail | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [file, setFile] = useState<string | null>(null);
    const [copied, setCopied] = useState(false);

    useEffect(() => {
        let cancelled = false;

        jsonRequest<{ commit: CommitDetail }>(
            ProjectGitController.show.url({ project: projectId, sha }),
        )
            .then(({ commit }) => !cancelled && setDetail(commit))
            .catch((e: Error) => !cancelled && setError(e.message));

        return () => {
            cancelled = true;
        };
    }, [projectId, sha]);

    if (error) {
        return <p className="p-4 text-sm text-red-600">{error}</p>;
    }

    if (!detail || detail.sha.slice(0, sha.length) !== sha) {
        return <p className="p-4 text-sm text-muted-foreground">Loading…</p>;
    }

    const copy = () => {
        void navigator.clipboard?.writeText(detail.sha).then(() => {
            setCopied(true);
            setTimeout(() => setCopied(false), 1500);
        });
    };

    return (
        <div className="space-y-3 p-4" data-test="git-commit-details">
            <div className="flex items-start gap-3">
                <h3
                    className="min-w-0 flex-1 text-base font-medium"
                    data-test="git-commit-subject"
                >
                    {detail.subject}
                </h3>
                {actions}
            </div>
            {detail.body && (
                <p
                    className="text-sm whitespace-pre-wrap"
                    data-test="git-commit-body"
                >
                    {detail.body}
                </p>
            )}
            <dl className="grid grid-cols-[5rem_minmax(0,1fr)] gap-x-3 gap-y-1 text-xs">
                <dt className="text-muted-foreground">Author</dt>
                <dd className="truncate">
                    {detail.agent ? 'Agent' : detail.author}{' '}
                    <span className="text-muted-foreground">
                        &lt;{detail.email}&gt;
                    </span>
                </dd>
                <dt className="text-muted-foreground">Date</dt>
                <dd>
                    {new Date(detail.date).toLocaleString(undefined, {
                        dateStyle: 'full',
                        timeStyle: 'short',
                    })}
                </dd>
                <dt className="text-muted-foreground">Commit</dt>
                <dd className="flex min-w-0 items-center gap-1">
                    <span
                        className="truncate font-mono"
                        data-test="git-commit-sha"
                    >
                        {detail.sha}
                    </span>
                    <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        className="size-6 shrink-0"
                        aria-label="Copy commit id"
                        onClick={copy}
                        data-test="git-commit-copy"
                    >
                        {copied ? (
                            <Check className="size-3.5" />
                        ) : (
                            <Copy className="size-3.5" />
                        )}
                    </Button>
                </dd>
                {detail.parents.length > 0 && (
                    <>
                        <dt className="text-muted-foreground">
                            {detail.parents.length > 1 ? 'Parents' : 'Parent'}
                        </dt>
                        <dd className="font-mono">
                            {detail.parents
                                .map((parent) => parent.slice(0, 7))
                                .join(', ')}
                        </dd>
                    </>
                )}
            </dl>

            <div className="rounded-lg border border-sidebar-border/70 dark:border-sidebar-border">
                <p className="border-b border-sidebar-border/70 px-3 py-1.5 text-xs text-muted-foreground dark:border-sidebar-border">
                    {detail.files.length === 0
                        ? 'No files changed'
                        : `${detail.files.length}${detail.more_files ? '+' : ''} ${detail.files.length === 1 ? 'file' : 'files'} changed`}
                </p>
                <ul className="divide-y" data-test="git-commit-files">
                    {detail.files.map((changed) => (
                        <li key={changed.path}>
                            <button
                                type="button"
                                onClick={() =>
                                    setFile(
                                        file === changed.path
                                            ? null
                                            : changed.path,
                                    )
                                }
                                aria-expanded={file === changed.path}
                                disabled={changed.binary}
                                className="flex w-full items-center gap-2 px-3 py-1.5 text-left text-xs hover:bg-muted/50 disabled:cursor-default disabled:hover:bg-transparent"
                                data-test="git-commit-file"
                            >
                                <span
                                    title={FILE_STATUS[changed.status]}
                                    className={cn(
                                        'w-3 font-mono',
                                        changed.status === 'D'
                                            ? 'text-red-600'
                                            : changed.status === 'A'
                                              ? 'text-green-600'
                                              : 'text-amber-600',
                                    )}
                                >
                                    {changed.status}
                                </span>
                                <span className="min-w-0 flex-1 truncate font-mono">
                                    {changed.path}
                                </span>
                                {changed.binary ? (
                                    <span className="text-muted-foreground">
                                        binary
                                    </span>
                                ) : (
                                    <span className="font-mono">
                                        <span className="text-green-600">
                                            +{changed.additions}
                                        </span>{' '}
                                        <span className="text-red-600">
                                            −{changed.deletions}
                                        </span>
                                    </span>
                                )}
                            </button>
                            {file === changed.path && (
                                <FileDiff
                                    projectId={projectId}
                                    sha={detail.sha}
                                    path={changed.path}
                                />
                            )}
                        </li>
                    ))}
                </ul>
            </div>
        </div>
    );
}

/** One file's patch in a commit, lines colored by what happened to them. */
function FileDiff({
    projectId,
    sha,
    path,
}: {
    projectId: number;
    sha: string;
    path: string;
}) {
    const [diff, setDiff] = useState<{
        patch: string;
        truncated: boolean;
    } | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        let cancelled = false;

        jsonRequest<{ patch: string; truncated: boolean }>(
            ProjectGitController.diff.url(
                { project: projectId, sha },
                { query: { path } },
            ),
        )
            .then((loaded) => !cancelled && setDiff(loaded))
            .catch((e: Error) => !cancelled && setError(e.message));

        return () => {
            cancelled = true;
        };
    }, [projectId, sha, path]);

    if (error) {
        return <p className="px-3 pb-2 text-xs text-red-600">{error}</p>;
    }

    if (!diff) {
        return (
            <p className="px-3 pb-2 text-xs text-muted-foreground">Loading…</p>
        );
    }

    return (
        <div className="border-t border-sidebar-border/70 dark:border-sidebar-border">
            <PatchView
                patch={diff.patch}
                truncated={diff.truncated}
                path={path}
                className="max-h-96"
            />
        </div>
    );
}

export function Confirm({
    title,
    description,
    action,
    onConfirm,
    onClose,
}: {
    title: string;
    description: string;
    action: string;
    onConfirm: () => Promise<boolean>;
    onClose: () => void;
}) {
    const [busy, setBusy] = useState(false);

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent data-test="git-confirm">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        autoFocus
                        disabled={busy}
                        onClick={() => {
                            setBusy(true);
                            void onConfirm().finally(onClose);
                        }}
                        data-test="git-confirm-action"
                    >
                        {action}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function Field({ label, children }: { label: string; children: ReactNode }) {
    return (
        <label className="block space-y-1 text-sm">
            <span className="font-medium">{label}</span>
            {children}
        </label>
    );
}

export function Empty({
    children,
    tone,
}: {
    children: ReactNode;
    tone?: 'error';
}) {
    return (
        <div
            className={cn(
                'rounded-xl border border-dashed border-sidebar-border p-6 text-sm',
                tone === 'error' ? 'text-red-600' : 'text-muted-foreground',
            )}
            data-test="git-empty"
        >
            {children}
        </div>
    );
}

const RELATIVE = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });

/** "3 minutes ago", "yesterday". */
export function timeAgo(iso: string): string {
    const seconds = (new Date(iso).getTime() - Date.now()) / 1000;
    const units: [Intl.RelativeTimeFormatUnit, number][] = [
        ['year', 31536000],
        ['month', 2592000],
        ['week', 604800],
        ['day', 86400],
        ['hour', 3600],
        ['minute', 60],
    ];

    for (const [unit, size] of units) {
        if (Math.abs(seconds) >= size) {
            return RELATIVE.format(Math.round(seconds / size), unit);
        }
    }

    return 'just now';
}
