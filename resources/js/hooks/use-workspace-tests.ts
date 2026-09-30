import { useCallback, useEffect, useRef, useState } from 'react';
import ProjectTestController from '@/actions/App/Http/Controllers/ProjectTestController';

/** A test's latest run (TEST-001). */
export type TestResult = {
    status: 'passed' | 'failed' | 'skipped';
    duration: number;
    error: string | null;
    /** Workspace paths of its recording. */
    video: string | null;
    trace: string | null;
    ran_at: string;
    /** The actions and checks the test made, in order (TEST-005). */
    steps?: TestStep[];
};

/** One action or check in a test, e.g. "Click" on getByRole('button'), at a line of the test's file. */
export type TestStep = {
    title: string;
    subtitle: string | null;
    line: number;
};

/** One of the app's browser tests, in tests/e2e, tagged with the requirements it checks (REQ-001). */
export type WorkspaceTest = {
    id: string;
    file: string;
    line: number;
    title: string;
    tags: string[];
    result: TestResult | null;
};

export type TestsState = {
    running: boolean;
    started_at: string | null;
    finished_at: string | null;
    /** Why the tests couldn't be listed or run (e.g. a syntax error), from Playwright. */
    error: string | null;
    tests: WorkspaceTest[];
};

/** How often a run is checked on while it goes, in ms. */
const POLL_MS = 2000;

function xsrfToken(): string {
    const token = document.cookie
        .split('; ')
        .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
        ?.slice('XSRF-TOKEN='.length);

    return decodeURIComponent(token ?? '');
}

/**
 * The app's browser tests and their results, read from the sandbox while `enabled`, again whenever
 * `refreshSignal` changes (the agent may have written or run some), and every few seconds while a run goes.
 */
export function useWorkspaceTests(
    projectId: number,
    enabled: boolean,
    refreshSignal: string,
) {
    const [state, setState] = useState<TestsState | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [starting, setStarting] = useState(false);
    const [checks, setChecks] = useState(0);
    const alive = useRef(true);

    useEffect(() => {
        alive.current = true;

        return () => {
            alive.current = false;
        };
    }, []);

    const load = useCallback(
        async (cached: boolean) => {
            const response = await fetch(
                ProjectTestController.index.url(projectId, {
                    query: cached ? { cached: 1 } : {},
                }),
                {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                },
            ).catch(() => null);
            const body = response
                ? await response.json().catch(() => ({}))
                : {};

            if (!alive.current) {
                return;
            }

            if (!response?.ok) {
                setError(body.message ?? 'Could not read the tests.');

                return;
            }

            setError(null);
            setState(body as TestsState);
        },
        [projectId],
    );

    useEffect(() => {
        if (enabled) {
            void load(false);
        }
    }, [enabled, load, refreshSignal]);

    // While a run goes, check on it; the kept state is enough (no need to look for new tests).
    const running = state?.running ?? false;

    useEffect(() => {
        if (!enabled || !running) {
            return;
        }

        const timer = window.setTimeout(() => {
            void load(true).then(() => setChecks((n) => n + 1));
        }, POLL_MS);

        return () => window.clearTimeout(timer);
    }, [enabled, running, load, checks]);

    /** Run all the tests, or some: files, `file:line`, or tags such as `@REQ-001`. */
    const run = useCallback(
        async (targets: string[] = []) => {
            setStarting(true);

            const response = await fetch(
                ProjectTestController.store.url(projectId),
                {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-XSRF-TOKEN': xsrfToken(),
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ targets }),
                },
            ).catch(() => null);
            const body = response
                ? await response.json().catch(() => ({}))
                : {};

            if (!alive.current) {
                return;
            }

            setStarting(false);

            if (!response?.ok) {
                setError(body.message ?? 'Could not start the tests.');

                return;
            }

            setError(null);
            setState((current) =>
                current ? { ...current, running: true } : current,
            );
        },
        [projectId],
    );

    return { state, error, starting, run };
}

/** Which tests check a requirement, and how they did. */
export function requirementTests(tests: WorkspaceTest[], id: string) {
    const checking = tests.filter((test) => test.tags.includes(id));

    return {
        tests: checking,
        passed: checking.filter((test) => test.result?.status === 'passed')
            .length,
        failed: checking.filter((test) => test.result?.status === 'failed')
            .length,
        notRun: checking.filter((test) => !test.result).length,
    };
}
