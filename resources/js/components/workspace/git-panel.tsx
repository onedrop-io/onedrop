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
    RefreshCw,
    Search,
    Undo2,
    User,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import type { FormEvent, KeyboardEvent, ReactNode } from 'react';
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
import { jsonRequest } from '@/lib/json-request';
import { cn } from '@/lib/utils';

type Change = { path: string; status: string };

type Status = {
    initialized: boolean;
    branch: string | null;
    branches: string[];
    head: string | null;
    changes: Change[];
    more_changes: boolean;
    tracking: { ahead: number; behind: number } | null;
    state: 'merging' | 'rebasing' | null;
};

type Commit = {
    sha: string;
    subject: string;
    author: string;
    email: string;
    date: string;
    agent: boolean;
};

type Remote = {
    url: string;
    host: string | null;
    username: string | null;
    /** Connected through the GitHub App (no token stored). */
    github_app: boolean;
    sync_status: 'pushing' | 'pulling' | 'failed' | null;
    sync_error: string | null;
    synced_at: string | null;
};

type GitData = {
    status: Status;
    commits: Commit[];
    remote: Remote | null;
    backed_up_at: string | null;
    github: GitHubInfo;
    /** There are older commits than those listed. */
    more: boolean;
};

type Notice = { text: string; error?: boolean } | null;

const STATUS_LABELS: Record<string, string> = {
    M: 'Modified',
    A: 'Added',
    D: 'Deleted',
    R: 'Renamed',
    U: 'Conflicted',
    '?': 'New',
};

/**
 * Tools → Git: the app's branch, uncommitted changes and history (the agent commits after every turn),
 * committing, restoring an earlier version, and pushing to or pulling from GitHub, GitLab or any HTTPS git host.
 */
