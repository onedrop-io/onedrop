import { router } from '@inertiajs/react';
import {
    ArrowLeft,
    CircleCheck,
    CircleDashed,
    CircleX,
    ExternalLink,
    GitBranch,
    GitPullRequest,
    GitPullRequestArrow,
    MessageSquare,
    RefreshCw,
    Search,
    Wrench,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import ProjectPullRequestController from '@/actions/App/Http/Controllers/ProjectPullRequestController';
import Markdown from '@/components/markdown';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import PatchView from '@/components/workspace/patch-view';
import { jsonRequest } from '@/lib/json-request';
import { cn } from '@/lib/utils';
import { openWorkspaceTool } from '@/lib/workspace-view';

export type ChecksState = 'success' | 'failure' | 'pending' | null;

type Person = { login: string; avatar_url: string | null } | null;

type PullSummary = {
    number: number;
    title: string;
    state: 'open' | 'draft' | 'closed' | 'merged';
    url: string;
    updated_at: string;
    head: string;
    base: string;
    author: Person;
    fork: boolean;
    comments: number;
    review: 'approved' | 'changes_requested' | 'review_required' | null;
    checks: ChecksState;
};

type ListData = {
    available: boolean;
    repository: string | null;
    pulls: PullSummary[];
    /** Task ids by pull request number, for those checked out as tasks. */
    tasks: Record<string, number>;
};

type Pull = {
    number: number;
    title: string;
    body: string;
    state: PullSummary['state'];
    url: string;
    author: Person;
    created_at: string | null;
    updated_at: string | null;
    head: string;
    head_sha: string;
    base: string;
    fork: boolean;
    mergeable: boolean | null;
    mergeable_state: string | null;
    commits: number;
    additions: number;
    deletions: number;
    changed_files: number;
    comments: number;
};

type Entry = {
    kind: 'comment' | 'review' | 'line';
    author: Person;
    body: string;
    state: string | null;
    path: string | null;
    line: number | null;
    created_at: string | null;
    url: string | null;
};

type Check = {
    id: string;
    name: string;
    state: string;
    actions: boolean;
    started_at: string | null;
    completed_at: string | null;
    url: string | null;
    summary: string | null;
};

type PullTask = {
    id: number;
    title: string;
    url: string;
    status: string;
    checks: ChecksState;
};

type PullData = {
    pull: Pull;
    conversation: Entry[];
    checks: Check[];
    checks_state: ChecksState;
    task: PullTask | null;
    check_out_problem: string | null;
};

type Commit = {
    sha: string;
    message: string;
    author: string;
    date: string | null;
    url: string;
};

type ChangedFile = {
    path: string;
    previous_path: string | null;
    status: string;
    additions: number;
    deletions: number;
    binary: boolean;
    patch: string;
    truncated: boolean;
};

const OPEN_PULL_EVENT = 'workspace:open-pull';
/** How often running checks are looked at again while the page is open, in milliseconds. */
const CHECKS_REFRESH = 15_000;
const FAILED = [
    'failure',
    'timed_out',
    'cancelled',
    'action_required',
    'startup_failure',
    'stale',
];
const RUNNING = ['queued', 'in_progress', 'pending', 'waiting', 'requested'];

/** Show a pull request's page in Tools → Pull requests (e.g. from its task's bar). */
export function openPullRequest(number: number): void {
    setPullInUrl(number);
    openWorkspaceTool('pulls');
    window.dispatchEvent(
        new CustomEvent<number>(OPEN_PULL_EVENT, { detail: number }),
    );
}

/** The open pull request lives in the URL beside the Tools section (`?tab=tools&tool=pulls&pr=12`, GIT-013). */
function setPullInUrl(number: number | null): void {
    const url = new URL(window.location.href);

    if (number) {
        url.searchParams.set('pr', String(number));
    } else {
        url.searchParams.delete('pr');
    }

    if (url.href !== window.location.href) {
        window.history.replaceState(window.history.state, '', url.href);
    }
}

/**
 * Tools → Pull requests (GIT-013): the GitHub repository's pull requests, and a page for each with its conversation,
 * commits, files and checks, from which it's checked out as a task (GIT-014) or its failed checks fixed (GIT-015).
 */
export default function PullRequestsPanel({
    projectId,
}: {
    projectId: number;
}) {
    const [number, setNumber] = useState<number | null>(() => {
        if (typeof window === 'undefined') {
            return null;
        }

        const asked = Number(
            new URLSearchParams(window.location.search).get('pr'),
        );

        return asked > 0 ? asked : null;
    });

    useEffect(() => {
        const listener = (event: Event) =>
            setNumber((event as CustomEvent<number>).detail);

        window.addEventListener(OPEN_PULL_EVENT, listener);

        return () => window.removeEventListener(OPEN_PULL_EVENT, listener);
    }, []);

    // The URL follows the open pull request; leaving the section takes it out.
    useEffect(() => setPullInUrl(number), [number]);
    useEffect(() => () => setPullInUrl(null), []);

    return number ? (
        <PullRequestPage
            key={number}
            projectId={projectId}
            number={number}
            onBack={() => setNumber(null)}
        />
    ) : (
        <PullRequestList projectId={projectId} onOpen={setNumber} />
    );
}

function PullRequestList({
    projectId,
    onOpen,
}: {
    projectId: number;
    onOpen: (number: number) => void;
}) {
    const [closed, setClosed] = useState(false);
    const [data, setData] = useState<ListData | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [loading, setLoading] = useState(true);
    const [query, setQuery] = useState('');

    const load = useCallback(() => {
        setLoading(true);
        setError(null);
        jsonRequest<ListData>(
            ProjectPullRequestController.index.url(projectId, {
                query: closed ? { closed: 1 } : {},
            }),
        )
            .then(setData)
            .catch((e: Error) => setError(e.message))
            .finally(() => setLoading(false));
    }, [projectId, closed]);

    useEffect(load, [load]);

    const shown = useMemo(() => {
        const words = query.trim().toLowerCase();

        return (data?.pulls ?? []).filter(
            (pull) =>
                !words ||
                [
                    pull.title,
                    `#${pull.number}`,
                    pull.head,
                    pull.base,
                    pull.author?.login ?? '',
                ].some((text) => text.toLowerCase().includes(words)),
        );
    }, [data, query]);

    if (data && !data.available) {
        return (
            <div
                className="max-w-xl rounded-xl border border-dashed border-sidebar-border p-6 text-sm text-muted-foreground"
                data-test="pulls-unavailable"
            >
                <p>
                    Connect a GitHub repository to see its pull requests here,
                    check them out as tasks, and fix their failing checks.
                </p>
                <Button
                    variant="outline"
                    size="sm"
                    className="mt-3"
                    onClick={() => openWorkspaceTool('git')}
                >
                    <GitBranch />
                    Open Git
                </Button>
            </div>
        );
    }

    return (
        <div className="max-w-3xl space-y-3" data-test="pulls-list">
            <div className="flex flex-wrap items-center gap-2">
                <div className="flex rounded-md border border-sidebar-border/70 p-0.5 text-sm dark:border-sidebar-border">
                    {[false, true].map((value) => (
                        <button
                            key={String(value)}
                            type="button"
                            onClick={() => setClosed(value)}
                            aria-pressed={closed === value}
                            className={cn(
                                'rounded px-2.5 py-1',
                                closed === value
                                    ? 'bg-muted font-medium'
                                    : 'text-muted-foreground hover:text-foreground',
                            )}
                            data-test={value ? 'pulls-closed' : 'pulls-open'}
                        >
                            {value ? 'Closed' : 'Open'}
                        </button>
                    ))}
                </div>
                <div className="relative min-w-48 flex-1">
                    <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder="Search by title, number, branch or author"
                        className="h-8 pl-8"
                        data-test="pulls-search"
                    />
                </div>
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={load}
                    disabled={loading}
                    title="Refresh"
                >
                    <RefreshCw className={cn(loading && 'animate-spin')} />
                </Button>
            </div>

            {data?.repository && (
                <p className="text-xs text-muted-foreground">
                    {data.repository}
                </p>
            )}

            {error && <ErrorNote>{error}</ErrorNote>}

            {!data && loading ? (
                <p className="text-sm text-muted-foreground">Loading…</p>
            ) : shown.length === 0 && !error ? (
                <p
                    className="rounded-xl border border-dashed border-sidebar-border p-6 text-sm text-muted-foreground"
                    data-test="pulls-empty"
                >
                    {query
                        ? 'No pull requests match.'
                        : closed
                          ? 'No closed pull requests.'
                          : 'No open pull requests.'}
                </p>
            ) : (
                <ul className="divide-y rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    {shown.map((pull) => (
                        <li key={pull.number}>
                            <button
                                type="button"
                                onClick={() => onOpen(pull.number)}
                                className="flex w-full items-start gap-3 px-3 py-2.5 text-left hover:bg-muted/50"
                                data-test="pull-row"
                            >
                                <PullStateIcon state={pull.state} />
                                <span className="min-w-0 flex-1">
                                    <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
                                        <span className="font-medium">
                                            {pull.title}
                                        </span>
                                        {pull.state === 'draft' && (
                                            <Badge>Draft</Badge>
                                        )}
                                        {pull.review && (
                                            <ReviewBadge review={pull.review} />
                                        )}
                                        {data?.tasks[pull.number] && (
                                            <Badge tone="blue">Task</Badge>
                                        )}
                                    </span>
                                    <span className="mt-0.5 block truncate text-xs text-muted-foreground">
                                        #{pull.number}
                                        {pull.author &&
                                            ` by ${pull.author.login}`}{' '}
                                        · <code>{pull.head}</code> into{' '}
                                        <code>{pull.base}</code>
                                        {pull.fork && ' (fork)'} · updated{' '}
                                        {timeAgo(pull.updated_at)}
                                    </span>
                                </span>
                                <span className="flex shrink-0 items-center gap-3 text-xs text-muted-foreground">
                                    {pull.comments > 0 && (
                                        <span
                                            className="flex items-center gap-1"
                                            title={`${pull.comments} comments`}
                                        >
                                            <MessageSquare className="size-3.5" />
                                            {pull.comments}
                                        </span>
                                    )}
                                    <ChecksIcon state={pull.checks} />
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

type PageTab = 'conversation' | 'commits' | 'files' | 'checks';

function PullRequestPage({
    projectId,
    number,
    onBack,
}: {
    projectId: number;
    number: number;
    onBack: () => void;
}) {
    const ids = { project: projectId, number };
    const [data, setData] = useState<PullData | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [tab, setTab] = useState<PageTab>('conversation');
    const [busy, setBusy] = useState<'check-out' | 'fix' | null>(null);

    useEffect(() => {
        jsonRequest<PullData>(ProjectPullRequestController.show.url(ids))
            .then(setData)
            .catch((e: Error) => setError(e.message));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [projectId, number]);

    // Running checks are looked at again while the page is open.
    const pending = data?.checks_state === 'pending';

    useEffect(() => {
        if (!pending) {
            return;
        }

        const timer = window.setInterval(() => {
            jsonRequest<{ checks: Check[]; checks_state: ChecksState }>(
                ProjectPullRequestController.checks.url(ids),
            )
                .then((fresh) =>
                    setData((current) =>
                        current ? { ...current, ...fresh } : current,
                    ),
                )
                .catch(() => {});
        }, CHECKS_REFRESH);

        return () => window.clearInterval(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [pending, projectId, number]);

    const go = (action: 'check-out' | 'fix') => {
        setBusy(action);
        setError(null);
        jsonRequest<{ url: string }>(
            action === 'fix'
                ? ProjectPullRequestController.fix.url(ids)
                : ProjectPullRequestController.checkOut.url(ids),
            {},
        )
            // The task's page opens on this pull request's page in Tools, beside its chat.
            .then(({ url }) =>
                router.visit(`${url}?tab=tools&tool=pulls&pr=${number}`),
            )
            .catch((e: Error) => {
                setError(e.message);
                setBusy(null);
            });
    };

    const back = (
        <button
            type="button"
            onClick={onBack}
            className="flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
            data-test="pull-back"
        >
            <ArrowLeft className="size-4" />
            All pull requests
        </button>
    );

    if (!data) {
        return (
            <div className="max-w-3xl space-y-3">
                {back}
                {error ? (
                    <ErrorNote>{error}</ErrorNote>
                ) : (
                    <p className="text-sm text-muted-foreground">Loading…</p>
                )}
            </div>
        );
    }

    const { pull, task } = data;
    const failed = data.checks_state === 'failure';

    return (
        <div className="max-w-3xl space-y-4" data-test="pull-page">
            {back}

            <div className="space-y-2">
                <h3 className="text-lg font-medium" data-test="pull-title">
                    {pull.title}{' '}
                    <span className="font-normal text-muted-foreground">
                        #{pull.number}
                    </span>
                </h3>
                <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                    <StateBadge state={pull.state} />
                    {pull.author && <Avatar person={pull.author} />}
                    <span>
                        {pull.author?.login ?? 'Someone'} wants to merge{' '}
                        {pull.commits === 1
                            ? '1 commit'
                            : `${pull.commits} commits`}{' '}
                        into <code className="text-xs">{pull.base}</code> from{' '}
                        <code className="text-xs">{pull.head}</code>
                        {pull.fork && ' (a fork)'}
                    </span>
                </div>
                <p className="text-xs text-muted-foreground">
                    <span className="text-green-600">+{pull.additions}</span>{' '}
                    <span className="text-red-600">−{pull.deletions}</span> in{' '}
                    {pull.changed_files === 1
                        ? '1 file'
                        : `${pull.changed_files} files`}
                    {pull.state === 'open' || pull.state === 'draft' ? (
                        <>
                            {' · '}
                            {pull.mergeable === null
                                ? 'GitHub is checking whether it can be merged'
                                : pull.mergeable
                                  ? 'No conflicts with the base branch'
                                  : 'Has conflicts with the base branch'}
                        </>
                    ) : null}
                </p>
            </div>

            <div className="flex flex-wrap items-center gap-2">
                {task ? (
                    <Button
                        size="sm"
                        onClick={() =>
                            router.visit(
                                `${task.url}?tab=tools&tool=pulls&pr=${number}`,
                            )
                        }
                        data-test="pull-open-task"
                    >
                        <GitPullRequestArrow />
                        Open task
                    </Button>
                ) : (
                    <Button
                        size="sm"
                        onClick={() => go('check-out')}
                        disabled={!!data.check_out_problem || busy !== null}
                        title={data.check_out_problem ?? undefined}
                        data-test="pull-check-out"
                    >
                        <GitPullRequestArrow />
                        {busy === 'check-out'
                            ? 'Checking out…'
                            : 'Check out as task'}
                    </Button>
                )}
                {failed && (
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => go('fix')}
                        disabled={
                            busy !== null || (!task && !!data.check_out_problem)
                        }
                        title="Send the failed checks and their logs to the task's agent to fix"
                        data-test="pull-fix"
                    >
                        <Wrench />
                        {busy === 'fix' ? 'Sending…' : 'Fix failing checks'}
                    </Button>
                )}
                <Button size="sm" variant="ghost" asChild>
                    <a href={pull.url} target="_blank" rel="noreferrer">
                        <ExternalLink />
                        Open on GitHub
                    </a>
                </Button>
            </div>
            {!task && data.check_out_problem && (
                <p className="text-xs text-muted-foreground">
                    {data.check_out_problem}
                </p>
            )}
            {error && <ErrorNote>{error}</ErrorNote>}

            <div
                role="tablist"
                className="flex gap-1 overflow-x-auto border-b border-sidebar-border/70 text-sm dark:border-sidebar-border"
            >
                {(
                    [
                        ['conversation', 'Conversation', pull.comments],
                        ['commits', 'Commits', pull.commits],
                        ['files', 'Files changed', pull.changed_files],
                        ['checks', 'Checks', null],
                    ] as [PageTab, string, number | null][]
                ).map(([id, label, count]) => (
                    <button
                        key={id}
                        type="button"
                        role="tab"
                        aria-selected={tab === id}
                        onClick={() => setTab(id)}
                        className={cn(
                            '-mb-px flex shrink-0 items-center gap-1.5 border-b-2 px-3 py-1.5 whitespace-nowrap',
                            tab === id
                                ? 'border-primary font-medium'
                                : 'border-transparent text-muted-foreground hover:text-foreground',
                        )}
                        data-test={`pull-tab-${id}`}
                    >
                        {label}
                        {id === 'checks' ? (
                            <ChecksIcon state={data.checks_state} />
                        ) : (
                            count !== null && (
                                <span className="rounded-full bg-muted px-1.5 text-xs">
                                    {count}
                                </span>
                            )
                        )}
                    </button>
                ))}
            </div>

            {tab === 'conversation' ? (
                <Conversation pull={pull} entries={data.conversation} />
            ) : tab === 'commits' ? (
                <Commits projectId={projectId} number={number} />
            ) : tab === 'files' ? (
                <Files projectId={projectId} number={number} />
            ) : (
                <Checks
                    projectId={projectId}
                    number={number}
                    checks={data.checks}
                />
            )}
        </div>
    );
}

function Conversation({ pull, entries }: { pull: Pull; entries: Entry[] }) {
    return (
        <ol className="space-y-3" data-test="pull-conversation">
            <ConversationEntry
                author={pull.author}
                at={pull.created_at}
                label="opened it"
            >
                {pull.body.trim() ? (
                    <Markdown content={pull.body} />
                ) : (
                    <p className="text-muted-foreground italic">
                        No description.
                    </p>
                )}
            </ConversationEntry>
            {entries.map((entry, index) => (
                <ConversationEntry
                    key={index}
                    author={entry.author}
                    at={entry.created_at}
                    label={
                        entry.kind === 'review'
                            ? (REVIEW_STATES[entry.state ?? ''] ?? 'reviewed')
                            : entry.kind === 'line'
                              ? `commented on ${entry.path}${entry.line ? `:${entry.line}` : ''}`
                              : 'commented'
                    }
                    tone={
                        entry.state === 'approved'
                            ? 'green'
                            : entry.state === 'changes_requested'
                              ? 'red'
                              : undefined
                    }
                >
                    {entry.body.trim() && <Markdown content={entry.body} />}
                </ConversationEntry>
            ))}
        </ol>
    );
}

const REVIEW_STATES: Record<string, string> = {
    approved: 'approved these changes',
    changes_requested: 'requested changes',
    commented: 'reviewed',
    dismissed: 'reviewed (dismissed)',
};

function ConversationEntry({
    author,
    at,
    label,
    tone,
    children,
}: {
    author: Person;
    at: string | null;
    label: string;
    tone?: 'green' | 'red';
    children: ReactNode;
}) {
    return (
        <li
            className={cn(
                'rounded-xl border border-sidebar-border/70 dark:border-sidebar-border',
                tone === 'green' && 'border-green-600/40',
                tone === 'red' && 'border-red-600/40',
            )}
            data-test="pull-entry"
        >
            <p className="flex items-center gap-2 border-b border-sidebar-border/70 px-3 py-1.5 text-xs text-muted-foreground dark:border-sidebar-border">
                {author && <Avatar person={author} />}
                <span className="font-medium text-foreground">
                    {author?.login ?? 'Someone'}
                </span>
                {label}
                {at && <span>· {timeAgo(at)}</span>}
            </p>
            {children && (
                <div className="px-3 py-2 text-sm [&>*]:min-w-0">
                    {children}
                </div>
            )}
        </li>
    );
}

function Commits({ projectId, number }: { projectId: number; number: number }) {
    const [commits, setCommits] = useState<Commit[] | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        jsonRequest<{ commits: Commit[] }>(
            ProjectPullRequestController.commits.url({
                project: projectId,
                number,
            }),
        )
            .then((data) => setCommits(data.commits))
            .catch((e: Error) => setError(e.message));
    }, [projectId, number]);

    if (error) {
        return <ErrorNote>{error}</ErrorNote>;
    }

    if (!commits) {
        return <p className="text-sm text-muted-foreground">Loading…</p>;
    }

    return (
        <ul
            className="divide-y rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
            data-test="pull-commits"
        >
            {commits.map((commit) => (
                <li
                    key={commit.sha}
                    className="flex items-start gap-3 px-3 py-2 text-sm"
                >
                    <span className="min-w-0 flex-1">
                        <span className="block truncate">
                            {commit.message.split('\n')[0]}
                        </span>
                        <span className="text-xs text-muted-foreground">
                            {commit.author}
                            {commit.date && ` · ${timeAgo(commit.date)}`}
                        </span>
                    </span>
                    <a
                        href={commit.url}
                        target="_blank"
                        rel="noreferrer"
                        className="shrink-0 font-mono text-xs text-muted-foreground hover:underline"
                    >
                        {commit.sha.slice(0, 7)}
                    </a>
                </li>
            ))}
        </ul>
    );
}

const FILE_STATES: Record<string, { letter: string; label: string }> = {
    added: { letter: 'A', label: 'Added' },
    removed: { letter: 'D', label: 'Deleted' },
    modified: { letter: 'M', label: 'Modified' },
    renamed: { letter: 'R', label: 'Renamed' },
    copied: { letter: 'C', label: 'Copied' },
    changed: { letter: 'M', label: 'Changed' },
};

function Files({ projectId, number }: { projectId: number; number: number }) {
    const [files, setFiles] = useState<ChangedFile[] | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [open, setOpen] = useState<string | null>(null);

    useEffect(() => {
        jsonRequest<{ files: ChangedFile[] }>(
            ProjectPullRequestController.files.url({
                project: projectId,
                number,
            }),
        )
            .then((data) => setFiles(data.files))
            .catch((e: Error) => setError(e.message));
    }, [projectId, number]);

    if (error) {
        return <ErrorNote>{error}</ErrorNote>;
    }

    if (!files) {
        return <p className="text-sm text-muted-foreground">Loading…</p>;
    }

    return (
        <ul
            className="divide-y rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
            data-test="pull-files"
        >
            {files.map((file) => {
                const state = FILE_STATES[file.status] ?? FILE_STATES.modified;

                return (
                    <li key={file.path}>
                        <button
                            type="button"
                            onClick={() =>
                                setOpen(open === file.path ? null : file.path)
                            }
                            aria-expanded={open === file.path}
                            disabled={file.binary}
                            className="flex w-full items-center gap-2 px-3 py-1.5 text-left text-xs hover:bg-muted/50 disabled:cursor-default disabled:hover:bg-transparent"
                            data-test="pull-file"
                        >
                            <span
                                title={state.label}
                                className={cn(
                                    'w-3 font-mono',
                                    state.letter === 'D'
                                        ? 'text-red-600'
                                        : state.letter === 'A'
                                          ? 'text-green-600'
                                          : 'text-amber-600',
                                )}
                            >
                                {state.letter}
                            </span>
                            <span className="min-w-0 flex-1 truncate font-mono">
                                {file.previous_path &&
                                    `${file.previous_path} → `}
                                {file.path}
                            </span>
                            {file.binary ? (
                                <span className="text-muted-foreground">
                                    binary
                                </span>
                            ) : (
                                <span className="font-mono">
                                    <span className="text-green-600">
                                        +{file.additions}
                                    </span>{' '}
                                    <span className="text-red-600">
                                        −{file.deletions}
                                    </span>
                                </span>
                            )}
                        </button>
                        {open === file.path && (
                            <div className="border-t border-sidebar-border/70 dark:border-sidebar-border">
                                {file.patch ? (
                                    <PatchView
                                        patch={file.patch}
                                        truncated={file.truncated}
                                        path={file.path}
                                        className="max-h-[32rem]"
                                    />
                                ) : (
                                    <p className="px-3 py-2 text-xs text-muted-foreground">
                                        {file.truncated
                                            ? 'This diff is too large to show here. Open it on GitHub.'
                                            : 'No changes to show.'}
                                    </p>
                                )}
                            </div>
                        )}
                    </li>
                );
            })}
        </ul>
    );
}

function Checks({
    projectId,
    number,
    checks,
}: {
    projectId: number;
    number: number;
    checks: Check[];
}) {
    const [open, setOpen] = useState<string | null>(null);
    const [logs, setLogs] = useState<Record<string, string | null>>({});

    const toggle = (check: Check) => {
        const next = open === check.id ? null : check.id;
        setOpen(next);

        if (next && !(check.id in logs)) {
            jsonRequest<{ log: string | null }>(
                ProjectPullRequestController.log.url({
                    project: projectId,
                    number,
                    check: check.id,
                }),
            )
                .then((data) =>
                    setLogs((current) => ({
                        ...current,
                        [check.id]: data.log,
                    })),
                )
                .catch((e: Error) =>
                    setLogs((current) => ({
                        ...current,
                        [check.id]: e.message,
                    })),
                );
        }
    };

    if (checks.length === 0) {
        return (
            <p
                className="rounded-xl border border-dashed border-sidebar-border p-6 text-sm text-muted-foreground"
                data-test="pull-checks"
            >
                No checks on its newest commit.
            </p>
        );
    }

    return (
        <ul
            className="divide-y rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
            data-test="pull-checks"
        >
            {checks.map((check) => {
                const failed = FAILED.includes(check.state);

                return (
                    <li key={check.id} data-test="pull-check">
                        <div className="flex items-center gap-2 px-3 py-2 text-sm">
                            <ChecksIcon
                                state={
                                    failed
                                        ? 'failure'
                                        : RUNNING.includes(check.state)
                                          ? 'pending'
                                          : 'success'
                                }
                            />
                            {failed ? (
                                <button
                                    type="button"
                                    onClick={() => toggle(check)}
                                    aria-expanded={open === check.id}
                                    className="min-w-0 flex-1 truncate text-left hover:underline"
                                >
                                    {check.name}
                                </button>
                            ) : (
                                <span className="min-w-0 flex-1 truncate">
                                    {check.name}
                                </span>
                            )}
                            <span className="shrink-0 text-xs text-muted-foreground">
                                {checkLabel(check)}
                            </span>
                            {check.url && (
                                <a
                                    href={check.url}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="shrink-0 text-xs text-muted-foreground hover:text-foreground"
                                    title="Details"
                                >
                                    <ExternalLink className="size-3.5" />
                                </a>
                            )}
                        </div>
                        {open === check.id && (
                            <pre
                                className="max-h-80 overflow-auto border-t border-sidebar-border/70 bg-muted/40 px-3 py-2 font-mono text-xs whitespace-pre-wrap dark:border-sidebar-border"
                                data-test="pull-check-log"
                            >
                                {check.id in logs
                                    ? (logs[check.id] ??
                                      'GitHub has no log for this check.')
                                    : 'Loading…'}
                            </pre>
                        )}
                    </li>
                );
            })}
        </ul>
    );
}

/** "Failed after 3m 2s", "Running", "Passed". */
function checkLabel(check: Check): string {
    const seconds =
        check.started_at && check.completed_at
            ? Math.max(
                  0,
                  Math.round(
                      (new Date(check.completed_at).getTime() -
                          new Date(check.started_at).getTime()) /
                          1000,
                  ),
              )
            : null;
    const took =
        seconds === null
            ? ''
            : ` in ${seconds >= 60 ? `${Math.floor(seconds / 60)}m ` : ''}${seconds % 60}s`;

    if (FAILED.includes(check.state)) {
        return `${check.state === 'cancelled' ? 'Cancelled' : check.state === 'timed_out' ? 'Timed out' : 'Failed'}${took}`;
    }

    if (RUNNING.includes(check.state)) {
        return check.state === 'queued' ? 'Queued' : 'Running';
    }

    return check.state === 'skipped'
        ? 'Skipped'
        : check.state === 'neutral'
          ? 'Neutral'
          : `Passed${took}`;
}

export function ChecksIcon({
    state,
    className,
}: {
    state: ChecksState;
    className?: string;
}) {
    if (state === 'success') {
        return (
            <CircleCheck
                className={cn('size-4 shrink-0 text-green-600', className)}
                aria-label="Checks passed"
                data-test="checks-success"
            />
        );
    }

    if (state === 'failure') {
        return (
            <CircleX
                className={cn('size-4 shrink-0 text-red-600', className)}
                aria-label="Checks failed"
                data-test="checks-failure"
            />
        );
    }

    if (state === 'pending') {
        return (
            <CircleDashed
                className={cn(
                    'size-4 shrink-0 animate-spin text-amber-500 [animation-duration:3s]',
                    className,
                )}
                aria-label="Checks running"
                data-test="checks-pending"
            />
        );
    }

    return null;
}

function PullStateIcon({ state }: { state: PullSummary['state'] }) {
    return (
        <GitPullRequest
            className={cn(
                'mt-0.5 size-4 shrink-0',
                state === 'open' && 'text-green-600',
                state === 'draft' && 'text-muted-foreground',
                state === 'merged' && 'text-purple-600',
                state === 'closed' && 'text-red-600',
            )}
        />
    );
}

function StateBadge({ state }: { state: PullSummary['state'] }) {
    return (
        <span
            className={cn(
                'flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium text-white',
                state === 'open' && 'bg-green-600',
                state === 'draft' && 'bg-muted-foreground',
                state === 'merged' && 'bg-purple-600',
                state === 'closed' && 'bg-red-600',
            )}
            data-test="pull-state"
        >
            <GitPullRequest className="size-3.5" />
            {state[0].toUpperCase() + state.slice(1)}
        </span>
    );
}

function ReviewBadge({
    review,
}: {
    review: NonNullable<PullSummary['review']>;
}) {
    return review === 'approved' ? (
        <Badge tone="green">Approved</Badge>
    ) : review === 'changes_requested' ? (
        <Badge tone="red">Changes requested</Badge>
    ) : (
        <Badge>Review required</Badge>
    );
}

function Badge({
    tone,
    children,
}: {
    tone?: 'green' | 'red' | 'blue';
    children: ReactNode;
}) {
    return (
        <span
            className={cn(
                'rounded-full border px-1.5 text-[11px] leading-4',
                !tone && 'border-sidebar-border text-muted-foreground',
                tone === 'green' &&
                    'border-green-600/40 text-green-700 dark:text-green-400',
                tone === 'red' &&
                    'border-red-600/40 text-red-700 dark:text-red-400',
                tone === 'blue' &&
                    'border-blue-600/40 text-blue-700 dark:text-blue-400',
            )}
        >
            {children}
        </span>
    );
}

function Avatar({ person }: { person: NonNullable<Person> }) {
    return person.avatar_url ? (
        <img
            src={person.avatar_url}
            alt=""
            className="size-5 shrink-0 rounded-full"
        />
    ) : null;
}

function ErrorNote({ children }: { children: ReactNode }) {
    return (
        <p
            className="rounded-lg border border-red-600/30 bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950/30 dark:text-red-400"
            data-test="pulls-error"
        >
            {children}
        </p>
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
