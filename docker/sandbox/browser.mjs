#!/usr/bin/env node
// The browser (TEST-005): run one of the app's tests up to a step, stop it there with its browser still open,
// and let the user use that page from the workspace: its screen is streamed to a viewer (a canvas) and their mouse
// and keyboard are sent back, over the debugging protocol. The page keeps talking to the dev server, so the agent's
// changes reach it through hot reload while the user and the agent work on it together.
//
//   node browser.mjs start <file:line> <step>   stop that test after its <step>th step (0: before the first), print JSON
//                                               {token} at once; `status` says when the page is ready
//   node browser.mjs stop                       close the browser
//   node browser.mjs status                     JSON: open, starting, error, its page's address and title, where it stopped
//   node browser.mjs screenshot <file>          save what the user sees (for the agent)
//   node browser.mjs reload                     reload the page (for the agent; in-page state is lost)
//   node browser.mjs resume step|end            carry on with the test: one more step, or to its end (TEST-006)
//
// host-proxy.mjs serves the viewer under /__onedrop/browser/ on the preview's address to holders of the token.
// test-steps.mjs does the stopping, inside the test's worker. The browser closes after 30 minutes unwatched.
import { spawn } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import {
    existsSync,
    openSync,
    readFileSync,
    renameSync,
    rmSync,
    writeFileSync,
} from 'node:fs';
import http from 'node:http';
import net from 'node:net';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const WORKSPACE = process.env.ONEDROP_WORKSPACE || '/workspace';
const PLAYWRIGHT = `${WORKSPACE}/node_modules/.bin/playwright`;
const CONFIG = '/opt/onedrop/playwright.config.mjs';
const PRELOAD = '/opt/onedrop/test-steps.mjs';
const STATE = process.env.ONEDROP_BROWSER_STATE || '/tmp/onedrop-browser.json';
const HELD = '/tmp/onedrop-browser-held.json';
// How far a resumed test goes (test-steps.mjs reads it on waking), and how long it waits before each step so the
// user can follow it (TEST-006).
const CONTROL = '/tmp/onedrop-browser-control.json';
const PACE_MS = 500;
const LOG = '/tmp/onedrop-browser.log';
const PORT = Number(process.env.ONEDROP_BROWSER_PORT || 9331);
// The test's browser opens its debugging port on the first free one of these (host-proxy.mjs reserves them all), so
// a browser left over from an earlier session can never be the one attached to.
const CDP_PORTS = Array.from({ length: 10 }, (_, i) => 9224 + i);
const START_MS = 90_000;
const IDLE_MS = 30 * 60 * 1000;
const TARGET = /^tests\/e2e\/[\w./-]+\.(spec|test)\.[cm]?[jt]sx?:\d+$/;