export default function GitPanel({
    projectId,
    running,
    working,
}: {
    projectId: number;
    running: boolean;
    /** The agent is running a task. */
    working: boolean;
}) {
    const [data, setData] = useState<GitData | null>(null);
    const [error, setError] = useState<string | null>(null);
    // Back from GitHub with a problem (`?github_error=`): show it once, and tidy the URL.
    const [notice, setNotice] = useState<Notice>(() => {
        if (typeof window === 'undefined') {
            return null;
        }

        const params = new URLSearchParams(window.location.search);
        const githubError = params.get('github_error');

        // The Tools section stays in the URL (TASK-004); only the one-time error goes.
        if (githubError) {
            params.delete('github_error');
            const query = params.toString();

            window.history.replaceState(
                window.history.state,
                '',
                window.location.pathname + (query ? `?${query}` : ''),
            );
        }

        return githubError ? { text: githubError, error: true } : null;
    });

    // Back from GitHub (`?github=connect`): open the Connect to GitHub dialog again, once.
    const [returnedFromGitHub] = useState(() => {
        if (typeof window === 'undefined') {
            return false;
        }

        const params = new URLSearchParams(window.location.search);

        if (params.get('github') !== 'connect') {
            return false;
        }

        params.delete('github');
        const query = params.toString();
        window.history.replaceState(
            window.history.state,
            '',
            window.location.pathname + (query ? `?${query}` : ''),
        );

        return true;
    });

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

    useEffect(() => {
        if (running) {
            void load();
        }
        // Reload when the agent finishes: its turn is a new commit.
    }, [load, running, working]);

    const syncing =
        data?.remote?.sync_status === 'pushing' ||
        data?.remote?.sync_status === 'pulling';

    useEffect(() => {
        if (!syncing) {
            return;
        }

        const timer = setInterval(load, 2000);

        return () => clearInterval(timer);
    }, [load, syncing]);

    if (!running) {
        return <Empty>Git works when the sandbox is running.</Empty>;
    }

    if (error && !data) {
        return <Empty tone="error">{error}</Empty>;
    }

    if (!data) {
        return <Empty>Loading…</Empty>;
    }

    /** Send a change and merge what comes back (status, commits, remote) into the panel. */
    const send = (
        url: string,
        body: unknown,
        method: 'POST' | 'PUT' | 'DELETE' = 'POST',
    ) =>
        jsonRequest<Partial<GitData>>(url, body, method)
            .then((changed) => {
                setData((current) =>
                    current ? { ...current, ...changed } : current,
                );
                setNotice(null);

                return true;
            })
            .catch((e: Error) => {
                setNotice({ text: e.message, error: true });

                return false;
            });

    return (
        <div className="max-w-3xl space-y-6" data-test="git-panel">
            <BranchBar
                projectId={projectId}
                status={data.status}
                disabled={working}
                send={send}
                onRefresh={load}
            />

            {data.github.problems.length > 0 && (
                <div
                    className="space-y-2 rounded-xl border border-amber-500/40 bg-amber-500/5 p-4 text-sm"
                    data-test="git-github-problems"
                >
                    <p className="font-medium">
                        The GitHub App needs attention (only admins see this)
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

            {notice && (
                <p
                    className={cn(
                        'text-sm',
                        notice.error ? 'text-red-600' : 'text-muted-foreground',
                    )}
                    data-test="git-notice"
                >
                    {notice.text}
                </p>
            )}

            {working && (
                <p
                    className="text-sm text-muted-foreground"
                    data-test="git-working"
                >
                    The agent is working. It commits its changes when it
                    finishes.
                </p>
            )}

            {data.status.state && (
                <p className="text-sm text-amber-600" data-test="git-state">
                    The repository is in the middle of a{' '}
                    {data.status.state === 'merging' ? 'merge' : 'rebase'}. Ask
                    the agent to finish it, or use the Shell.
                </p>
            )}

            <RemoteCard
                projectId={projectId}
                remote={data.remote}
                status={data.status}
                github={data.github}
                autoOpenGitHub={returnedFromGitHub && !notice?.error}
                onReload={load}
                send={send}
            />

            <CommitCard
                projectId={projectId}
                status={data.status}
                disabled={working}
                send={send}
            />

            <History
                projectId={projectId}
                commits={data.commits}
                more={data.more}
                head={data.status.head}
                disabled={working}
                send={send}
            />

            <p className="text-xs text-muted-foreground">
                The agent commits everything after each turn, and the history is
                backed up outside the sandbox
                {data.backed_up_at
                    ? ` (last backup ${timeAgo(data.backed_up_at)})`
                    : ''}
                . Dependencies, caches and{' '}
                <code className="font-mono">.env</code> secrets are never
                committed.
            </p>
        </div>
    );
}

type Send = (
    url: string,
    body: unknown,
    method?: 'POST' | 'PUT' | 'DELETE',
) => Promise<boolean>;

/** The current branch, a menu to switch or create one, and refresh. */
function BranchBar({
    projectId,
    status,
    disabled,
    send,
    onRefresh,
}: {
    projectId: number;
    status: Status;
    disabled: boolean;
    send: Send;
    onRefresh: () => void;
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

    return (
        <div className="flex items-center gap-2 border-b border-sidebar-border/70 pb-3 dark:border-sidebar-border">
            <GitBranch className="size-4 text-muted-foreground" />
            {creating ? (
                <form onSubmit={create} className="flex flex-1 gap-2">
                    <Input
                        autoFocus
                        value={name}
                        onChange={(event) => setName(event.target.value)}
                        placeholder="New branch name"
                        className="h-8 max-w-xs"
                        data-test="git-branch-name"
                    />
                    <Button
                        type="submit"
                        size="sm"
                        disabled={name.trim() === ''}
                        data-test="git-branch-create"
                    >
                        Create
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={() => setCreating(false)}
                    >
                        Cancel
                    </Button>
                </form>
            ) : (
                <>
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                variant="ghost"
                                size="sm"
                                className="gap-1 font-medium"
                                disabled={disabled || !status.head}
                                data-test="git-branch"
                            >
                                {status.branch ??
                                    (status.head ? 'Detached' : 'main')}
                                <ChevronDown className="size-4 text-muted-foreground" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="start">
                            {status.branches.map((branch) => (
                                <DropdownMenuItem
                                    key={branch}
                                    onSelect={() =>
                                        branch !== status.branch &&
                                        send(
                                            ProjectGitController.switch.url(
                                                projectId,
                                            ),
                                            { branch },
                                        )
                                    }
                                    data-test={`git-branch-${branch}`}
                                >
                                    <Check
                                        className={cn(
                                            'size-4',
                                            branch !== status.branch &&
                                                'invisible',
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
                    <div className="flex-1" />
                    <Button
                        variant="ghost"
                        size="icon"
                        className="size-8"
                        aria-label="Refresh"
                        onClick={onRefresh}
                        data-test="git-refresh"
                    >
                        <RefreshCw className="size-4" />
                    </Button>
                </>
            )}
        </div>
    );
}

/** The connected remote with push and pull, or ways to connect one. */
function RemoteCard({
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

/** Uncommitted changes, discarding them, and committing them with a message. */
function CommitCard({
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
    const [message, setMessage] = useState('');
    const [committing, setCommitting] = useState(false);
    // The file whose changes to discard, or null for all of them. (A path can't mean "all": a file may be named that.)
    const [confirmDiscard, setConfirmDiscard] = useState<{
        path: string | null;
    } | null>(null);
    const count = status.changes.length;

    const commit = (event?: FormEvent) => {
        event?.preventDefault();

        if (message.trim() === '' || count === 0) {
            return;
        }

        setCommitting(true);
        void send(ProjectGitController.commit.url(projectId), { message })
            .then((ok) => ok && setMessage(''))
            .finally(() => setCommitting(false));
    };

    const onKeyDown = (event: KeyboardEvent) => {
        if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) {
            commit();
        }
    };

    return (
        <section className="space-y-3" data-test="git-commit">
            <h3 className="text-sm font-medium">Commit</h3>
            <form onSubmit={commit} className="space-y-3">
                <Input
                    value={message}
                    onChange={(event) => setMessage(event.target.value)}
                    onKeyDown={onKeyDown}
                    placeholder="Summary of your changes"
                    disabled={disabled}
                    data-test="git-message"
                />
                <div className="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <div className="flex items-center gap-2 border-b border-sidebar-border/70 px-3 py-2 dark:border-sidebar-border">
                        <p
                            className="flex-1 text-sm"
                            data-test="git-change-count"
                        >
                            {count === 0
                                ? 'No changes'
                                : `${count}${status.more_changes ? '+' : ''} changed ${count === 1 ? 'file' : 'files'}`}
                        </p>
                        {count > 0 && (
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                disabled={disabled}
                                onClick={() =>
                                    setConfirmDiscard({ path: null })
                                }
                                data-test="git-discard-all"
                            >
                                <Undo2 className="size-4" />
                                Discard all
                            </Button>
                        )}
                    </div>
                    {count > 0 && (
                        <ul
                            className="max-h-64 divide-y overflow-y-auto"
                            data-test="git-changes"
                        >
                            {status.changes.map((change) => (
                                <li
                                    key={change.path}
                                    className="flex items-center gap-2 px-3 py-1.5 text-sm"
                                    data-test="git-change"
                                >
                                    <span className="min-w-0 flex-1 truncate font-mono text-xs">
                                        {change.path}
                                    </span>
                                    <Button
                                        type="button"
                                        size="icon"
                                        variant="ghost"
                                        className="size-7"
                                        aria-label={`Discard changes to ${change.path}`}
                                        disabled={disabled}
                                        onClick={() =>
                                            setConfirmDiscard({
                                                path: change.path,
                                            })
                                        }
                                    >
                                        <Undo2 className="size-3.5" />
                                    </Button>
                                    <span
                                        title={STATUS_LABELS[change.status]}
                                        className={cn(
                                            'w-5 rounded text-center font-mono text-xs',
                                            change.status === 'D'
                                                ? 'text-red-600'
                                                : change.status === '?' ||
                                                    change.status === 'A'
                                                  ? 'text-green-600'
                                                  : 'text-amber-600',
                                        )}
                                    >
                                        {change.status === '?'
                                            ? 'U'
                                            : change.status === 'U'
                                              ? '!'
                                              : change.status}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
                <Button
                    type="submit"
                    className="w-full"
                    disabled={
                        disabled ||
                        committing ||
                        count === 0 ||
                        message.trim() === ''
                    }
                    data-test="git-commit-button"
                >
                    <Check className="size-4" />
                    Commit all changes
                </Button>
            </form>

            {confirmDiscard && (
                <Confirm
                    title={
                        confirmDiscard.path === null
                            ? 'Discard all changes?'
                            : 'Discard changes to this file?'
                    }
                    description={
                        confirmDiscard.path === null
                            ? "Every file goes back to the last commit, and new files are deleted. This can't be undone."
                            : `${confirmDiscard.path} goes back to the last commit (or is deleted if it's new). This can't be undone.`
                    }
                    action="Discard"
                    onConfirm={() =>
                        send(ProjectGitController.discard.url(projectId), {
                            path: confirmDiscard.path,
                        })
                    }
                    onClose={() => setConfirmDiscard(null)}
                />
            )}
        </section>
    );
}

/** Recent commits, the agent's and people's, each restorable. */
function History({
    projectId,
    commits: latest,
    more: latestMore,
    head,
    disabled,
    send,
}: {
    projectId: number;
    commits: Commit[];
    more: boolean;
    head: string | null;
    disabled: boolean;
    send: Send;
}) {
    const [restoring, setRestoring] = useState<Commit | null>(null);
    const [open, setOpen] = useState<string | null>(null);
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
        <section className="space-y-3" data-test="git-history">
            <div className="flex items-center gap-3">
                <h3 className="flex-1 text-sm font-medium">History</h3>
                {(latest.length > 0 || searching) && (
                    <div className="relative w-56 max-w-[60%]">
                        <Search className="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            type="search"
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            placeholder="Search history"
                            aria-label="Search the history by message, author or commit id"
                            className="h-8 pl-8 text-sm"
                            data-test="git-history-search"
                        />
                    </div>
                )}
            </div>
            {error && <p className="text-sm text-red-600">{error}</p>}
            {commits.length === 0 ? (
                searching ? (
                    <Empty>
                        {loading || page === null
                            ? 'Searching…'
                            : `No commits match "${query.trim()}".`}
                    </Empty>
                ) : (
                    <Empty>
                        No commits yet. The agent commits its changes after each
                        turn.
                    </Empty>
                )
            ) : (
                <ol className="space-y-1" data-test="git-commits">
                    {commits.map((commit) => (
                        <li
                            key={commit.sha}
                            className="group rounded-lg hover:bg-muted/50"
                            data-test="git-commit-row"
                        >
                            <div className="flex items-start gap-1 pr-2">
                                <button
                                    type="button"
                                    onClick={() =>
                                        setOpen(
                                            open === commit.sha
                                                ? null
                                                : commit.sha,
                                        )
                                    }
                                    aria-expanded={open === commit.sha}
                                    className="flex min-w-0 flex-1 items-start gap-3 rounded-lg px-2 py-2 text-left focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
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
                                        <span className="block text-xs text-muted-foreground">
                                            {commit.agent
                                                ? 'Agent'
                                                : commit.author}{' '}
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
                                    <ChevronDown
                                        className={cn(
                                            'mt-0.5 size-4 shrink-0 text-muted-foreground transition-transform',
                                            open === commit.sha && 'rotate-180',
                                        )}
                                    />
                                </button>
                                {commit.sha === head ? (
                                    // The current version: nothing to restore, but keep the column so the arrows line up.
                                    <span
                                        aria-hidden
                                        className="mt-1.5 size-7 shrink-0"
                                    />
                                ) : (
                                    <DropdownMenu>
                                        <DropdownMenuTrigger asChild>
                                            <Button
                                                size="icon"
                                                variant="ghost"
                                                className="mt-1.5 size-7 opacity-0 group-hover:opacity-100 focus-visible:opacity-100 data-[state=open]:opacity-100"
                                                aria-label={`More options for ${commit.subject}`}
                                                data-test="git-commit-menu"
                                            >
                                                <EllipsisVertical />
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end">
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
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                )}
                            </div>
                            {open === commit.sha && (
                                <CommitDetails
                                    projectId={projectId}
                                    sha={commit.sha}
                                />
                            )}
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

            {restoring && (
                <Confirm
                    title="Restore this version?"
                    description={`The app goes back to how it was at "${restoring.subject}". This is saved as a new commit, so nothing is lost: you can restore today's version the same way. Uncommitted changes are committed first.`}
                    action="Restore"
                    onConfirm={() =>
                        send(ProjectGitController.restore.url(projectId), {
                            sha: restoring.sha,
                        })
                    }
                    onClose={() => setRestoring(null)}
                />
            )}
        </section>
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

const FILE_STATUS: Record<string, string> = {
    A: 'Added',
    M: 'Modified',
    D: 'Deleted',
    T: 'Type changed',
};

/** A commit's full message, author, date, id and changed files, each opening its diff. */
function CommitDetails({ projectId, sha }: { projectId: number; sha: string }) {
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
        return <p className="pr-3 pb-3 pl-9 text-sm text-red-600">{error}</p>;
    }

    if (!detail) {
        return (
            <p className="pr-3 pb-3 pl-9 text-sm text-muted-foreground">
                Loading…
            </p>
        );
    }

    const copy = () => {
        void navigator.clipboard?.writeText(detail.sha).then(() => {
            setCopied(true);
            setTimeout(() => setCopied(false), 1500);
        });
    };

    return (
        <div
            className="space-y-3 pr-3 pb-3 pl-9"
            data-test="git-commit-details"
        >
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

    // Skip git's header lines (diff --git, index, ---, +++); the hunks start at the first @@.
    const lines = diff.patch.split('\n');
    const firstHunk = lines.findIndex((line) => line.startsWith('@@'));
    const body = (firstHunk === -1 ? lines : lines.slice(firstHunk)).filter(
        (line, index, all) => index < all.length - 1 || line !== '',
    );

    return (
        <div className="border-t border-sidebar-border/70 dark:border-sidebar-border">
            <pre
                className="max-h-96 overflow-auto bg-muted/30 py-1 font-mono text-xs leading-5"
                data-test="git-diff"
            >
                {body.length === 0 ? (
                    <span className="px-3 text-muted-foreground">
                        No text changes (e.g. only permissions changed).
                    </span>
                ) : (
                    body.map((line, index) => (
                        <div
                            key={index}
                            className={cn(
                                'px-3 whitespace-pre',
                                line.startsWith('@@')
                                    ? 'text-sky-600 dark:text-sky-400'
                                    : line.startsWith('+')
                                      ? 'bg-green-500/10 text-green-700 dark:text-green-400'
                                      : line.startsWith('-')
                                        ? 'bg-red-500/10 text-red-700 dark:text-red-400'
                                        : 'text-muted-foreground',
                            )}
                        >
                            {line || ' '}
                        </div>
                    ))
                )}
            </pre>
            {diff.truncated && (
                <p className="px-3 py-1.5 text-xs text-muted-foreground">
                    This diff is too large to show in full.
                </p>
            )}
        </div>
    );
}

function Confirm({
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

function Empty({ children, tone }: { children: ReactNode; tone?: 'error' }) {
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
function timeAgo(iso: string): string {
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
