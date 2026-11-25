#!/usr/bin/env node
// Runs one agent task (OpenCode, Claude Code or Codex) and forwards its JSON events to the platform.
// The platform never holds a connection open: we POST batches of events to a signed webhook.
// Each agent streams its replies and tool calls (AGT-017): Claude Code prints them, OpenCode's are read from its
// server's events (`opencode serve`, with `opencode run --attach` giving it the prompt) and Codex's from its app server
// (`codex app-server`). agent-stream.mjs turns them into the events the platform reads, with secrets hidden.
//
// Env: APP_AGENT ("opencode", the default, "claude_code" or "codex"), APP_PROMPT, APP_MODEL, APP_EVENTS_URL,
// APP_EVENTS_TOKEN, optional APP_RUN (which chat: "main" or "task-<id>"; several may run at once),
// APP_SESSION_ID, APP_VARIANT (reasoning level) and, for OpenCode and Codex,
// APP_FILES (JSON list of image paths the model should see with the prompt). For Claude Code,
// APP_CLAUDE_AUTH is "subscription" when it runs on the user's own `claude auth login` (AI-005).
// For OpenCode, APP_COMMAND is one of its commands to run (AGT-016), with APP_PROMPT as its details.
// For Codex, CODEX_AUTH_CONTENT is its auth.json (the ChatGPT sign-in or OpenAI key), written before it starts.
// APP_REQUIREMENTS is "1" when the agent keeps the project's requirements and their tests (REQ-002, TEST-002): those
// guides join the instructions. APP_MODE is "computer" on a person's computer (CMP-002): the agent works its desktop
// with the computer guide instead of building an app, and there's no checkpoint to commit. APP_COMMIT_TURNS is "0"
// when the turn's changes are left for the user to commit (SCM-003): the checkpoint is private.
// Provider keys (ANTHROPIC_API_KEY, OPENAI_API_KEY, ...) are read by the agent; a Claude subscription is Claude Code's own sign-in.
import { spawn, spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { mkdirSync, readFileSync, writeFileSync, rmSync } from 'node:fs';
import { createServer } from 'node:net';
import { createInterface } from 'node:readline';
import {
    ClaudeTranslator,
    CodexTranslator,
    OpenCodeTranslator,
    StreamRedactor,
    coalesce,
    redact,
    secretValues,
} from './agent-stream.mjs';

const INSTRUCTIONS = '/opt/onedrop/instructions.md';
const COMPUTER_INSTRUCTIONS = '/opt/onedrop/computer.md';
const REQUIREMENTS_GUIDES = [
    '/opt/onedrop/guides/requirements.md',
    '/opt/onedrop/guides/tests.md',
];
const OPENCODE_CONFIG = '/opt/onedrop/opencode.json';
const CHECKPOINT = '/opt/onedrop/checkpoint';
// A cloud sandbox reaching a local install goes through its dev tunnel, whose address changes when it restarts; the
// platform writes the current one to this file (SBX-015), and it replaces the address's origin.
const CALLBACK_FILE = '/etc/onedrop-callback';

function current(url) {
    let origin = '';
    try {
        origin = readFileSync(CALLBACK_FILE, 'utf8').trim();
    } catch {
        return url;
    }
    if (!origin) {
        return url;
    }
    const target = new URL(url);
    return origin.replace(/\/+$/, '') + target.pathname + target.search;
}

const {
    APP_AGENT = 'opencode',
    APP_PROMPT,
    APP_COMMAND,
    APP_MODEL,
    APP_EVENTS_URL,
    APP_EVENTS_TOKEN,
    APP_SESSION_ID,
    APP_VARIANT,
    APP_FILES,
    APP_RUN = 'main',
    APP_CLAUDE_AUTH,
    APP_REQUIREMENTS,
    APP_MODE = 'app',
    APP_COMMIT_TURNS = '1',
    CODEX_AUTH_CONTENT,
} = process.env;

const computer = APP_MODE === 'computer';
const requirements = APP_REQUIREMENTS === '1' && !computer;

// The agent's instructions: the platform's (or, on a computer, the computer guide), plus the requirements and tests
// guides when that's on.
function instructions() {
    return [
        computer ? COMPUTER_INSTRUCTIONS : INSTRUCTIONS,
        ...(requirements ? REQUIREMENTS_GUIDES : []),
    ]
        .map((file) => readFileSync(file, 'utf8'))
        .join('\n');
}

// OpenCode reads its instructions from its config, so the guides go in a copy that lists them too.
function opencodeConfig() {
    if (!requirements && !computer) {
        return OPENCODE_CONFIG;
    }

    const config = JSON.parse(readFileSync(OPENCODE_CONFIG, 'utf8'));
    // One per run: several runs may start at once.
    const path = `/tmp/onedrop-opencode-${APP_RUN.replace(/[^a-z0-9-]/g, '')}.json`;

    config.instructions = computer
        ? [COMPUTER_INSTRUCTIONS]
        : [...(config.instructions ?? []), ...REQUIREMENTS_GUIDES];
    writeFileSync(path, JSON.stringify(config));

    return path;
}

// /opt/onedrop/stop-agent signals this process to end the run. The project's main chat and each of its
// tasks run separately (APP_RUN is "main" or "task-<id>"), so each run has its own PID file.
const PID_FILE =
    APP_RUN === 'main'
        ? '/tmp/onedrop-agent.pid'
        : `/tmp/onedrop-agent-${APP_RUN.replace(/[^a-z0-9-]/g, '')}.pid`;

const claude = APP_AGENT === 'claude_code';
const codex = APP_AGENT === 'codex';
// Names this run's streamed blocks, so they can't be confused with another run's (AGT-017).
const RUN_ID = randomBytes(4).toString('hex');

// `opencode run` gives the prompt to the run's own OpenCode server (`server`), whose events are read instead of the
// run's: it only prints finished parts.
function opencodeCommand(server) {
    const args = [
        'run',
        '--attach',
        server,
        '--format',
        'json',
        '--thinking',
        '--auto',
        '--dir',
        '/workspace',
        '--model',
        APP_MODEL,
    ];

    if (APP_SESSION_ID) {
        args.push('--session', APP_SESSION_ID);
    }

    if (APP_VARIANT) {
        args.push('--variant', APP_VARIANT);
    }

    for (const file of JSON.parse(APP_FILES || '[]')) {
        args.push('--file', file);
    }

    if (APP_COMMAND) {
        args.push('--command', APP_COMMAND);
    }

    // `--file` takes a list, so end the options or the prompt would be read as another file.
    // A command's details are optional.
    if (!APP_COMMAND || APP_PROMPT) {
        args.push('--', APP_PROMPT);
    }

    return { command: 'opencode', args };
}

function claudeCommand(resume) {
    const args = [
        '-p',
        '--output-format',
        'stream-json',
        '--verbose',
        // Replies and thinking as they're written (AGT-017).
        '--include-partial-messages',
        '--model',
        APP_MODEL,
        '--permission-mode',
        'bypassPermissions',
        '--append-system-prompt',
        instructions(),
    ];

    if (resume && APP_SESSION_ID) {
        args.push('--resume', APP_SESSION_ID);
    }

    if (APP_VARIANT) {
        args.push('--effort', APP_VARIANT);
    }

    return {
        command: 'claude',
        args,
        env: { DISABLE_AUTOUPDATER: '1' },
        // A key would win over the user's Claude sign-in and bill their API account instead.
        unset:
            APP_CLAUDE_AUTH === 'subscription'
                ? [
                      'ANTHROPIC_API_KEY',
                      'ANTHROPIC_AUTH_TOKEN',
                      'CLAUDE_CODE_OAUTH_TOKEN',
                  ]
                : [],
        input: APP_PROMPT,
    };
}

// Codex reads its sign-in or key from auth.json.
function writeCodexAuth() {
    const home = process.env.CODEX_HOME || `${process.env.HOME}/.codex`;

    if (CODEX_AUTH_CONTENT) {
        mkdirSync(home, { recursive: true });
        writeFileSync(`${home}/auth.json`, CODEX_AUTH_CONTENT, { mode: 0o600 });
    }
}

// The long strings in Codex's auth.json (its tokens and key), to hide wherever they'd show.
function codexAuthValues() {
    try {
        const values = [];
        const collect = (value) => {
            if (typeof value === 'string' && value.length >= 16) {
                values.push(value);
            } else if (value && typeof value === 'object') {
                Object.values(value).forEach(collect);
            }
        };

        collect(JSON.parse(CODEX_AUTH_CONTENT || '{}'));

        return values;
    } catch {
        return [];
    }
}

// The organization's secrets (SECRET-003), as /opt/onedrop/org-secrets gives them to the Shell and the app's start
// script: settings already in the environment win over them; names the project's .env sets don't.
function organizationSecrets() {
    let lines;

    try {
        lines = readFileSync('/tmp/onedrop-org-secrets', 'utf8').split('\n');
    } catch {
        return {};
    }

    const secrets = {};

    for (const line of lines) {
        const [name, value = ''] = line.split(' ');

        if (/^[A-Za-z_][A-Za-z0-9_]*$/.test(name) && !(name in process.env)) {
            secrets[name] = Buffer.from(value, 'base64').toString('utf8');
        }
    }

    return secrets;
}

// Before the turn: save edits made since the last checkpoint (in the Shell, the Files panel) as their own
// checkpoint, so the turn's checkpoint holds only the agent's changes (SCM-002). In the background, so the start
// event still goes out at once.
function snapshot() {
    // A computer's Home isn't a repository (CMP-001).
    if (computer) {
        return Promise.resolve();
    }

    return new Promise((resolve) => {
        const child = spawn(CHECKPOINT, ['--snapshot'], {
            stdio: ['ignore', 'ignore', 'ignore'],
            timeout: 60000,
        });

        child.on('error', resolve);
        child.on('close', resolve);
    });
}

// Save the turn's changes before the platform hears it's over, so its backup includes them: a private checkpoint,
// and a commit unless the project leaves its changes for the user to commit (SBX-006, SCM-003).
// The first line of the prompt is the subject; a longer prompt follows in full.
function checkpoint() {
    if (computer) {
        return;
    }

    const prompt = (
        APP_COMMAND ? `/${APP_COMMAND} ${APP_PROMPT ?? ''}` : (APP_PROMPT ?? '')
    ).trim();
    const firstLine = prompt.split('\n')[0];
    const subject =
        firstLine.length > 72 ? `${firstLine.slice(0, 71)}…` : firstLine;
    const message = subject === prompt ? prompt : `${subject}\n\n${prompt}`;

    spawnSync(CHECKPOINT, [], {
        input: message,
        stdio: ['pipe', 'ignore', 'ignore'],
        timeout: 60000,
        env: { ...process.env, ONEDROP_COMMIT_TURNS: APP_COMMIT_TURNS },
    });
}

let pending = [];
let sending = Promise.resolve();

function flush() {
    if (pending.length === 0) {
        return sending;
    }

    // A batch's pieces of the same reply go as one (AGT-017).
    const events = coalesce(pending);
    pending = [];

    // Chain posts so the platform receives events in order.
    sending = sending.then(() => post(events));

    return sending;
}

async function post(events) {
    for (let attempt = 1; attempt <= 5; attempt++) {
        try {
            const response = await fetch(current(APP_EVENTS_URL), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    Authorization: `Bearer ${APP_EVENTS_TOKEN}`,
                },
                body: JSON.stringify({ agent: APP_AGENT, events }),
            });

            if (response.ok || response.status < 500) {
                return;
            }
        } catch (error) {
            console.error(
                `forwarder: post failed (${attempt}/5): ${error.message}`,
            );
        }

        await new Promise((resolve) => setTimeout(resolve, attempt * 500));
    }
}

