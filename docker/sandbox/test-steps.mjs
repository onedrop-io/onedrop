// Loaded into each Playwright test worker (NODE_OPTIONS=--import, set by tests.mjs and browser.mjs). Workers report
// every step to the runner just before running it; this watches those reports to:
//
// - record each test's steps (the actions and checks written in the test itself, in order) to
//   ONEDROP_TESTS_STEPS, one JSON line per step, so the Tests tab can list them (TEST-005);
// - with ONEDROP_BROWSER_AFTER=<n>, pause the test once step n has finished (0: before the first), before step n+1
//   starts, or before its teardown (which closes the page) once it has no more steps. Pausing freezes the worker
//   (SIGSTOP): the browser is its own process and keeps the page, and the frozen worker sends it nothing more.
//   browser.mjs learns of each pause from ONEDROP_BROWSER_HELD, and resumes the test (TEST-006) by writing how far
//   to go next to ONEDROP_BROWSER_CONTROL ({"until": <step>, "pace": <ms>}) and waking the worker (SIGCONT).
//   Resumed steps wait `pace` ms before each, so the user can follow them.
// - with ONEDROP_DEMO_TIMELINE (demo.mjs, DEMO-002), wait ONEDROP_DEMO_PACE ms before each action (not checks) and
//   hold the final state a second before the teardown, and note when each step starts and ends and when the test's
//   own steps are done, one JSON line each, so the demo can cut each test's part out of the recording.
import { appendFileSync, readFileSync, writeFileSync } from 'node:fs';

const stepsFile = process.env.ONEDROP_TESTS_STEPS;
// -1: don't pause. 0 pauses before the first step, n after the nth.
let after =
    process.env.ONEDROP_BROWSER_AFTER === undefined
        ? -1
        : Number(process.env.ONEDROP_BROWSER_AFTER);
const heldFile = process.env.ONEDROP_BROWSER_HELD;
const controlFile = process.env.ONEDROP_BROWSER_CONTROL;
const USER_STEPS = new Set(['pw:api', 'expect', 'test.step']);
const MAX_PACE_MS = 5000;
const timelineFile = process.env.ONEDROP_DEMO_TIMELINE;
const demoPace = Math.max(
    0,
    Math.min(MAX_PACE_MS, Number(process.env.ONEDROP_DEMO_PACE) || 0),
);
const DEMO_HOLD_MS = 1000;

/** Block this thread for `ms` (the worker must not reach the browser meanwhile; the browser carries on). */
function wait(ms) {
    Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, ms);
}

/** A line of the demo's timeline; never fails the test. */
function note(entry) {
    try {
        appendFileSync(
            timelineFile,
            `${JSON.stringify({ ...entry, at: Date.now() })}\n`,
        );
    } catch {
        // The demo then says the scene has no recording.
    }
}

if (
    typeof process.send === 'function' &&
    (stepsFile || after >= 0 || timelineFile)
) {
    const send = process.send.bind(process);
    const counts = new Map();
    const userSteps = new Set();
    let frozen = false;
    let ended = false;
    let pace = 0;
    let failure = null;

    const pause = (testId, step, next) => {
        frozen = true;

        if (heldFile) {
            writeFileSync(
                heldFile,
                JSON.stringify({
                    testId,
                    step,
                    next,
                    ended,
                    error: failure,
                    pid: process.pid,
                    at: Date.now(),
                }),
            );
        }

        process.kill(process.pid, 'SIGSTOP');

        // Woken up: browser.mjs says how far to go now. At the end there's nowhere left to go.
        let control = {};

        try {
            control = JSON.parse(readFileSync(controlFile, 'utf8'));
        } catch {
            // No instructions: carry on to the end.
        }

        if (!ended) {
            after = Number.isFinite(control.until)
                ? control.until
                : Number.MAX_SAFE_INTEGER;
            pace = Math.max(
                0,
                Math.min(MAX_PACE_MS, Number(control.pace) || 0),
            );
            frozen = false;
        }
    };

    process.send = (message, ...rest) => {
        const method = message?.params?.method;
        const params = message?.params?.params;

        if (
            !frozen &&
            method === 'stepBegin' &&
            params &&
            !params.parentStepId &&
            params.location &&
            USER_STEPS.has(params.category)
        ) {
            const n = (counts.get(params.testId) ?? 0) + 1;
            counts.set(params.testId, n);
            userSteps.add(params.stepId);

            if (after >= 0 && n === after + 1) {
                pause(
                    params.testId,
                    after,
                    [params.title, params.subtitle].filter(Boolean).join(' '),
                );
            } else if (pace > 0) {
                wait(pace);
            } else if (timelineFile && params.category === 'pw:api') {
                wait(demoPace);
            }

            if (timelineFile) {
                note({ test: params.testId, kind: 'step', n });
            }

            if (stepsFile) {
                try {
                    appendFileSync(
                        stepsFile,
                        `${JSON.stringify({
                            test: params.testId,
                            n,
                            title: String(params.title ?? '').slice(0, 200),
                            subtitle: params.subtitle
                                ? String(params.subtitle).slice(0, 200)
                                : null,
                            line: params.location.line,
                        })}\n`,
                    );
                } catch {
                    // Steps are a nicety; a test run never fails over them.
                }
            }
        }

        if (
            timelineFile &&
            method === 'stepEnd' &&
            params &&
            userSteps.has(params.stepId)
        ) {
            note({
                test: params.testId,
                kind: 'stepEnd',
                n: counts.get(params.testId) ?? 0,
            });
        }

        // A step of the test's own failed: say which, when the test pauses at its end.
        if (
            method === 'stepEnd' &&
            params &&
            userSteps.has(params.stepId) &&
            params.error
        ) {
            const n = counts.get(params.testId) ?? 0;
            failure = `Step ${n} failed: ${String(
                params.error.message ?? params.error.value ?? 'error',
            )
                // eslint-disable-next-line no-control-regex
                .replace(/\x1b\[[0-9;?]*[ -/]*[@-~]/g, '')
                .slice(0, 2000)}`;
        }

        // The test's own steps are done and its teardown (which closes the page) is starting: pause here for good.
        const teardown =
            method === 'stepBegin' &&
            params &&
            !params.parentStepId &&
            params.category === 'hook' &&
            params.title === 'After Hooks';

        if (timelineFile && teardown) {
            note({ test: params.testId, kind: 'end' });
            wait(DEMO_HOLD_MS);
        }

        if (
            !frozen &&
            after >= 0 &&
            (teardown || method === 'testEnd') &&
            params &&
            (counts.get(params.testId) ?? 0) <= after
        ) {
            ended = true;
            pause(params.testId, counts.get(params.testId) ?? 0, null);
        }

        return send(message, ...rest);
    };
}
