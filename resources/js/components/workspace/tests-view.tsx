import { usePage } from '@inertiajs/react';
import {
    AppWindow,
    Check,
    CircleDashed,
    Download,
    Minus,
    MousePointerClick,
    Play,
    X,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import ProjectTestController from '@/actions/App/Http/Controllers/ProjectTestController';
import { Spinner } from '@/components/ui/spinner';
import { takeOver } from '@/components/workspace/browser-view';
import type { BrowserSession } from '@/components/workspace/browser-view';
import { Notice } from '@/components/workspace/console-view';
import { requirementNames, useRequirements } from '@/hooks/use-requirements';
import { useWorkspaceTests } from '@/hooks/use-workspace-tests';
import { askAgent } from '@/lib/ask-agent';
import { jsonRequest } from '@/lib/json-request';
import type { TestTriage, WorkspaceTest } from '@/hooks/use-workspace-tests';
import { cn } from '@/lib/utils';
import type { Project } from '@/types/projects';

/** The group for tests not tagged with a requirement. */
const UNLINKED = '';

/**
 * The app's browser tests (TEST-001): grouped by the requirement each checks (TEST-003), with their latest
 * results. The user can run all of them, a requirement's, or one, and watch any test's recording.
 */
export default function TestsView({
    projectId,
    running,
    refreshSignal,
    focus,
    runnerReachable,
    onTakeOver,
}: {
    projectId: number;
    running: boolean;
    /** Changes when the agent did something or files changed: look again. */
    refreshSignal: string;
    /** A requirement to show, e.g. "REQ-001" (from the Requirements tab). */
    focus: string | null;
    /** This browser can reach the sandbox's preview address, where the test runner is served. */
    runnerReachable: boolean;
    /** A test's page was taken over at a step: show it in the Browser tab (TEST-005). */
    onTakeOver: (session: BrowserSession) => void;
}) {
    const { project } = usePage<{ project: Project }>().props;
    const { state, error, starting, run } = useWorkspaceTests(
        projectId,
        running,
        refreshSignal,
    );
    const { content } = useRequirements(projectId, running, refreshSignal);
    const names = useMemo(() => requirementNames(content), [content]);
    const [selectedId, setSelectedId] = useState<string | null>(null);
    const [runnerError, setRunnerError] = useState<string | null>(null);
    const [asked, setAsked] = useState<{
        text: string;
        error?: boolean;
    } | null>(null);
    const groupRefs = useRef<Record<string, HTMLElement | null>>({});
    const tests = state?.tests;
    const groups = useMemo(() => groupTests(tests ?? []), [tests]);
    const busy = starting || !!state?.running;
    const selected =
        tests?.find((test) => test.id === selectedId) ??
        (focus ? groups.find((g) => g.id === focus)?.tests[0] : undefined) ??
        tests?.find((test) => test.result?.status === 'failed') ??
        tests?.[0] ??
        null;

    // Opened on a requirement: bring its tests into view.
    useEffect(() => {
        if (focus && tests) {
            groupRefs.current[focus]?.scrollIntoView({ block: 'nearest' });
        }
    }, [focus, tests]);

    if (!running) {
        return <Notice>The tests show when the sandbox is running.</Notice>;
    }

    if (!state) {
        return error ? <Notice>{error}</Notice> : null;
    }

    const untested = Object.keys(names).filter(
        (id) => !state.tests.some((test) => test.tags.includes(id)),
    );
    const writeTests = () => {
        setAsked({ text: 'Asking the agent…' });
        askAgent(ProjectTestController.write.url(projectId))
            .then(({ queued }) =>
                setAsked({
                    text: queued
                        ? 'Asked the agent. It writes them after its current task; follow along in the chat.'
                        : 'The agent is writing the tests. Follow along in the chat.',
                }),
            )
            .catch((e: Error) => setAsked({ text: e.message, error: true }));
    };

    // A window opened after the request would be blocked as a pop-up: open it on the click, then point it there.
    const openRunner = () => {
        const runner = window.open(
            '',
            `onedrop-tests-${projectId}`,
            `popup,width=${Math.round(screen.availWidth * 0.9)},height=${Math.round(screen.availHeight * 0.9)}`,
        );

        // The runner is served from the sandbox: it gets no handle on this window.
        if (runner) {
            runner.opener = null;
        }

        runner?.document.write(
            '<title>Test runner</title><body style="font:14px system-ui;padding:2rem;background:#111;color:#ccc">Starting the test runner…</body>',
        );
        setRunnerError(null);

        jsonRequest<{ url: string }>(
            ProjectTestController.openRunner.url(projectId),
            {},
            'POST',
        )
            .then(({ url }) => {
                if (runner) {
                    runner.location.href = url;
                } else {
                    window.open(url, `onedrop-tests-${projectId}`);
                }
            })
            .catch((e: Error) => {
                runner?.close();
                setRunnerError(e.message);
            });
    };

    const passed = state.tests.filter((t) => t.result?.status === 'passed');
    const failed = state.tests.filter((t) => t.result?.status === 'failed');
    const notRun = state.tests.filter((t) => !t.result);

    return (
        <div className="flex min-h-0 flex-1 flex-col" data-test="tests-view">
            <div className="flex items-center gap-3 border-b border-sidebar-border/70 px-4 py-2 text-sm dark:border-sidebar-border">
                <button
                    type="button"
                    onClick={() => void run()}
                    disabled={busy || state.tests.length === 0}
                    data-test="tests-run-all"
                    className="inline-flex items-center gap-1.5 rounded-md bg-primary px-2.5 py-1 text-xs font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                >
                    {busy ? (
                        <Spinner className="size-3.5" />
                    ) : (
                        <Play className="size-3.5" />
                    )}
                    {busy ? 'Running…' : 'Run all'}
                </button>
                <button
                    type="button"
                    onClick={openRunner}
                    disabled={state.tests.length === 0 || !runnerReachable}
                    title={
                        runnerReachable
                            ? 'Watch the tests run step by step, inspect the page at each step, pick locators, and rerun tests as files change, in a bigger window'
                            : 'The test runner only opens on the machine running this app builder.'
                    }
                    data-test="tests-open-runner"
                    className="inline-flex items-center gap-1.5 rounded-md border px-2.5 py-1 text-xs font-medium hover:bg-muted disabled:opacity-50"
                >
                    <AppWindow className="size-3.5" />
                    Open test runner
                </button>
                <p
                    className="min-w-0 flex-1 truncate text-muted-foreground"
                    data-test="tests-summary"
                >
                    {state.tests.length === 0
                        ? ''
                        : [
                              passed.length && `${passed.length} passed`,
                              failed.length && `${failed.length} failed`,
                              notRun.length && `${notRun.length} not run`,
                          ]
                              .filter(Boolean)
                              .join(' · ')}
                </p>
            </div>
            {runnerError && (
                <p
                    className="border-b border-sidebar-border/70 bg-red-500/10 px-4 py-2 text-sm text-red-700 dark:border-sidebar-border dark:text-red-300"
                    data-test="tests-runner-error"
                >
                    {runnerError}
                </p>
            )}
            {(error || state.error) && (
                <pre
                    className="max-h-40 overflow-auto border-b border-sidebar-border/70 bg-red-500/10 px-4 py-2 font-mono text-xs whitespace-pre-wrap text-red-700 dark:border-sidebar-border dark:text-red-300"
                    data-test="tests-error"
                >
                    {error ?? state.error}
                </pre>
            )}
            {project.track_requirements && untested.length > 0 && (
                <div
                    className="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-sidebar-border/70 px-4 py-2 text-sm dark:border-sidebar-border"
                    data-test="tests-untested"
                >
                    <p className="min-w-0 flex-1 text-muted-foreground">
                        {asked ? (
                            <span
                                className={cn(
                                    asked.error &&
                                        'text-red-600 dark:text-red-400',
                                )}
                            >
                                {asked.text}
                            </span>
                        ) : (
                            `${untested.length === 1 ? `${untested[0]} has` : `${untested.length} requirements have`} no tests yet.`
                        )}
                    </p>
                    {!asked && (
                        <button
                            type="button"
                            onClick={writeTests}
                            data-test="tests-write"
                            className="shrink-0 rounded-md border px-2.5 py-1 text-xs font-medium hover:bg-muted"
                        >
                            Write them with agent
                        </button>
                    )}
                </div>
            )}
            {state.tests.length === 0 ? (
                <div
                    className="max-w-prose p-4 text-sm text-muted-foreground"
                    data-test="tests-empty"
                >
                    <p className="font-medium text-foreground">No tests yet</p>
                    <p className="mt-1">
                        {project.track_requirements
                            ? 'As the agent builds what you ask for, it writes browser tests that check each requirement, runs them, and records a video of each. Watch them here.'
                            : 'Turn on requirements in the Requirements tab, and the agent writes browser tests that check each one as it builds.'}
                    </p>
                </div>
            ) : (
                <div className="flex min-h-0 flex-1">
                    <ul
                        className="w-2/5 max-w-md min-w-56 shrink-0 overflow-y-auto border-r border-sidebar-border/70 py-1 dark:border-sidebar-border"
                        aria-label="Tests"
                    >
                        {groups.map((group) => (
                            <li
                                key={group.id || 'unlinked'}
                                ref={(el) => {
                                    groupRefs.current[group.id] = el;
                                }}
                                className={cn(
                                    'py-1',
                                    focus &&
                                        group.id === focus &&
                                        'bg-muted/50',
                                )}
                                data-test={`tests-group-${group.id || 'unlinked'}`}
                            >
                                <div className="group/heading flex items-center gap-2 px-3 py-1 text-xs text-muted-foreground">
                                    <span className="min-w-0 flex-1 truncate">
                                        {group.id ? (
                                            <>
                                                <span className="font-medium text-foreground">
                                                    {group.id}
                                                </span>
                                                {names[group.id] &&
                                                    ` · ${names[group.id]}`}
                                            </>
                                        ) : (
                                            'Not linked to a requirement'
                                        )}
                                    </span>
                                    {group.id && (
                                        <button
                                            type="button"
                                            onClick={() =>
                                                void run([`@${group.id}`])
                                            }
                                            disabled={busy}
                                            aria-label={`Run ${group.id}'s tests`}
                                            title={`Run ${group.id}'s tests`}
                                            className="rounded p-0.5 opacity-0 group-hover/heading:opacity-100 hover:bg-muted focus-visible:opacity-100 disabled:hidden"
                                        >
                                            <Play className="size-3" />
                                        </button>
                                    )}
                                </div>
                                <ul>
                                    {group.tests.map((test) => (
                                        <li key={test.id}>
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    setSelectedId(test.id)
                                                }
                                                aria-current={
                                                    selected?.id === test.id
                                                }
                                                data-test="test-row"
                                                className={cn(
                                                    'flex w-full items-start gap-2 px-3 py-1.5 text-left text-sm hover:bg-muted',
                                                    selected?.id === test.id &&
                                                        'bg-muted',
                                                )}
                                            >
                                                <StatusIcon test={test} />
                                                <span className="min-w-0 flex-1">
                                                    {test.title}
                                                    {test.result?.triage && (
                                                        <span
                                                            className="block text-xs text-muted-foreground"
                                                            data-test="test-row-triage"
                                                        >
                                                            {triageLabel(
                                                                test.result
                                                                    .triage,
                                                            )}
                                                        </span>
                                                    )}
                                                </span>
                                                {test.result && (
                                                    <span className="shrink-0 text-xs text-muted-foreground tabular-nums">
                                                        {seconds(
                                                            test.result
                                                                .duration,
                                                        )}
                                                    </span>
                                                )}
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            </li>
                        ))}
                    </ul>
                    {selected && (
                        <TestDetail
                            key={selected.id}
                            projectId={projectId}
                            test={selected}
                            busy={busy}
                            onRun={() =>
                                void run([`${selected.file}:${selected.line}`])
                            }
                            takeOverReachable={runnerReachable}
                            onTakeOver={onTakeOver}
                        />
                    )}
                </div>
            )}
        </div>
    );
}

/** One test: its recording, what went wrong, and a button to run it again. */
function TestDetail({
    projectId,
    test,
    busy,
    onRun,
    takeOverReachable,
    onTakeOver,
}: {
    projectId: number;
    test: WorkspaceTest;
    busy: boolean;
    onRun: () => void;
    takeOverReachable: boolean;
    onTakeOver: (session: BrowserSession) => void;
}) {
    const result = test.result;
    const [takingOver, setTakingOver] = useState<number | null>(null);
    const [takeOverError, setTakeOverError] = useState<string | null>(null);

    /** Run the test up to after its step n (0: before the first) and open its page in the Browser tab. */
    const takeOverAt = (n: number) => {
        setTakingOver(n);
        setTakeOverError(null);
        takeOver(
            projectId,
            `${test.file}:${test.line}`,
            n,
            test.title,
            n > 0 ? stepLabel(result?.steps?.[n - 1]) : null,
        )
            .then(onTakeOver)
            .catch((e: Error) => setTakeOverError(e.message))
            .finally(() => setTakingOver(null));
    };

    return (
        <div
            className="min-w-0 flex-1 overflow-y-auto p-4"
            data-test="test-detail"
        >
            <div className="flex items-start gap-3">
                <div className="min-w-0 flex-1">
                    <h3 className="font-medium">{test.title}</h3>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        {test.file}:{test.line}
                        {result &&
                            ` · ${statusLabel(result.status)} ${timeAgo(result.ran_at)}`}
                    </p>
                </div>
                <button
                    type="button"
                    onClick={onRun}
                    disabled={busy}
                    data-test="test-run"
                    className="inline-flex shrink-0 items-center gap-1.5 rounded-md border px-2.5 py-1 text-xs font-medium hover:bg-muted disabled:opacity-50"
                >
                    <Play className="size-3.5" />
                    Run
                </button>
            </div>
            {result?.triage && (
                <p
                    className="mt-3 text-sm font-medium"
                    title={`Jev, a decision model, is ${Math.round(result.triage.probability * 100)}% sure`}
                    data-test="test-triage"
                    data-verdict={result.triage.verdict}
                >
                    {triageLabel(result.triage)}
                </p>
            )}
            {result?.error && (
                <pre
                    className="mt-3 overflow-x-auto rounded-md bg-red-500/10 p-3 font-mono text-xs whitespace-pre-wrap text-red-700 dark:text-red-300"
                    data-test="test-error"
                >
                    {result.error}
                </pre>
            )}
            {result?.steps && result.steps.length > 0 && (
                <div className="mt-4" data-test="test-steps">
                    <h4 className="text-xs font-medium text-muted-foreground">
                        Steps
                        <span className="font-normal">
                            {' '}
                            · take over after one to use the page yourself and
                            ask the agent for changes
                        </span>
                    </h4>
                    {takeOverError && (
                        <p
                            className="mt-1 text-xs text-red-600 dark:text-red-400"
                            data-test="take-over-error"
                        >
                            {takeOverError}
                        </p>
                    )}
                    <ol className="mt-1 max-w-3xl text-sm">
                        {result.steps.map((step, i) => (
                            <li
                                key={i}
                                className="group/step flex items-center gap-2 rounded px-2 py-1 hover:bg-muted"
                                data-test="test-step"
                            >
                                <span className="w-5 shrink-0 text-right text-xs text-muted-foreground tabular-nums">
                                    {i + 1}
                                </span>
                                <span className="min-w-0 flex-1 truncate">
                                    {step.title}
                                    {step.subtitle && (
                                        <span className="ml-1.5 font-mono text-xs text-muted-foreground">
                                            {step.subtitle}
                                        </span>
                                    )}
                                </span>
                                <button
                                    type="button"
                                    onClick={() => takeOverAt(i + 1)}
                                    disabled={
                                        takingOver !== null ||
                                        !takeOverReachable
                                    }
                                    title={
                                        takeOverReachable
                                            ? 'Run the test up to here, then use its page yourself in the Browser tab'
                                            : 'This only works on the machine running this app builder.'
                                    }
                                    data-test="take-over"
                                    className={cn(
                                        'inline-flex shrink-0 items-center gap-1 rounded border px-1.5 py-0.5 text-xs opacity-0 group-hover/step:opacity-100 hover:bg-background focus-visible:opacity-100 disabled:opacity-50',
                                        takingOver === i + 1 && 'opacity-100',
                                    )}
                                >
                                    {takingOver === i + 1 ? (
                                        <Spinner className="size-3" />
                                    ) : (
                                        <MousePointerClick className="size-3" />
                                    )}
                                    Take over
                                </button>
                            </li>
                        ))}
                    </ol>
                </div>
            )}
            {result?.video ? (
                <Recording projectId={projectId} path={result.video} />
            ) : (
                <p className="mt-3 text-sm text-muted-foreground">
                    {result
                        ? 'No recording for this run.'
                        : "This test hasn't run yet. Run it to record it."}
                </p>
            )}
            {result?.trace && (
                <a
                    href={ProjectTestController.recording.url(projectId, {
                        query: { path: result.trace },
                    })}
                    className="mt-3 inline-flex items-center gap-1.5 text-xs text-muted-foreground hover:text-foreground"
                    title="Open it at trace.playwright.dev to step through each action"
                >
                    <Download className="size-3.5" />
                    Download trace
                </a>
            )}
        </div>
    );
}

/** A test's video, fetched whole so it can be scrubbed through. */
function Recording({ projectId, path }: { projectId: number; path: string }) {
    const [url, setUrl] = useState<string | null>(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        let objectUrl: string | null = null;
        let cancelled = false;

        void (async () => {
            const response = await fetch(
                ProjectTestController.recording.url(projectId, {
                    query: { path },
                }),
                { credentials: 'same-origin' },
            ).catch(() => null);

            if (cancelled) {
                return;
            }

            if (!response?.ok) {
                setFailed(true);

                return;
            }

            objectUrl = URL.createObjectURL(await response.blob());

            if (cancelled) {
                URL.revokeObjectURL(objectUrl);

                return;
            }

            setUrl(objectUrl);
        })();

        return () => {
            cancelled = true;

            if (objectUrl) {
                URL.revokeObjectURL(objectUrl);
            }
        };
    }, [projectId, path]);

    if (failed) {
        return (
            <p className="mt-3 text-sm text-muted-foreground">
                Couldn't load the recording.
            </p>
        );
    }

    return url ? (
        <video
            src={url}
            controls
            autoPlay
            muted
            className="mt-3 w-full max-w-3xl rounded-md border bg-black"
            data-test="test-video"
        />
    ) : (
        <div className="mt-3 aspect-video w-full max-w-3xl animate-pulse rounded-md bg-muted" />
    );
}

function StatusIcon({ test }: { test: WorkspaceTest }) {
    const status = test.result?.status;
    const [Icon, tone, label] =
        status === 'passed'
            ? [Check, 'text-green-600 dark:text-green-400', 'Passed']
            : status === 'failed'
              ? [X, 'text-red-600 dark:text-red-400', 'Failed']
              : status === 'skipped'
                ? [Minus, 'text-muted-foreground', 'Skipped']
                : [CircleDashed, 'text-muted-foreground', 'Not run'];

    return (
        <Icon
            className={cn('mt-0.5 size-4 shrink-0', tone)}
            aria-label={label}
            data-status={status ?? 'not-run'}
        />
    );
}

/** Tests by the requirements they're tagged with (a test with two appears under both), then the rest. */
function groupTests(tests: WorkspaceTest[]) {
    const byId = new Map<string, WorkspaceTest[]>();
    const sorted = [...tests].sort(
        (a, b) => a.file.localeCompare(b.file) || a.line - b.line,
    );

    for (const test of sorted) {
        const requirements = test.tags.filter((tag) => /^REQ-\d+$/.test(tag));

        for (const id of requirements.length ? requirements : [UNLINKED]) {
            byId.set(id, [...(byId.get(id) ?? []), test]);
        }
    }

    return [...byId.entries()]
        .sort(([a], [b]) =>
            a === UNLINKED ? 1 : b === UNLINKED ? -1 : number(a) - number(b),
        )
        .map(([id, grouped]) => ({ id, tests: grouped }));
}

const number = (id: string) => Number(id.replace(/\D/g, ''));

/** A step as a person would say it: `Click getByRole('button')`. */
const stepLabel = (step?: { title: string; subtitle: string | null }) =>
    step ? [step.title, step.subtitle].filter(Boolean).join(' ') : null;

/** Why Jev thinks a test failed (TEST-008), e.g. "Likely: the test is out of date". */
const triageLabel = (triage: TestTriage) =>
    `Likely: ${
        {
            app: 'the app broke',
            test: 'the test is out of date',
            flaky: 'flaky or timing',
        }[triage.verdict]
    }`;

const seconds = (ms: number) => `${(ms / 1000).toFixed(1)}s`;

const statusLabel = (status: string) =>
    ({ passed: 'Passed', failed: 'Failed', skipped: 'Skipped' })[status] ??
    status;

function timeAgo(iso: string): string {
    const minutes = Math.round((Date.now() - new Date(iso).getTime()) / 60000);

    if (minutes < 1) {
        return 'just now';
    }

    if (minutes < 60) {
        return `${minutes} min ago`;
    }

    const hours = Math.round(minutes / 60);

    return hours < 24 ? `${hours} h ago` : new Date(iso).toLocaleDateString();
}