// eslint-disable-next-line no-control-regex
const ANSI = /\x1b\[[0-9;?]*[ -/]*[@-~]/g;

process.env.PLAYWRIGHT_BROWSERS_PATH ||= '/opt/onedrop/playwright';

function readState() {
    try {
        return JSON.parse(readFileSync(STATE, 'utf8'));
    } catch {
        return null;
    }
}

function writeState(state) {
    writeFileSync(`${STATE}.tmp`, JSON.stringify(state), { mode: 0o600 });
    renameSync(`${STATE}.tmp`, STATE);
}

function updateState(changes) {
    const state = readState();

    if (state && state.pid === process.pid) {
        writeState({ ...state, ...changes });
    }
}

function alive(pid) {
    try {
        return !!pid && process.kill(pid, 0);
    } catch {
        return false;
    }
}

function logTail() {
    try {
        return readFileSync(LOG, 'utf8')
            .replace(ANSI, '')
            .trim()
            .split('\n')
            .slice(-25)
            .join('\n');
    } catch {
        return '';
    }
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/** Start the browser on a test's step, and print the token that opens it. */
async function start(target, step) {
    if (!TARGET.test(target ?? '') || !Number.isInteger(step) || step < 0) {
        console.error(
            'Usage: browser.mjs start tests/e2e/<file>:<line> <step>',
        );

        return 64;
    }

    if (!existsSync(PLAYWRIGHT)) {
        console.error(
            "The tests need Playwright: ask the agent to add @playwright/test to the app's dev dependencies.",
        );

        return 3;
    }

    // Only one at a time: it uses the browser's one debugging port.
    const previous = readState()?.pid;
    stop();

    for (let waited = 0; alive(previous) && waited < 10_000; waited += 200) {
        await sleep(200);
    }

    const token = randomBytes(32).toString('hex');
    const child = spawn(
        process.execPath,
        [fileURLToPath(import.meta.url), 'serve', target, String(step)],
        {
            cwd: WORKSPACE,
            detached: true,
            stdio: ['ignore', openSync(LOG, 'w'), openSync(LOG, 'a')],
        },
    );

    child.unref();
    writeState({
        token,
        pid: child.pid,
        target,
        step,
        ready: false,
        error: null,
        started_at: new Date().toISOString(),
    });
    // Getting to the step takes as long as the test does: `status` says when it's ready.
    console.log(JSON.stringify({ token }));

    return 0;
}

/** Whether the browser is open, still getting to its step, or failed to. */
function status() {
    const state = readState();

    if (!state) {
        return { open: false, starting: false, error: null };
    }

    const running = alive(state.pid);
    const late =
        !state.ready && Date.now() - Date.parse(state.started_at) > START_MS;
    const error =
        state.error ??
        (late
            ? `The test didn't reach that step in time:\n${logTail()}`
            : !running && !state.ready
              ? `The browser didn't start:\n${logTail()}`
              : null);

    if (late && running) {
        stop();
    }

    return {
        open: !!state.ready && running,
        starting: !state.ready && running && !error,
        error: state.ready && !running ? null : error,
        url: state.url ?? null,
        title: state.title ?? null,
        test: state.target,
        step: state.step,
        playback: state.playback ?? null,
    };
}

function stop() {
    const state = readState();

    if (state?.pid && alive(state.pid)) {
        try {
            process.kill(state.pid, 'SIGTERM');
        } catch {
            // Already gone.
        }
    }

    rmSync(STATE, { force: true });

    return 0;
}

/** The supervisor: runs the test to its step, attaches to its browser, and serves the viewer until it's done. */
async function serve(target, step) {
    const { chromium } = createRequire(`${WORKSPACE}/package.json`)(
        'playwright-core',
    );
    const { wsServer } = createRequire(`${WORKSPACE}/package.json`)(
        'playwright-core/lib/utilsBundle',
    );

    rmSync(HELD, { force: true });
    rmSync(CONTROL, { force: true });

    const cdpPort = await freePort();

    if (!cdpPort) {
        updateState({
            error: 'The browser has no free debugging port. Close it and try again.',
        });

        return;
    }

    const test = spawn(
        PLAYWRIGHT,
        ['test', '--config', CONFIG, '--reporter=list', target],
        {
            cwd: WORKSPACE,
            stdio: 'inherit',
            env: {
                ...process.env,
                ONEDROP_BROWSER_CDP_PORT: String(cdpPort),
                ONEDROP_BROWSER_AFTER: String(step),
                ONEDROP_BROWSER_HELD: HELD,
                ONEDROP_BROWSER_CONTROL: CONTROL,
                ONEDROP_TESTS_OUTPUT: '/tmp/onedrop-browser-output',
                NODE_OPTIONS:
                    `${process.env.NODE_OPTIONS ?? ''} --import ${PRELOAD}`.trim(),
            },
        },
    );
    let browser = null;
    let testDone = false;
    let resume = () => false;
    let pauses = null;

    const shutdown = async () => {
        clearInterval(idleTimer);
        clearInterval(pauses);

        // The browser runs in its own process group: close it through the protocol, then everything else.
        try {
            const cdp = browser ? await browser.newBrowserCDPSession() : null;
            await cdp?.send('Browser.close');
        } catch {
            // Already closed.
        }

        try {
            process.kill(-process.pid, 'SIGKILL');
        } catch {
            process.exit(0);
        }
    };

    process.on('SIGTERM', () => void shutdown());
    test.on('exit', () => {
        testDone = true;
    });

    let held = null;

    while (!held) {
        if (testDone) {
            updateState({
                error: `The test ended before reaching that step:\n${logTail()}`,
            });

            return;
        }

        try {
            held = JSON.parse(readFileSync(HELD, 'utf8'));
        } catch {
            await sleep(200);
        }
    }

    // The frozen test's browser is still up: attach to it and take its page.
    browser = await chromium.connectOverCDP(`http://127.0.0.1:${cdpPort}`);
    const pages = () =>
        browser
            .contexts()
            .flatMap((context) => context.pages())
            .filter((p) => !p.isClosed());
    let page = pages().at(-1);

    if (!page) {
        updateState({ error: 'The test has no page open at that step.' });
        await shutdown();

        return;
    }

    const clients = new Set();
    const followed = new WeakSet();
    let session = null;
    let viewport = { width: 1280, height: 720 };
    let lastSeen = Date.now();

    const broadcast = (message) => {
        for (const client of clients) {
            if (client.readyState === 1) {
                client.send(message);
            }
        }
    };
    const announce = async () => {
        const info = {
            type: 'page',
            url: page.url(),
            title: await page.title().catch(() => ''),
        };

        updateState({ url: info.url, title: info.title });
        broadcast(JSON.stringify(info));
    };

    /** Stream this page's screen (again, after switching pages or resizing). */
    const watch = async (next) => {
        await session?.send('Page.stopScreencast').catch(() => {});
        await session?.detach().catch(() => {});
        page = next;
        session = await page.context().newCDPSession(page);
        session.on('Page.screencastFrame', (frame) => {
            session
                .send('Page.screencastFrameAck', { sessionId: frame.sessionId })
                .catch(() => {});
            broadcast(Buffer.from(frame.data, 'base64'));
        });
        await session
            .send('Emulation.setDeviceMetricsOverride', {
                ...viewport,
                deviceScaleFactor: 1,
                mobile: false,
            })
            .catch(() => {});
        await session.send('Page.startScreencast', {
            format: 'jpeg',
            quality: 75,
            maxWidth: viewport.width,
            maxHeight: viewport.height,
        });

        if (!followed.has(page)) {
            followed.add(page);
            next.on(
                'framenavigated',
                (frame) =>
                    frame === next.mainFrame() &&
                    next === page &&
                    void announce(),
            );
            next.on('load', () => next === page && void announce());
        }

        await announce();
    };

    await watch(page);

    // A link that opens a new tab or window: follow it.
    for (const context of browser.contexts()) {
        context.on('page', (opened) => void watch(opened));
    }

    const input = async (message) => {
        const modifiers =
            (message.alt ? 1 : 0) |
            (message.ctrl ? 2 : 0) |
            (message.meta ? 4 : 0) |
            (message.shift ? 8 : 0);

        switch (message.type) {
            case 'mouse':
                return session.send('Input.dispatchMouseEvent', {
                    type: message.event,
                    x: message.x,
                    y: message.y,
                    button: message.button ?? 'none',
                    buttons: message.buttons ?? 0,
                    clickCount: message.clickCount ?? 0,
                    modifiers,
                });
            case 'wheel':
                return session.send('Input.dispatchMouseEvent', {
                    type: 'mouseWheel',
                    x: message.x,
                    y: message.y,
                    deltaX: message.deltaX,
                    deltaY: message.deltaY,
                    modifiers,
                });
            case 'key':
                return session.send('Input.dispatchKeyEvent', {
                    type: message.event,
                    key: message.key,
                    code: message.code,
                    windowsVirtualKeyCode: message.keyCode,
                    // Typed characters (and Enter, which submits forms) need their text; shortcuts don't.
                    text:
                        message.event !== 'keyDown' || modifiers & 6
                            ? undefined
                            : message.key.length === 1
                              ? message.key
                              : message.key === 'Enter'
                                ? '\r'
                                : undefined,
                    modifiers,
                });
            case 'text':
                return session.send('Input.insertText', {
                    text: String(message.text).slice(0, 10_000),
                });
            case 'resize':
                viewport = {
                    width: Math.max(
                        320,
                        Math.min(3840, Math.round(message.width)),
                    ),
                    height: Math.max(
                        240,
                        Math.min(2160, Math.round(message.height)),
                    ),
                };

                return watch(page);
            case 'back':
                return page.goBack().catch(() => {});
            case 'forward':
                return page.goForward().catch(() => {});
            case 'reload':
                return page.reload().catch(() => {});
            case 'resume':
                return resume(message.mode === 'step' ? 'step' : 'end');
        }
    };

    const html = readFileSync(
        new URL('./browser-viewer.html', import.meta.url),
        'utf8',
    );
    const server = http.createServer(async (req, res) => {
        const path = new URL(req.url ?? '/', 'http://browser').pathname;
        // What the agent uses, from inside the sandbox; never through the preview's address.
        const local = !req.headers['x-onedrop-proxied'];

        if (path === '/') {
            lastSeen = Date.now();
            res.writeHead(200, {
                'content-type': 'text/html; charset=utf-8',
                'cache-control': 'no-store',
            });
            res.end(html);
        } else if (local && path === '/control/screenshot') {
            res.writeHead(200, { 'content-type': 'image/png' });
            res.end(await page.screenshot());
        } else if (local && path === '/control/reload') {
            await page.reload().catch(() => {});
            res.end('ok');
        } else if (local && path === '/control/resume') {
            const resumed = resume(
                new URL(req.url, 'http://browser').searchParams.get('mode') ===
                    'step'
                    ? 'step'
                    : 'end',
            );
            res.writeHead(resumed ? 200 : 409);
            res.end(resumed ? 'ok' : "The test isn't paused.");
        } else {
            res.writeHead(404);
            res.end();
        }
    });
    const sockets = new wsServer({ server, path: '/ws' });

    sockets.on('connection', (client) => {
        clients.add(client);
        lastSeen = Date.now();
        void announce();
        client.send(JSON.stringify({ type: 'playback', ...playback }));
        // A fresh frame for the newcomer.
        void watch(page);
        client.on('message', (data) => {
            lastSeen = Date.now();

            try {
                void input(JSON.parse(String(data))).catch(() => {});
            } catch {
                // Not JSON: ignore it.
            }
        });
        client.on('close', () => {
            clients.delete(client);
            lastSeen = Date.now();
        });
    });

    // Where the test is: paused at a step (or at its end), or playing on after the user resumed it.
    let playback = {
        paused: true,
        playing: false,
        step: held.step,
        next: held.next,
        ended: !!held.ended,
        error: held.error ?? null,
    };
    let pausedAt = held.at;
    const tellPlayback = () => {
        updateState({ playback });
        broadcast(JSON.stringify({ type: 'playback', ...playback }));
    };

    // The test pauses again (after a step, or at its end): test-steps.mjs rewrites HELD each time.
    pauses = setInterval(() => {
        try {
            const next = JSON.parse(readFileSync(HELD, 'utf8'));

            if (next.at !== pausedAt) {
                pausedAt = next.at;
                held = next;
                playback = {
                    paused: true,
                    playing: false,
                    step: next.step,
                    next: next.next,
                    ended: !!next.ended,
                    error: next.error ?? null,
                };
                tellPlayback();
            }
        } catch {
            // Mid-write: next time.
        }
    }, 250);

    /** Carry on with the test: one more step, or to its end. */
    resume = (mode) => {
        if (!playback.paused || playback.ended) {
            return false;
        }

        writeFileSync(
            CONTROL,
            JSON.stringify({
                until: mode === 'step' ? playback.step + 1 : null,
                pace: PACE_MS,
            }),
        );
        playback = { ...playback, paused: false, playing: true, error: null };
        tellPlayback();

        try {
            process.kill(held.pid, 'SIGCONT');
        } catch {
            return false;
        }

        return true;
    };

    const idleTimer = setInterval(() => {
        if (clients.size === 0 && Date.now() - lastSeen > IDLE_MS) {
            void shutdown();
        }
    }, 60_000);

    browser.on('disconnected', () => void shutdown());
    server.listen(PORT, '127.0.0.1', () =>
        updateState({ ready: true, held, cdpPort, playback }),
    );
}

/** The first of CDP_PORTS nothing listens on. */
async function freePort() {
    for (const port of CDP_PORTS) {
        const free = await new Promise((resolve) => {
            const probe = net.createServer();
            probe.once('error', () => resolve(false));
            probe.listen(port, '127.0.0.1', () =>
                probe.close(() => resolve(true)),
            );
        });

        if (free) {
            return port;
        }
    }

    return null;
}

async function control(path) {
    const state = readState();

    if (!state?.ready || !alive(state.pid)) {
        console.error("The browser isn't open.");

        return null;
    }

    const response = await fetch(`http://127.0.0.1:${PORT}${path}`).catch(
        () => null,
    );

    return response?.ok ? response : null;
}

const [command, ...args] = process.argv.slice(2);

if (command === 'start') {
    process.exitCode = await start(args[0], Number(args[1]));
} else if (command === 'serve') {
    await serve(args[0], Number(args[1]));
} else if (command === 'stop') {
    process.exitCode = stop();
} else if (command === 'status') {
    console.log(JSON.stringify(status()));
} else if (command === 'screenshot') {
    const response = await control('/control/screenshot');

    if (response && args[0]) {
        writeFileSync(args[0], Buffer.from(await response.arrayBuffer()));
        console.log(args[0]);
    } else {
        process.exitCode = 1;
    }
} else if (command === 'reload') {
    process.exitCode = (await control('/control/reload')) ? 0 : 1;
} else if (command === 'resume') {
    process.exitCode = (await control(
        `/control/resume?mode=${args[0] === 'step' ? 'step' : 'end'}`,
    ))
        ? 0
        : 1;
} else {
    console.error(
        'Usage: browser.mjs start <file:line> <step> | stop | status | screenshot <file> | reload | resume step|end',
    );
    process.exitCode = 64;
}