// Secrets the sandbox holds never leave it in the agent's events (AGT-017): settings that look secret, the
// organization's secrets (SECRET-003) and Codex's tokens. Streamed text holds back a little in case one is being written.
const SECRETS = secretValues(process.env, [
    ...Object.values(organizationSecrets()),
    ...codexAuthValues(),
]);
const streamRedactor = new StreamRedactor(SECRETS);

function emit(events) {
    for (const event of events) {
        if (event.type === 'onedrop.delta') {
            const delta = streamRedactor.push(event.block, event.delta);

            if (delta !== '') {
                pending.push({ ...event, delta });
            }
        } else {
            pending.push(redact(event, SECRETS));
        }
    }
}

// Tell the platform we're alive before the model's first token.
pending.push({ type: 'onedrop.start' });

writeFileSync(PID_FILE, String(process.pid));

const timer = setInterval(flush, 300);

// The run's processes (the agent, and OpenCode's server), each in its own process group.
const children = new Set();
let stopped = false;
// The agent already explained why it failed (Claude Code's error results, Codex's failed turns), so the exit needn't.
let reported = false;

function kill(signal) {
    for (const child of children) {
        try {
            process.kill(-child.pid, signal);
        } catch {
            // Already gone.
        }
    }
}

process.on('SIGTERM', () => {
    stopped = true;
    kill('SIGTERM');

    // Force it if it hasn't exited shortly.
    setTimeout(() => {
        kill('SIGKILL');
        process.exit(0);
    }, 3000).unref();
});

