#!/usr/bin/env node
// Runs the app's Playwright tests (tests/e2e) for the Tests tab and the agent (TEST-001..003), recording a video
// and a trace of each, and keeps the latest result of every test in /workspace/.onedrop/tests/state.json.
//
//   node tests.mjs status [--cached]         JSON: the tests (found again unless --cached or running) and their results
//   node tests.mjs run [target...]           run them (all, a file, file:line or @REQ-001), printing progress
//   node tests.mjs run --background [target] start a run and return at once (the Tests tab)
//   node tests.mjs ui start | stop            the test runner (Playwright's UI mode, TEST-004): start prints
//                                            JSON {token}; host-proxy.mjs serves it to holders of the token
//
// A run only replaces the results of the tests it ran. Each run records into its own folder under runs/, and
// folders no result points to any more are removed. None of this is committed (see checkpoint).
import { spawn, spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import {
    existsSync,
    mkdirSync,
    readFileSync,
    readdirSync,
    rmSync,
    statSync,
    writeFileSync,
    openSync,
} from 'node:fs';
import net from 'node:net';
import { fileURLToPath } from 'node:url';

const WORKSPACE = process.env.ONEDROP_WORKSPACE || '/workspace';
const DIR = `${WORKSPACE}/.onedrop/tests`;
const STATE = `${DIR}/state.json`;
const RUNS = `${DIR}/runs`;
const TEST_DIR = 'tests/e2e';
const CONFIG = '/opt/onedrop/playwright.config.mjs';
// Loaded into the test workers: records each test's steps (and stops at one, for the live browser).
const STEPS_PRELOAD = '/opt/onedrop/test-steps.mjs';
const MAX_STEPS = 200;
const PLAYWRIGHT = `${WORKSPACE}/node_modules/.bin/playwright`;
const MAX_ERROR = 4000;

// The test runner: where it listens, the state host-proxy.mjs reads its token from, the file the proxy touches
// whenever it's used, and how long it may go unused before it stops.
const UI_PORT = Number(process.env.ONEDROP_TESTS_UI_PORT || 9323);
const UI_STATE =
    process.env.ONEDROP_TESTS_UI_STATE || '/tmp/onedrop-tests-ui.json';
const UI_SEEN = `${UI_STATE}.seen`;
const UI_IDLE_MS = 30 * 60 * 1000;
const UI_START_MS = 30_000;

// Where the image put Playwright's ffmpeg (the Dockerfile sets it too; some providers don't pass the image's env on).
process.env.PLAYWRIGHT_BROWSERS_PATH ||= '/opt/onedrop/playwright';

// eslint-disable-next-line no-control-regex
const ANSI = /\x1b\[[0-9;?]*[ -/]*[@-~]/g;

const EMPTY = {
    running: false,
    pid: null,
    target: [],
    started_at: null,
    finished_at: null,
    error: null,
    tests: [],
};

function readState() {
    try {
        return { ...EMPTY, ...JSON.parse(readFileSync(STATE, 'utf8')) };
    } catch {
        return { ...EMPTY };
    }
}

function writeState(state) {
    mkdirSync(DIR, { recursive: true });
    writeFileSync(`${STATE}.tmp`, JSON.stringify(state));
    spawnSync('mv', ['-f', `${STATE}.tmp`, STATE]);
}

function alive(pid) {
    try {
        return !!pid && process.kill(pid, 0);
    } catch {
        return false;
    }
}

/** The state, with a run whose process is gone (killed, sandbox restarted) counted as finished. */
function currentState() {
    const state = readState();

    return state.running && !alive(state.pid)
        ? { ...state, running: false, pid: null }
        : state;
}

const hasTests = () => existsSync(`${WORKSPACE}/${TEST_DIR}`);
const hasPlaywright = () => existsSync(PLAYWRIGHT);
const relative = (path) =>
    path?.startsWith(`${WORKSPACE}/`)
        ? path.slice(WORKSPACE.length + 1)
        : (path ?? null);

/** Every test in a Playwright JSON report, with its describe blocks in the title. */
function specs(report) {
    const found = [];
    const walk = (suite, titles) => {
        for (const spec of suite.specs ?? []) {
            found.push({ spec, title: [...titles, spec.title].join(' › ') });
        }

        for (const child of suite.suites ?? []) {
            walk(child, [...titles, child.title]);
        }
    };

    for (const suite of report.suites ?? []) {
        walk(suite, []);
    }

    return found;
}

function describe({ spec, title }) {
    return {
        id: spec.id,
        file: `${TEST_DIR}/${spec.file}`,
        line: spec.line,
        // Tags written in the title ("… @REQ-002") are shown as tags, not in the title.
        title: title.replace(/\s+@[\w-]+/g, '').trim(),
        tags: (spec.tags ?? []).map((tag) => tag.replace(/^@/, '')),
    };
}

function reportError(report) {
    const message = (report.errors ?? [])
        .map((error) => error.message ?? error.value ?? '')
        .join('\n\n');

    return message ? message.replace(ANSI, '').slice(0, MAX_ERROR) : null;
}

/** The tests in tests/e2e, without running them. */
function list() {
    if (!hasTests()) {
        return { tests: [], error: null };
    }

    if (!hasPlaywright()) {
        return {
            tests: null,
            error: "The tests need Playwright: ask the agent to add @playwright/test to the app's dev dependencies.",
        };
    }

    const result = spawnSync(
        PLAYWRIGHT,
        ['test', '--config', CONFIG, '--list', '--reporter=json'],
        {
            cwd: WORKSPACE,
            encoding: 'utf8',
            maxBuffer: 64 * 1024 * 1024,
        },
    );

    try {
        const report = JSON.parse(result.stdout);

        return {
            tests: specs(report).map(describe),
            error: reportError(report),
        };
    } catch {
        return {
            tests: null,
            error: (
                result.stderr ||
                result.stdout ||
                "Couldn't list the tests."
            )
                .replace(ANSI, '')
                .slice(0, MAX_ERROR),
        };
    }
}

/** The tests found now, each with its last result (null when it hasn't run yet). */
function merge(tests, previous, results = {}) {
    const before = Object.fromEntries(previous.map((test) => [test.id, test]));

    return tests.map((test) => ({
        ...test,
        result: results[test.id] ?? before[test.id]?.result ?? null,
    }));
}

/** Remove recordings no test's result points to. */
function prune(tests) {
    const kept = new Set(tests.map((test) => test.result?.run).filter(Boolean));

    for (const run of existsSync(RUNS) ? readdirSync(RUNS) : []) {
        if (!kept.has(run)) {
            rmSync(`${RUNS}/${run}`, { recursive: true, force: true });
        }
    }
}

function status(cached) {
    const state = currentState();

    if (cached || state.running) {
        return state;
    }

    const found = list();
    // Listing takes a moment: if a run started meanwhile, its state wins.
    const latest = currentState();

    if (latest.running) {
        return latest;
    }

    const next = {
        ...latest,
        error: found.error,
        tests:
            found.tests === null
                ? latest.tests
                : merge(found.tests, latest.tests),
    };

    writeState(next);

    return next;
}

/** Each test's steps from the file test-steps.mjs wrote during a run, by test id. */
function readSteps(file) {
    const byId = {};

    try {
        for (const line of readFileSync(file, 'utf8').split('\n')) {
            const step = line ? JSON.parse(line) : null;

            if (step && (byId[step.test] ??= []).length < MAX_STEPS) {
                byId[step.test].push({
                    title: step.title,
                    subtitle: step.subtitle,
                    line: step.line,
                });
            }
        }
    } catch {
        // No steps recorded (an older image's preload, or none ran).
    }

    return byId;
}

function results(report, run, steps = {}) {
    const byId = {};

    for (const { spec } of specs(report)) {
        const result = spec.tests?.[0]?.results?.at(-1);

        if (!result) {
            continue;
        }

        const attachment = (name) =>
            relative(result.attachments?.find((a) => a.name === name)?.path);
        const error = (result.errors ?? [])
            .map((e) => e.message ?? '')
            .join('\n\n')
            .replace(ANSI, '')
            .trim();

        byId[spec.id] = {
            status:
                { passed: 'passed', skipped: 'skipped' }[result.status] ??
                'failed',
            duration: result.duration,
            error: error ? error.slice(0, MAX_ERROR) : null,
            video: attachment('video'),
            trace: attachment('trace'),
            run,
            ran_at: result.startTime ?? new Date().toISOString(),
            steps: steps[spec.id] ?? [],
        };
    }

    return byId;
}

/** Run the target's tests (files, file:line, or @TAGs), or all of them. */
function run(target) {
    const state = currentState();
    const files = target.filter((arg) => !arg.startsWith('@'));
    const grep = target
        .filter((arg) => arg.startsWith('@'))
        .flatMap((tag) => ['--grep', tag]);

    if (state.running && state.pid !== process.pid) {
        console.error('Tests are already running.');

        return 4;
    }

    if (!hasTests()) {
        console.error(
            `No tests yet: add them in ${TEST_DIR} (see /opt/onedrop/guides/tests.md).`,
        );

        return 2;
    }

    if (!hasPlaywright()) {
        console.error(
            'Add Playwright first: npm install --save-dev @playwright/test@1.63.0',
        );

        return 3;
    }

    const id = new Date()
        .toISOString()
        .replace(/[^0-9]/g, '')
        .slice(0, 17);
    const report = `${DIR}/report-${id}.json`;
    const stepsFile = `${DIR}/steps-${id}.jsonl`;

    writeState({
        ...state,
        running: true,
        pid: process.pid,
        target,
        started_at: new Date().toISOString(),
        finished_at: null,
        error: null,
    });

    // Video needs Playwright's own ffmpeg; the image has the pinned version's, an app on another version fetches its own once.
    spawnSync(PLAYWRIGHT, ['install', 'ffmpeg'], {
        cwd: WORKSPACE,
        stdio: 'ignore',
    });

    const result = spawnSync(
        PLAYWRIGHT,
        ['test', '--config', CONFIG, ...files, ...grep],
        {
            cwd: WORKSPACE,
            stdio: 'inherit',
            env: {
                ...process.env,
                ONEDROP_TESTS_OUTPUT: `${RUNS}/${id}`,
                ONEDROP_TESTS_REPORT: report,
                ONEDROP_TESTS_STEPS: stepsFile,
                NODE_OPTIONS:
                    `${process.env.NODE_OPTIONS ?? ''} --import ${STEPS_PRELOAD}`.trim(),
            },
        },
    );

    let parsed = null;

    try {
        parsed = JSON.parse(readFileSync(report, 'utf8'));
    } catch {
        // No report: Playwright couldn't start (see its output above).
    }

    rmSync(report, { force: true });
    const steps = readSteps(stepsFile);
    rmSync(stepsFile, { force: true });

    const found = list();
    const tests = merge(
        found.tests ?? state.tests,
        state.tests,
        parsed ? results(parsed, id, steps) : {},
    );

    writeState({
        ...state,
        running: false,
        pid: null,
        target,
        started_at: readState().started_at,
        finished_at: new Date().toISOString(),
        error: parsed
            ? (reportError(parsed) ?? found.error)
            : (found.error ?? "The tests couldn't start."),
        tests,
    });
    prune(tests);

    console.log('\nResults and recordings are in the Tests tab.');

    return result.status ?? 1;
}

/** Start a run in its own process and return: the state says it's running before this returns. */
function background(target) {
    const state = currentState();

    if (state.running) {
        console.error('Tests are already running.');

        return 4;
    }

    mkdirSync(DIR, { recursive: true });
    const log = openSync(`${DIR}/run.log`, 'w');
    const child = spawn(
        process.execPath,
        [fileURLToPath(import.meta.url), 'run', ...target],
        {
            cwd: WORKSPACE,
            detached: true,
            stdio: ['ignore', log, log],
        },
    );

    writeState({
        ...state,
        running: true,
        pid: child.pid,
        target,
        started_at: new Date().toISOString(),
        finished_at: null,
        error: null,
    });
    child.unref();

    return 0;
}

function uiState() {
    try {
        const state = JSON.parse(readFileSync(UI_STATE, 'utf8'));

        return alive(state.pid) ? state : null;
    } catch {
        return null;
    }
}

function listening(port) {
    return new Promise((resolve) => {
        const socket = net.connect(port, '127.0.0.1');
        socket.once('connect', () => socket.end(resolve(true)));
        socket.once('error', () => resolve(false));
    });
}

/** Start the runner, or find the one already going, and print its token. */
async function uiStart() {
    if (!hasTests()) {
        console.error(
            `No tests yet: add them in ${TEST_DIR} (see /opt/onedrop/guides/tests.md).`,
        );

        return 2;
    }

    if (!hasPlaywright()) {
        console.error(
            "The tests need Playwright: ask the agent to add @playwright/test to the app's dev dependencies.",
        );

        return 3;
    }

    let state = uiState();

    if (!state) {
        mkdirSync(DIR, { recursive: true });
        const log = openSync(`${DIR}/ui.log`, 'w');
        const child = spawn(
            process.execPath,
            [fileURLToPath(import.meta.url), 'ui', 'serve'],
            {
                cwd: WORKSPACE,
                detached: true,
                stdio: ['ignore', log, log],
            },
        );

        child.unref();
        state = { token: randomBytes(32).toString('hex'), pid: child.pid };
        writeFileSync(UI_STATE, JSON.stringify(state), { mode: 0o600 });
        writeFileSync(UI_SEEN, '');
    }

    for (const started = Date.now(); !(await listening(UI_PORT));) {
        if (!alive(state.pid) || Date.now() - started > UI_START_MS) {
            uiStop();
            console.error(
                `The test runner didn't start:\n${readTail(`${DIR}/ui.log`)}`,
            );

            return 1;
        }

        await new Promise((resolve) => setTimeout(resolve, 250));
    }

    console.log(JSON.stringify({ token: state.token }));

    return 0;
}

/** Run Playwright's UI mode until it's closed or goes unused for UI_IDLE_MS (the detached process uiStart starts). */
function uiServe() {
    spawnSync(PLAYWRIGHT, ['install', 'ffmpeg'], {
        cwd: WORKSPACE,
        stdio: 'ignore',
    });

    // Its own output folder: runs/ is pruned after each run of the Tests tab.
    const ui = spawn(
        PLAYWRIGHT,
        [
            'test',
            '--config',
            CONFIG,
            '--ui-host',
            '127.0.0.1',
            '--ui-port',
            String(UI_PORT),
        ],
        {
            cwd: WORKSPACE,
            stdio: 'inherit',
            env: { ...process.env, ONEDROP_TESTS_OUTPUT: `${DIR}/ui` },
        },
    );
    const idle = setInterval(() => {
        let seen = 0;

        try {
            seen = statSync(UI_SEEN).mtimeMs;
        } catch {
            // Never used: counts from now.
        }

        if (Date.now() - seen > UI_IDLE_MS) {
            ui.kill();
        }
    }, 60_000);

    ui.on('exit', () => {
        clearInterval(idle);

        // Only this runner's own state: a second start that lost the race mustn't remove the first's token.
        if (uiOwner() === process.pid) {
            rmSync(UI_STATE, { force: true });
            rmSync(UI_SEEN, { force: true });
        }
    });
}

function uiOwner() {
    try {
        return JSON.parse(readFileSync(UI_STATE, 'utf8')).pid;
    } catch {
        return null;
    }
}

function uiStop() {
    const state = uiState();

    if (state) {
        try {
            process.kill(-state.pid);
        } catch {
            // Already gone.
        }
    }

    rmSync(UI_STATE, { force: true });
    rmSync(UI_SEEN, { force: true });

    return 0;
}

function readTail(file) {
    try {
        return readFileSync(file, 'utf8').replace(ANSI, '').slice(-MAX_ERROR);
    } catch {
        return '';
    }
}

const [command, ...args] = process.argv.slice(2);

if (command === 'status') {
    console.log(JSON.stringify(status(args.includes('--cached'))));
} else if (command === 'run') {
    const target = args.filter((arg) => arg !== '--background');

    process.exitCode = args.includes('--background')
        ? background(target)
        : run(target);
} else if (command === 'ui' && args[0] === 'start') {
    process.exitCode = await uiStart();
} else if (command === 'ui' && args[0] === 'serve') {
    uiServe();
} else if (command === 'ui' && args[0] === 'stop') {
    process.exitCode = uiStop();
} else {
    console.error(
        'Usage: tests.mjs status [--cached] | run [--background] [file | file:line | @TAG ...] | ui start|stop',
    );
    process.exitCode = 64;
}
