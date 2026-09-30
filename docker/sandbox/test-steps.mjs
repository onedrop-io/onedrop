// Loaded into each Playwright test worker (NODE_OPTIONS=--import, set by tests.mjs and browser.mjs). Workers report
// every step to the runner just before running it; this watches those reports to:
//
// - record each test's steps (the actions and checks written in the test itself, in order) to
//   ONEDROP_TESTS_STEPS, one JSON line per step, so the Tests tab can list them (TEST-005);
// - with ONEDROP_BROWSER_AFTER=<n>, freeze the worker once step n has finished (0: before the first), before step n+1 starts (or before the
//   test's teardown, if it has no more), so the browser stays on exactly that page for the browser. The browser
//   is its own process and keeps running; the frozen worker sends it nothing more, and its timers (the test's
//   timeout) stand still. browser.mjs learns it happened from ONEDROP_BROWSER_HELD, then attaches to the browser.
import { appendFileSync, writeFileSync } from 'node:fs';

const stepsFile = process.env.ONEDROP_TESTS_STEPS;
// -1: don't stop. 0 stops before the first step, n after the nth.
const after =
    process.env.ONEDROP_BROWSER_AFTER === undefined
        ? -1
        : Number(process.env.ONEDROP_BROWSER_AFTER);
const heldFile = process.env.ONEDROP_BROWSER_HELD;
const USER_STEPS = new Set(['pw:api', 'expect', 'test.step']);

if (typeof process.send === 'function' && (stepsFile || after >= 0)) {
    const send = process.send.bind(process);
    const counts = new Map();
    let frozen = false;

    const freeze = (testId, step, next) => {
        frozen = true;

        if (heldFile) {
            writeFileSync(
                heldFile,
                JSON.stringify({ testId, step, next, pid: process.pid }),
            );
        }

        process.kill(process.pid, 'SIGSTOP');
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

            if (after >= 0 && n === after + 1) {
                freeze(params.testId, after, params.title);
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

        // The test's own steps are done and its teardown (which closes the page) is starting: stop here instead.
        const teardown =
            method === 'stepBegin' &&
            params &&
            !params.parentStepId &&
            params.category === 'hook' &&
            params.title === 'After Hooks';

        if (
            !frozen &&
            after >= 0 &&
            (teardown || method === 'testEnd') &&
            params &&
            (counts.get(params.testId) ?? 0) <= after
        ) {
            freeze(params.testId, counts.get(params.testId) ?? 0, null);
        }

        return send(message, ...rest);
    };
}