// Start one of the run's processes with the organization's secrets in its environment (minus `unset`), in its own
// process group, so stopping also ends anything it started (npm, builds, ...). `closed` gives its exit code.
function start(
    command,
    args,
    { env = {}, unset = [], input, stdin = 'ignore' } = {},
) {
    const child = spawn(command, args, {
        cwd: '/workspace',
        stdio: [input === undefined ? stdin : 'pipe', 'pipe', 'pipe'],
        env: Object.fromEntries(
            Object.entries({
                ...organizationSecrets(),
                ...process.env,
                ...env,
            }).filter(([name]) => !unset.includes(name)),
        ),
        detached: true,
    });

    children.add(child);

    if (input !== undefined) {
        child.stdin.end(input);
    }

    let stderr = '';

    child.stderr.on('data', (chunk) => {
        stderr = (stderr + chunk).slice(-4000);
    });

    const closed = new Promise((resolve) => {
        child.on('error', (error) => {
            stderr += `\n${error.message}`;
        });
        child.on('close', (code) => {
            children.delete(child);
            resolve(code ?? 1);
        });
    });

    return { child, closed, stderr: () => stderr };
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

// Claude Code prints its events, partial messages included. Claude Code can't resume a session it no longer has (e.g.
// a new sandbox); then `retryFresh` starts a fresh one.
async function runClaude(resume) {
    const { command, args, env, unset, input } = claudeCommand(resume);
    const translator = new ClaudeTranslator(RUN_ID);
    const agent = start(command, args, { env, unset, input });
    let retryFresh = false;

    createInterface({ input: agent.child.stdout }).on('line', (line) => {
        let event;

        try {
            event = JSON.parse(line);
        } catch {
            // Not JSON (e.g. a warning); ignore.
            return;
        }

        const errors = (event.errors ?? []).join(' ');

        if (
            event.type === 'result' &&
            resume &&
            errors.includes('No conversation found')
        ) {
            retryFresh = true;

            return;
        }

        // Claude Code retries a rejected key for minutes; give up on the first rejection.
        if (
            event.type === 'system' &&
            event.subtype === 'api_retry' &&
            event.error === 'authentication_failed'
        ) {
            pending.push({
                type: 'result',
                is_error: true,
                api_error_status: 401,
                result: 'authentication_failed',
            });
            reported = true;
            kill('SIGTERM');

            return;
        }

        const events = translator.translate(event);
        reported ||= events.some(
            (compact) => compact.type === 'result' && compact.is_error,
        );
        emit(events);
    });

    const code = await agent.closed;

    return { code, stderr: agent.stderr(), retryFresh };
}

// A free port on this machine for OpenCode's server.
function freePort() {
    return new Promise((resolve, reject) => {
        const server = createServer();

        server.on('error', reject);
        server.listen(0, '127.0.0.1', () => {
            const { port } = server.address();
            server.close(() => resolve(port));
        });
    });
}

// Read a server's events (`text/event-stream`), retrying until the server is up. Resolves once connected; the events
// keep arriving until `signal` aborts.
async function subscribe(url, headers, onEvent, signal) {
    for (let attempt = 0; attempt < 300 && !stopped; attempt++) {
        let response;

        try {
            response = await fetch(url, { headers, signal });
        } catch {
            if (signal.aborted) {
                return false;
            }
        }

        if (response?.ok) {
            void (async () => {
                const decoder = new TextDecoder();
                let buffer = '';
                let data = [];

                try {
                    for await (const chunk of response.body) {
                        buffer += decoder.decode(chunk, { stream: true });
                        const lines = buffer.split('\n');
                        buffer = lines.pop();

                        for (const line of lines) {
                            if (line.startsWith('data:')) {
                                data.push(line.slice(5).trimStart());
                            } else if (line.trim() === '' && data.length > 0) {
                                try {
                                    onEvent(JSON.parse(data.join('\n')));
                                } catch {
                                    // Not JSON; ignore.
                                }
                                data = [];
                            }
                        }
                    }
                } catch {
                    // Aborted at the end of the run.
                }
            })();

            return true;
        }

        await sleep(200);
    }

    return false;
}

// OpenCode runs as a server of its own for this run, whose events say what the agent does as it does it; `opencode
// run --attach` gives it the prompt (and its files and command) and answers its permission prompts.
async function runOpenCode() {
    const port = await freePort();
    const password = randomBytes(18).toString('base64url');
    const base = `http://127.0.0.1:${port}`;
    const env = {
        OPENCODE_CONFIG: opencodeConfig(),
        OPENCODE_SERVER_PASSWORD: password,
    };
    const server = start(
        'opencode',
        ['serve', '--hostname', '127.0.0.1', '--port', String(port)],
        { env },
    );
    const translator = new OpenCodeTranslator(APP_MODEL, APP_SESSION_ID);
    const events = new AbortController();
    const connected = await subscribe(
        `${base}/event`,
        {
            Authorization: `Basic ${Buffer.from(`opencode:${password}`).toString('base64')}`,
        },
        (event) => emit(translator.translate(event)),
        events.signal,
    );

    let code = 1;
    let stderr = '';

    if (connected) {
        const { command, args } = opencodeCommand(base);
        const agent = start(command, args, { env });

        // Its own output repeats the server's events; only the server's are complete.
        agent.child.stdout.resume();
        code = await agent.closed;
        stderr = agent.stderr();

        // The last events can arrive just after the run exits.
        for (
            let waited = 0;
            !translator.idle &&
            waited < (code === 0 ? 15000 : 2000) &&
            !stopped;
            waited += 100
        ) {
            await sleep(100);
        }
    } else {
        stderr = server.stderr() || "OpenCode's server didn't start";
    }

    events.abort();
    kill('SIGTERM');
    await server.closed;

    return { code, stderr: stderr || server.stderr(), retryFresh: false };
}

// Codex runs as its app server, spoken to over stdin and stdout (JSON-RPC), which reports replies and thinking as
// they're written. A thread it no longer has (e.g. a new sandbox) is started afresh.
async function runCodex() {
    writeCodexAuth();

    const translator = new CodexTranslator(RUN_ID, APP_MODEL);
    const server = start('codex', ['app-server'], {
        unset: ['CODEX_AUTH_CONTENT'],
        stdin: 'pipe',
    });
    const waiting = new Map();
    let next = 0;
    let finish;
    const finished = new Promise((resolve) => (finish = resolve));

    const write = (message) => {
        if (server.child.stdin.writable) {
            server.child.stdin.write(`${JSON.stringify(message)}\n`);
        }
    };

    const request = (method, params) =>
        new Promise((resolve, reject) => {
            const id = ++next;
            waiting.set(id, { resolve, reject });
            write({ id, method, params });
        });

    createInterface({ input: server.child.stdout }).on('line', (line) => {
        let message;

        try {
            message = JSON.parse(line);
        } catch {
            return;
        }

        // A reply to one of our requests.
        if (message.id !== undefined && !message.method) {
            const call = waiting.get(message.id);
            waiting.delete(message.id);

            if (message.error) {
                call?.reject(new Error(message.error.message ?? 'Codex error'));
            } else {
                call?.resolve(message.result);
            }

            return;
        }

        // Codex asking for approval: everything is approved in the sandbox (as `--dangerously-bypass-approvals-and-
        // sandbox` did), and anything else it asks is declined.
        if (message.id !== undefined) {
            write(
                /requestApproval$|Approval$/.test(message.method)
                    ? { id: message.id, result: { decision: 'accept' } }
                    : {
                          id: message.id,
                          error: { code: -32601, message: 'Not supported' },
                      },
            );

            return;
        }

        emit(translator.translate(message));

        if (translator.result !== null) {
            finish();
        }
    });

    void server.closed.then(() => finish());

    try {
        await request('initialize', {
            clientInfo: { name: 'onedrop', title: 'onedrop', version: '1' },
            capabilities: null,
        });
        write({ method: 'initialized', params: {} });

        const thread = {
            model: APP_MODEL,
            cwd: '/workspace',
            approvalPolicy: 'never',
            sandbox: 'danger-full-access',
            developerInstructions: instructions(),
        };
        let started = null;

        if (APP_SESSION_ID) {
            started = await request('thread/resume', {
                ...thread,
                threadId: APP_SESSION_ID,
                excludeTurns: true,
            }).catch(() => null);
        }

        started ??= await request('thread/start', thread);
        emit([{ type: 'thread.started', thread_id: started.thread.id }]);

        await request('turn/start', {
            threadId: started.thread.id,
            input: [
                { type: 'text', text: APP_PROMPT ?? '', text_elements: [] },
                ...JSON.parse(APP_FILES || '[]').map((path) => ({
                    type: 'localImage',
                    path,
                })),
            ],
            ...(APP_VARIANT ? { effort: APP_VARIANT } : {}),
            summary: 'auto',
        });

        await finished;
    } catch (error) {
        emit([{ type: 'turn.failed', error: { message: error.message } }]);
        translator.result ??= 'failed';
    }

    kill('SIGTERM');
    await server.closed;

    reported ||= translator.result === 'failed';

    return {
        code: translator.result === 'completed' ? 0 : 1,
        stderr: server.stderr(),
        retryFresh: false,
    };
}

function runAgent(resume) {
    return claude ? runClaude(resume) : codex ? runCodex() : runOpenCode();
}

await snapshot();

// Stopped while the edits were being saved: there's no agent to start.
if (stopped) {
    process.exit(0);
}

let result = await runAgent(true);

if (result.retryFresh && !stopped) {
    result = await runAgent(false);
}

clearInterval(timer);
rmSync(PID_FILE, { force: true });
checkpoint();

// A stopped run's events are ignored by the platform anyway; don't report it as a crash.
if (!stopped) {
    pending.push({
        type: 'onedrop.exit',
        code: result.code,
        stderr: result.code === 0 ? '' : result.stderr,
        reported,
    });
    await flush();
}

process.exit(0);
