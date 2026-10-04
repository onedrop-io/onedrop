#!/usr/bin/env node
// The demo video (DEMO-001..003): re-runs the tests the storyboard (/workspace/.onedrop/demo.json) names, slowed down,
// records their pages over the debugging protocol, and composes the recording into an MP4 with Remotion
// (demo-video/, the composition; /opt/onedrop/remotion, its packages).
//
//   node demo.mjs status                       JSON: the storyboard, the latest video, and the render going, if any
//   node demo.mjs render [--url <address>]     render now, printing progress; --url goes on the end card
//   node demo.mjs render --background [...]    start a render and return at once (Tools → Demo)
//
// Renders keep only the latest video, in /workspace/.onedrop/demo (never committed, see checkpoint). The Tests tab's
// results aren't touched: the tests run with their own output folder and report.
import { spawn, spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import {
    copyFileSync,
    existsSync,
    mkdirSync,
    openSync,
    readFileSync,
    readdirSync,
    renameSync,
    rmSync,
    statSync,
    writeFileSync,
} from 'node:fs';
import http from 'node:http';
import { createRequire } from 'node:module';
import net from 'node:net';
import os from 'node:os';
import { extname, join, normalize } from 'node:path';
import { fileURLToPath } from 'node:url';

const WORKSPACE = process.env.ONEDROP_WORKSPACE || '/workspace';
const STORYBOARD = `${WORKSPACE}/.onedrop/demo.json`;
const DIR = `${WORKSPACE}/.onedrop/demo`;
const STATE = `${DIR}/state.json`;
const VIDEO = `${DIR}/demo.mp4`;
// Frames and the tests' output: outside the workspace, so thousands of files don't reach the Files panel's watcher.
const WORK = process.env.ONEDROP_DEMO_WORK || '/tmp/onedrop-demo';
const LOG = `${DIR}/render.log`;
const TESTS_STATE = `${WORKSPACE}/.onedrop/tests/state.json`;
const PLAYWRIGHT = `${WORKSPACE}/node_modules/.bin/playwright`;
const CONFIG = '/opt/onedrop/playwright.config.mjs';
const PRELOAD = '/opt/onedrop/test-steps.mjs';
const TESTS = '/opt/onedrop/tests.mjs';
const REMOTION = process.env.ONEDROP_REMOTION || '/opt/onedrop/remotion';
const COMPOSITION = process.env.ONEDROP_DEMO_VIDEO || '/opt/onedrop/demo-video';
const CHROMIUM = '/usr/bin/chromium';
// The debugging ports host-proxy.mjs reserves (shared with browser.mjs): the first free one is used.
const CDP_PORTS = Array.from({ length: 10 }, (_, i) => 9224 + i);
const BINDING = '__onedropDemo';

// Each action waits this long first; a scene holds its final state for a second (test-steps.mjs).
const PACE_MS = 700;
const HOLD_MS = 1000;
// Longer stretches with nothing happening are cut to this; the whole video stays under MAX_MS.
const MAX_GAP_MS = 1500;
const MAX_MS = 180_000;
const MIN_SCENE_MS = 2000;
// The title and end cards, as the composition draws them.
const INTRO_MS = 3000;
const OUTRO_MS = 3000;
const TEST_TIMEOUT_MS = 120_000;
const RECORD_TIMEOUT_MS = 15 * 60 * 1000;
const MAX_SCENES = 30;
const MAX_ERROR = 4000;

process.env.PLAYWRIGHT_BROWSERS_PATH ||= '/opt/onedrop/playwright';

// eslint-disable-next-line no-control-regex
const ANSI = /\x1b\[[0-9;?]*[ -/]*[@-~]/g;

const EMPTY = {
    running: false,
    pid: null,
    phase: null,
    progress: 0,
    started_at: null,
    finished_at: null,
    error: null,
    video: null,
};

/** Reports each mouse move and press, and each field focused, to the recorder, with the page's size (positions are kept as fractions). */
const MOUSE_SCRIPT = `(() => {
    if (window.__onedropDemoMouse) return;
    window.__onedropDemoMouse = true;
    const send = (type, event) => {
        try {
            window.${BINDING}(JSON.stringify({ type, x: event.clientX, y: event.clientY, w: innerWidth, h: innerHeight, t: Date.now() }));
        } catch {}
    };
    addEventListener('mousemove', (event) => send('move', event), true);
    addEventListener('mousedown', (event) => send('down', event), true);
    // Typing into a field (fill) doesn't move the mouse: point at the field instead.
    addEventListener('focusin', (event) => {
        const box = event.target?.getBoundingClientRect?.();
        if (box && box.width > 0) send('focus', { clientX: box.left + Math.min(box.width / 2, 40), clientY: box.top + box.height / 2 });
    }, true);
})();`;

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

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
    renameSync(`${STATE}.tmp`, STATE);
}

function updateState(changes) {
    writeState({ ...readState(), ...changes });
}

function alive(pid) {
    try {
        return !!pid && process.kill(pid, 0);
    } catch {
        return false;
    }
}

/** The state, with a render whose process is gone (killed, sandbox restarted) counted as stopped. */
function currentState() {
    const state = readState();

    return state.running && !alive(state.pid)
        ? {
              ...state,
              running: false,
              pid: null,
              phase: null,
              error: state.error ?? 'The render stopped before it finished.',
          }
        : state;
}

/**
 * The storyboard, tidied: a title, a tagline, an optional accent color and address, and its scenes, each a test
 * (by file and title) with a caption. Throws when the file isn't valid JSON.
 */
function readStoryboard() {
    if (!existsSync(STORYBOARD)) {
        return null;
    }

    const raw = JSON.parse(readFileSync(STORYBOARD, 'utf8'));
    const text = (value, max) =>
        typeof value === 'string' ? value.trim().slice(0, max) : '';

    return {
        title: text(raw?.title, 80),
        tagline: text(raw?.tagline, 160),
        accent: /^#[0-9a-f]{6}$/i.test(raw?.accent ?? '') ? raw.accent : null,
        url: /^https?:\/\/\S+$/.test(raw?.url ?? '') ? raw.url : null,
        scenes: (Array.isArray(raw?.scenes) ? raw.scenes : [])
            .filter((scene) => scene && typeof scene === 'object')
            .map((scene) => ({
                file: text(scene.file, 300),
                title: text(scene.title, 500),
                caption: text(scene.caption, 140),
            }))
            .filter((scene) => scene.file && scene.title)
            .slice(0, MAX_SCENES),
    };
}

function videoInfo() {
    try {
        const { size } = statSync(VIDEO);

        return {
            ...readState().video,
            path: '.onedrop/demo/demo.mp4',
            size,
        };
    } catch {
        return null;
    }
}

function status() {
    const state = currentState();
    let storyboard = null;
    let storyboardError = null;

    try {
        storyboard = readStoryboard();
    } catch (error) {
        storyboardError = `.onedrop/demo.json isn't valid JSON: ${error.message}`;
    }

    return {
        running: state.running,
        phase: state.running ? state.phase : null,
        progress: state.running ? state.progress : 0,
        started_at: state.started_at,
        finished_at: state.finished_at,
        error: state.error,
        video: videoInfo(),
        storyboard,
        storyboard_error: storyboardError,
    };
}

/** The app's tests, as tests.mjs finds them. */
function listTests() {
    const result = spawnSync(process.execPath, [TESTS, 'status'], {
        cwd: WORKSPACE,
        encoding: 'utf8',
        maxBuffer: 64 * 1024 * 1024,
    });
    const state = JSON.parse(result.stdout || '{}');

    if (!state.tests?.length && state.error) {
        throw new Error(state.error);
    }

    return state.tests ?? [];
}

/** The test a scene names: by file and title, else by its title alone (the test moved to another file). */
function findTest(tests, scene) {
    const last = (title) => title.split(' › ').at(-1);

    return (
        tests.find((t) => t.file === scene.file && t.title === scene.title) ??
        tests.find(
            (t) => t.file === scene.file && last(t.title) === last(scene.title),
        ) ??
        tests.find((t) => t.title === scene.title) ??
        null
    );
}

function listening(port) {
    return new Promise((resolve) => {
        const socket = net.connect(port, '127.0.0.1');
        socket.once('connect', () => socket.end(resolve(true)));
        socket.once('error', () => resolve(false));
    });
}

async function freePort() {
    for (const port of CDP_PORTS) {
        if (!(await listening(port))) {
            return port;
        }
    }

    throw new Error(
        'No debugging port is free: close the Browser tab and try again.',
    );
}

/**
 * Record the pages of the browser listening on `port`, until stop(): each screencast frame (a JPEG in `dir`, with
 * the time it was drawn), each mouse move and press, and each address the page goes to. Only the newest open page is
 * recorded. Attaching doesn't hold the pages up: the test's own Playwright drives them as usual.
 */
function record(port, dir) {
    const frames = [];
    const mouse = [];
    const urls = [];
    const attached = new Set();
    // Open pages, oldest first, and the session watching each.
    const pages = [];
    const sessions = new Map();
    const pending = new Map();
    let ws = null;
    let stopped = false;
    let id = 0;

    const current = () => pages.at(-1) ?? null;

    const send = (method, params = {}, sessionId) =>
        new Promise((resolve) => {
            if (!ws || ws.readyState !== 1) {
                return resolve(null);
            }

            const n = ++id;
            pending.set(n, resolve);
            ws.send(JSON.stringify({ id: n, method, params, sessionId }));
        });

    const attach = async (targetId, url) => {
        attached.add(targetId);
        const result = await send('Target.attachToTarget', {
            targetId,
            flatten: true,
        });
        const sessionId = result?.sessionId;

        if (!sessionId) {
            return;
        }

        sessions.set(sessionId, targetId);
        pages.push(targetId);
        urls.push({ t: Date.now(), url });
        // The page's calls to the binding only arrive with Runtime on.
        await send('Runtime.enable', {}, sessionId);
        await send('Runtime.addBinding', { name: BINDING }, sessionId);
        await send('Page.enable', {}, sessionId);
        await send(
            'Page.addScriptToEvaluateOnNewDocument',
            { source: MOUSE_SCRIPT },
            sessionId,
        );
        await send('Runtime.evaluate', { expression: MOUSE_SCRIPT }, sessionId);
        await send(
            'Page.startScreencast',
            { format: 'jpeg', quality: 85, maxWidth: 1920, maxHeight: 1080 },
            sessionId,
        );
    };

    const onMessage = (message) => {
        if (message.id !== undefined) {
            pending.get(message.id)?.(message.result ?? null);
            pending.delete(message.id);

            return;
        }

        const { method, params = {}, sessionId } = message;
        const info = params.targetInfo;

        if (
            (method === 'Target.targetCreated' ||
                method === 'Target.targetInfoChanged') &&
            info?.type === 'page'
        ) {
            if (!attached.has(info.targetId)) {
                void attach(info.targetId, info.url);
            } else if (info.targetId === current()) {
                urls.push({ t: Date.now(), url: info.url });
            }
        } else if (method === 'Target.targetDestroyed') {
            const index = pages.indexOf(params.targetId);

            if (index >= 0) {
                pages.splice(index, 1);
            }
        } else if (method === 'Page.screencastFrame') {
            void send(
                'Page.screencastFrameAck',
                { sessionId: params.sessionId },
                sessionId,
            );

            if (sessions.get(sessionId) === current()) {
                const file = `frames/${String(frames.length).padStart(6, '0')}.jpg`;
                writeFileSync(
                    join(dir, file),
                    Buffer.from(params.data, 'base64'),
                );
                frames.push({
                    file,
                    t: params.metadata?.timestamp
                        ? Math.round(params.metadata.timestamp * 1000)
                        : Date.now(),
                });
            }
        } else if (
            method === 'Runtime.bindingCalled' &&
            params.name === BINDING &&
            sessions.get(sessionId) === current()
        ) {
            try {
                const event = JSON.parse(params.payload);

                if (event.w > 0 && event.h > 0) {
                    mouse.push({
                        t: event.t,
                        type: ['down', 'focus'].includes(event.type)
                            ? event.type
                            : 'move',
                        x: event.x / event.w,
                        y: event.y / event.h,
                    });
                }
            } catch {
                // Not ours.
            }
        }
    };

    // The browser comes and goes (Playwright starts a new one after a test fails): keep finding it.
    const connect = async () => {
        while (!stopped) {
            const address = await fetch(`http://127.0.0.1:${port}/json/version`)
                .then((response) => response.json())
                .then((version) => version.webSocketDebuggerUrl)
                .catch(() => null);

            if (!address) {
                await sleep(100);
                continue;
            }

            await new Promise((resolve) => {
                ws = new WebSocket(address);
                ws.onopen = () =>
                    void send('Target.setDiscoverTargets', { discover: true });
                ws.onmessage = (event) => {
                    try {
                        onMessage(JSON.parse(String(event.data)));
                    } catch {
                        // A frame we couldn't keep; the next one will do.
                    }
                };
                ws.onerror = () => {};
                ws.onclose = () => {
                    for (const resolvePending of pending.values()) {
                        resolvePending(null);
                    }

                    pending.clear();
                    pages.length = 0;
                    resolve();
                };
            });
        }
    };

    const running = connect();

    return {
        frames,
        mouse,
        urls,
        async stop() {
            stopped = true;
            ws?.close();
            await running;
        },
    };
}

/** Each test's results in a Playwright JSON report, by test id. */
function reportResults(report) {
    const byId = {};
    const walk = (suite) => {
        for (const spec of suite.specs ?? []) {
            const result = spec.tests?.[0]?.results?.at(-1);

            byId[spec.id] = {
                status: result?.status ?? 'skipped',
                error: (result?.errors ?? [])
                    .map((error) => error.message ?? '')
                    .join('\n\n')
                    .replace(ANSI, '')
                    .trim()
                    .slice(0, MAX_ERROR),
            };
        }

        (suite.suites ?? []).forEach(walk);
    };

    (report.suites ?? []).forEach(walk);

    return byId;
}

/** The timeline test-steps.mjs wrote, by test id. */
function readTimeline(file) {
    const byTest = {};

    try {
        for (const line of readFileSync(file, 'utf8').split('\n')) {
            const entry = line ? JSON.parse(line) : null;

            if (entry) {
                (byTest[entry.test] ??= []).push(entry);
            }
        }
    } catch {
        // Nothing recorded.
    }

    return byTest;
}

/** The address bar's text: the path, under the published host when there is one. */
function shownAddress(url, published) {
    try {
        const { pathname, search } = new URL(url);

        if (url.startsWith('about:')) {
            return null;
        }

        return `${published ? new URL(published).host : ''}${pathname}${search}`;
    } catch {
        return null;
    }
}

/**
 * One scene: the test's part of the recording, from the end of its first step (the page has loaded) to a second
 * after its last, with long still stretches cut short. Times are ms from the scene's start.
 */
function cutScene(caption, entries, recording, published) {
    const step = (kind) => entries.filter((e) => e.kind === kind);
    const start = step('stepEnd')[0]?.at ?? step('step')[0]?.at;
    const end =
        (step('end').at(-1)?.at ?? step('stepEnd').at(-1)?.at ?? start) +
        HOLD_MS;

    if (start === undefined) {
        return null;
    }

    const within = (list) => {
        const before = list.filter((item) => item.t < start).at(-1);
        const inside = list.filter((item) => item.t >= start && item.t <= end);

        return before ? [{ ...before, t: start }, ...inside] : inside;
    };
    const frames = within(recording.frames);
    // Not the mouse from before: that was the previous test's page.
    const mouse = recording.mouse.filter((m) => m.t >= start && m.t <= end);
    const urls = within(recording.urls);

    if (frames.length === 0) {
        return null;
    }

    // Moments something happened; the time between two of them is kept up to MAX_GAP_MS.
    const moments = [
        ...new Set(
            [
                start,
                end,
                ...frames.map((f) => f.t),
                ...mouse.map((m) => m.t),
                ...entries.map((e) => e.at),
            ].filter((t) => t >= start && t <= end),
        ),
    ].sort((a, b) => a - b);
    const knots = [];

    for (const t of moments) {
        const previous = knots.at(-1);
        knots.push({
            src: t,
            out: previous
                ? previous.out + Math.min(t - previous.src, MAX_GAP_MS)
                : 0,
        });
    }

    const at = (t) => {
        let k = 0;

        while (k + 1 < knots.length && knots[k + 1].src <= t) {
            k++;
        }

        const next = knots[k + 1];

        return Math.round(
            knots[k].out +
                Math.min(t - knots[k].src, next ? next.out - knots[k].out : 0),
        );
    };

    return {
        caption,
        durationMs: at(end),
        frames: frames.map((f) => ({ src: f.file, t: at(f.t) })),
        mouse: mouse.map((m) => ({ ...m, t: at(m.t) })),
        urls: urls
            .map((u) => ({
                t: at(u.t),
                address: shownAddress(u.url, published),
            }))
            .filter((u) => u.address !== null),
    };
}

/** Serve the work folder (frames, icon) to the render. */
function serve(root) {
    const types = {
        '.jpg': 'image/jpeg',
        '.png': 'image/png',
        '.svg': 'image/svg+xml',
        '.ico': 'image/x-icon',
        '.webp': 'image/webp',
    };
    const server = http.createServer((request, response) => {
        const path = normalize(
            decodeURIComponent(new URL(request.url, 'http://x').pathname),
        ).replace(/^(\.\.[/\\])+/, '');

        try {
            const bytes = readFileSync(join(root, path));
            response.writeHead(200, {
                'Content-Type':
                    types[extname(path)] ?? 'application/octet-stream',
                'Access-Control-Allow-Origin': '*',
            });
            response.end(bytes);
        } catch {
            response.writeHead(404).end();
        }
    });

    return new Promise((resolve) =>
        server.listen(0, '127.0.0.1', () => resolve(server)),
    );
}

/** The app's icon (Tools → App Icon saves public/favicon.svg), copied next to the frames. */
function copyIcon() {
    for (const name of [
        'favicon.svg',
        'favicon.png',
        'apple-touch-icon.png',
        'icon.svg',
        'icon.png',
        'favicon.ico',
    ]) {
        const source = `${WORKSPACE}/public/${name}`;

        if (existsSync(source)) {
            copyFileSync(source, `${WORK}/icon${extname(name)}`);

            return `icon${extname(name)}`;
        }
    }

    return null;
}

/** The composition's bundle, built once per version of its source. */
async function bundled(require) {
    const hash = createHash('sha1');

    for (const file of readdirSync(COMPOSITION, { recursive: true })
        .map(String)
        .sort((a, b) => a.localeCompare(b))) {
        const path = join(COMPOSITION, String(file));

        if (statSync(path).isFile()) {
            hash.update(String(file)).update(readFileSync(path));
        }
    }

    const outDir = join(
        os.tmpdir(),
        `onedrop-demo-${hash.digest('hex').slice(0, 16)}`,
    );

    if (existsSync(join(outDir, 'index.html'))) {
        return outDir;
    }

    rmSync(outDir, { recursive: true, force: true });
    const { bundle } = require('@remotion/bundler');

    return bundle({
        entryPoint: join(COMPOSITION, 'src/index.ts'),
        outDir,
        // The composition's imports (remotion, react) come from the image's packages.
        webpackOverride: (config) => ({
            ...config,
            resolve: {
                ...config.resolve,
                modules: [join(REMOTION, 'node_modules'), 'node_modules'],
            },
        }),
    });
}

class RenderError extends Error {}

async function render(url) {
    const state = currentState();

    if (state.running && state.pid !== process.pid) {
        console.error('A demo is already rendering.');

        return 4;
    }

    try {
        const tests = JSON.parse(readFileSync(TESTS_STATE, 'utf8'));

        if (tests.running && alive(tests.pid)) {
            console.error(
                'The tests are running: render the demo once they finish.',
            );

            return 5;
        }
    } catch {
        // No tests have run yet.
    }

    if (!existsSync(join(REMOTION, 'node_modules/@remotion/renderer'))) {
        console.error(
            "This sandbox can't render demos yet: it moves to a new one with what's needed once the project sits unused.",
        );

        return 6;
    }

    let storyboard;

    try {
        storyboard = readStoryboard();
    } catch (error) {
        console.error(`.onedrop/demo.json isn't valid JSON: ${error.message}`);

        return 2;
    }

    if (!storyboard?.scenes.length) {
        console.error(
            'The storyboard has no scenes yet: pick some in Tools → Demo, or write .onedrop/demo.json (see /opt/onedrop/guides/demo.md).',
        );

        return 2;
    }

    if (!existsSync(PLAYWRIGHT)) {
        console.error(
            'Add Playwright first: npm install --save-dev @playwright/test@1.63.0',
        );

        return 3;
    }

    writeState({
        ...state,
        running: true,
        pid: process.pid,
        phase: 'recording',
        progress: 0,
        started_at: new Date().toISOString(),
        finished_at: null,
        error: null,
    });

    let server = null;

    try {
        const published = storyboard.url ?? url ?? null;
        const recorded = await recordScenes(storyboard, published);

        updateState({ phase: 'rendering', progress: 0 });
        console.log('Rendering the video…');

        const require = createRequire(join(REMOTION, 'package.json'));
        const {
            selectComposition,
            renderMedia,
        } = require('@remotion/renderer');
        server = await serve(WORK);
        const inputProps = {
            title: storyboard.title,
            tagline: storyboard.tagline,
            accent: storyboard.accent ?? '#6366f1',
            address: published
                ? published.replace(/^https?:\/\//, '').replace(/\/$/, '')
                : null,
            icon: copyIcon(),
            base: `http://127.0.0.1:${server.address().port}/`,
            scenes: recorded,
        };
        const serveUrl = await bundled(require);
        const browser = {
            browserExecutable: CHROMIUM,
            chromiumOptions: { enableMultiProcessOnLinux: true },
            logLevel: 'warn',
        };
        const composition = await selectComposition({
            serveUrl,
            id: 'Demo',
            inputProps,
            ...browser,
        });
        let reported = 0;

        await renderMedia({
            serveUrl,
            composition,
            inputProps,
            codec: 'h264',
            crf: 20,
            imageFormat: 'jpeg',
            jpegQuality: 90,
            outputLocation: `${WORK}/demo.mp4`,
            concurrency: Math.max(
                1,
                Math.min(4, Math.floor(os.cpus().length / 2)),
            ),
            onProgress: ({ progress }) => {
                if (progress - reported >= 0.01 || progress === 1) {
                    reported = progress;
                    updateState({ progress });
                }
            },
            ...browser,
        });

        // Copied, not moved: the work folder may be on another disk.
        copyFileSync(`${WORK}/demo.mp4`, `${VIDEO}.tmp`);
        renameSync(`${VIDEO}.tmp`, VIDEO);
        updateState({
            running: false,
            pid: null,
            phase: null,
            progress: 0,
            finished_at: new Date().toISOString(),
            error: null,
            video: {
                rendered_at: new Date().toISOString(),
                duration_ms: Math.round(
                    (composition.durationInFrames / composition.fps) * 1000,
                ),
                scenes: recorded.length,
            },
        });
        console.log('\nThe demo is in Tools → Demo.');

        return 0;
    } catch (error) {
        const message =
            error instanceof RenderError
                ? error.message
                : `The demo couldn't be rendered: ${String(error?.message ?? error).replace(ANSI, '')}`;

        updateState({
            running: false,
            pid: null,
            phase: null,
            progress: 0,
            finished_at: new Date().toISOString(),
            error: message.slice(0, MAX_ERROR),
        });
        console.error(message);

        return 1;
    } finally {
        server?.close();
        rmSync(WORK, { recursive: true, force: true });
    }
}

/** Run the storyboard's tests in demo mode, recording them, and cut each scene out of the recording. */
async function recordScenes(storyboard, published) {
    const tests = listTests();
    const scenes = storyboard.scenes.map((scene, index) => {
        const test = findTest(tests, scene);

        if (!test) {
            throw new RenderError(
                `Scene ${index + 1}'s test "${scene.title}" no longer exists in ${scene.file}.`,
            );
        }

        return { ...scene, test };
    });
    const targets = [
        ...new Set(scenes.map((s) => `${s.test.file}:${s.test.line}`)),
    ];

    rmSync(WORK, { recursive: true, force: true });
    mkdirSync(`${WORK}/frames`, { recursive: true });
    const port = await freePort();
    const timeline = `${WORK}/timeline.jsonl`;
    const report = `${WORK}/report.json`;

    console.log(
        `Recording ${targets.length} test${targets.length === 1 ? '' : 's'}…`,
    );

    const log = openSync(LOG, 'w');
    const child = spawn(
        PLAYWRIGHT,
        [
            'test',
            '--config',
            CONFIG,
            '--timeout',
            String(TEST_TIMEOUT_MS),
            ...targets,
        ],
        {
            cwd: WORKSPACE,
            stdio: ['ignore', log, log],
            env: {
                ...process.env,
                ONEDROP_DEMO: '1',
                ONEDROP_BROWSER_CDP_PORT: String(port),
                ONEDROP_DEMO_TIMELINE: timeline,
                ONEDROP_DEMO_PACE: String(PACE_MS),
                ONEDROP_TESTS_REPORT: report,
                ONEDROP_TESTS_OUTPUT: `${WORK}/output`,
                NODE_OPTIONS:
                    `${process.env.NODE_OPTIONS ?? ''} --import ${PRELOAD}`.trim(),
            },
        },
    );
    const recording = record(port, WORK);
    const progress = setInterval(() => {
        const ended = Object.values(readTimeline(timeline)).filter((entries) =>
            entries.some((e) => e.kind === 'end'),
        ).length;

        updateState({ progress: Math.min(1, ended / targets.length) });
    }, 1000);
    const timeout = setTimeout(() => child.kill('SIGKILL'), RECORD_TIMEOUT_MS);

    await new Promise((resolve) => child.on('exit', resolve));
    clearInterval(progress);
    clearTimeout(timeout);
    await recording.stop();

    let results = {};

    try {
        results = reportResults(JSON.parse(readFileSync(report, 'utf8')));
    } catch {
        throw new RenderError(
            `The tests couldn't start:\n${readFileSync(LOG, 'utf8').replace(ANSI, '').slice(-MAX_ERROR)}`,
        );
    }

    const byTest = readTimeline(timeline);
    const cut = [];
    let total = INTRO_MS + OUTRO_MS;

    for (const [index, scene] of scenes.entries()) {
        const result = results[scene.test.id];
        const name = scene.caption || scene.title;

        if (result?.status !== 'passed') {
            throw new RenderError(
                result
                    ? `Scene ${index + 1} ("${name}") failed, so it can't be shown: ${result.error || result.status}`
                    : `Scene ${index + 1} ("${name}") didn't run.`,
            );
        }

        const part = cutScene(
            scene.caption,
            byTest[scene.test.id] ?? [],
            recording,
            published,
        );

        if (!part) {
            throw new RenderError(
                `Scene ${index + 1} ("${name}") wasn't recorded.`,
            );
        }

        // Keep the video under MAX_MS: shorten the scene that crosses it, and leave out the rest.
        const room = MAX_MS - total;

        if (room < MIN_SCENE_MS) {
            break;
        }

        part.durationMs = Math.min(part.durationMs, room);
        total += part.durationMs;
        cut.push(part);
    }

    return cut;
}

/** Start a render in its own process and return: the state says it's running before this returns. */
function background(args) {
    const state = currentState();

    if (state.running) {
        console.error('A demo is already rendering.');

        return 4;
    }

    mkdirSync(DIR, { recursive: true });
    const log = openSync(`${DIR}/background.log`, 'w');
    const child = spawn(
        process.execPath,
        [fileURLToPath(import.meta.url), 'render', ...args],
        { cwd: WORKSPACE, detached: true, stdio: ['ignore', log, log] },
    );

    writeState({
        ...state,
        running: true,
        pid: child.pid,
        phase: 'recording',
        progress: 0,
        started_at: new Date().toISOString(),
        finished_at: null,
        error: null,
    });
    child.unref();

    return 0;
}

const [command, ...args] = process.argv.slice(2);
const urlIndex = args.indexOf('--url');
const url =
    urlIndex >= 0 && /^https?:\/\/\S+$/.test(args[urlIndex + 1] ?? '')
        ? args[urlIndex + 1]
        : null;

if (command === 'status') {
    console.log(JSON.stringify(status()));
} else if (command === 'render' && args.includes('--background')) {
    process.exitCode = background(args.filter((arg) => arg !== '--background'));
} else if (command === 'render') {
    process.exitCode = await render(url);
} else {
    console.error(
        'Usage: demo.mjs status | render [--background] [--url <address>]',
    );
    process.exitCode = 64;
}
