#!/usr/bin/env node
// Runs one agent task (OpenCode or Claude Code) and forwards its JSON events to the platform.
// The platform never holds a connection open: we POST batches of events to a signed webhook.
//
// Env: APP_AGENT ("opencode", the default, or "claude_code"), APP_PROMPT, APP_MODEL, APP_EVENTS_URL,
// APP_EVENTS_TOKEN, optional APP_RUN (which chat: "main" or "task-<id>"; several may run at once),
// APP_SESSION_ID, APP_VARIANT (reasoning level) and, for OpenCode,
// APP_FILES (JSON list of image paths the model should see with the prompt). For Claude Code,
// APP_CLAUDE_AUTH is "subscription" when it runs on the user's own `claude auth login` (AI-005).
// Provider keys (ANTHROPIC_API_KEY, OPENAI_API_KEY, ...) are read by the agent; a Claude subscription is Claude Code's own sign-in.
import { spawn, spawnSync } from 'node:child_process';
import { readFileSync, writeFileSync, rmSync } from 'node:fs';
import { createInterface } from 'node:readline';

const INSTRUCTIONS = '/opt/zap/instructions.md';
const CHECKPOINT = '/opt/zap/checkpoint';

const {
    APP_AGENT = 'opencode',
    APP_PROMPT,
    APP_MODEL,
    APP_EVENTS_URL,
    APP_EVENTS_TOKEN,
    APP_SESSION_ID,
    APP_VARIANT,
    APP_FILES,
    APP_RUN = 'main',
    APP_CLAUDE_AUTH,
} = process.env;

// /opt/zap/stop-agent signals this process to end the run. The project's main chat and each of its
// tasks run separately (APP_RUN is "main" or "task-<id>"), so each run has its own PID file.
const PID_FILE =
    APP_RUN === 'main'
        ? '/tmp/zap-agent.pid'
        : `/tmp/zap-agent-${APP_RUN.replace(/[^a-z0-9-]/g, '')}.pid`;

const claude = APP_AGENT === 'claude_code';

function opencodeCommand() {
    const args = [
        'run',
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

    // `--file` takes a list, so end the options or the prompt would be read as another file.
    args.push('--', APP_PROMPT);

    return {
        command: 'opencode',
        args,
        env: { OPENCODE_CONFIG: '/opt/zap/opencode.json' },
    };
}

// The prompt goes in on stdin, so one starting with "-" isn't read as an option.
function claudeCommand(resume) {
    const args = [
        '-p',
        '--output-format',
        'stream-json',
        '--verbose',
        '--model',
        APP_MODEL,
        '--permission-mode',
        'bypassPermissions',
        '--append-system-prompt',
        readFileSync(INSTRUCTIONS, 'utf8'),
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

function pick(object, keys) {
    const picked = {};

    for (const key of keys) {
        if (object?.[key] !== undefined) {
            picked[key] = object[key];
        }
    }

    return picked;
}

// Claude Code's events carry whole files and tool output; the platform only needs a little of each.
function compactClaudeEvent(event) {
    switch (event.type) {
        case 'assistant':
            return {
                type: 'assistant',
                session_id: event.session_id,
                message: {
                    model: event.message?.model,
                    content: (event.message?.content ?? []).map((block) => {
                        if (block.type === 'text') {
                            return { type: 'text', text: block.text };
                        }

                        if (block.type === 'tool_use') {
                            const input = pick(block.input, [
                                'file_path',
                                'notebook_path',
                                'path',
                                'command',
                                'description',
                                'pattern',
                            ]);

                            if (typeof input.command === 'string') {
                                input.command = input.command.slice(0, 500);
                            }

                            return {
                                type: 'tool_use',
                                name: block.name,
                                input,
                            };
                        }

                        return { type: block.type };
                    }),
                },
            };
        case 'result':
            return {
                ...pick(event, [
                    'type',
                    'subtype',
                    'is_error',
                    'result',
                    'errors',
                    'api_error_status',
                    'session_id',
                ]),
                // Tokens and estimated cost per model, for the Usage page.
                modelUsage: Object.fromEntries(
                    Object.entries(event.modelUsage ?? {}).map(
                        ([model, usage]) => [
                            model,
                            pick(usage, [
                                'inputTokens',
                                'outputTokens',
                                'cacheReadInputTokens',
                                'cacheCreationInputTokens',
                                'costUSD',
                            ]),
                        ],
                    ),
                ),
            };
        case 'system':
            return ['init', 'api_retry'].includes(event.subtype)
                ? pick(event, ['type', 'subtype', 'session_id', 'error'])
                : null;
        default:
            return null;
    }
}

// Commit the turn's changes before the platform hears it's over, so its backup includes them.
// The first line of the prompt is the subject; a longer prompt follows in full.
function checkpoint() {
    const prompt = (APP_PROMPT ?? '').trim();
    const firstLine = prompt.split('\n')[0];
    const subject =
        firstLine.length > 72 ? `${firstLine.slice(0, 71)}…` : firstLine;
    const message = subject === prompt ? prompt : `${subject}\n\n${prompt}`;

    spawnSync(CHECKPOINT, [], {
        input: message,
        stdio: ['pipe', 'ignore', 'ignore'],
        timeout: 60000,
    });
}

let pending = [];
let sending = Promise.resolve();

function flush() {
    if (pending.length === 0) {
        return sending;
    }

    const events = pending;
    pending = [];

    // Chain posts so the platform receives events in order.
    sending = sending.then(() => post(events));

    return sending;
}

async function post(events) {
    for (let attempt = 1; attempt <= 5; attempt++) {
        try {
            const response = await fetch(APP_EVENTS_URL, {
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

// Tell the platform we're alive before the model's first token.
pending.push({ type: 'zap.start' });

writeFileSync(PID_FILE, String(process.pid));

const timer = setInterval(flush, 300);

let agent;
let stopped = false;
// The agent already explained why it failed (Claude Code's error results), so the exit needn't.
let reported = false;

function kill(signal) {
    try {
        process.kill(-agent.pid, signal);
    } catch {
        // Already gone.
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

function run(resume) {
    const {
        command,
        args,
        env,
        unset = [],
        input,
    } = claude ? claudeCommand(resume) : opencodeCommand();
    // Claude Code can't resume a session it no longer has (e.g. a new sandbox); then start a fresh one.
    let retryFresh = false;
    let stderr = '';

    agent = spawn(command, args, {
        cwd: '/workspace',
        stdio: [input === undefined ? 'ignore' : 'pipe', 'pipe', 'pipe'],
        env: Object.fromEntries(
            Object.entries({ ...process.env, ...env }).filter(
                ([name]) => !unset.includes(name),
            ),
        ),
        // Own process group, so stopping also ends anything the agent started (npm, builds, ...).
        detached: true,
    });

    if (input !== undefined) {
        agent.stdin.end(input);
    }

    agent.stderr.on('data', (chunk) => {
        stderr = (stderr + chunk).slice(-4000);
    });

    createInterface({ input: agent.stdout }).on('line', (line) => {
        let event;

        try {
            event = JSON.parse(line);
        } catch {
            // Not JSON (e.g. a warning); ignore.
            return;
        }

        if (!claude) {
            // OpenCode's steps say what they used but not with which model.
            pending.push(
                event.type === 'step_finish'
                    ? { ...event, model: APP_MODEL }
                    : event,
            );

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

        const compact = compactClaudeEvent(event);

        if (compact) {
            reported ||= compact.type === 'result' && compact.is_error;
            pending.push(compact);
        }
    });

    agent.on('close', async (code) => {
        if (retryFresh && !stopped) {
            run(false);

            return;
        }

        clearInterval(timer);
        rmSync(PID_FILE, { force: true });
        checkpoint();

        // A stopped run's events are ignored by the platform anyway; don't report it as a crash.
        if (!stopped) {
            pending.push({
                type: 'zap.exit',
                code,
                stderr: code === 0 ? '' : stderr,
                reported,
            });
            await flush();
        }

        process.exit(0);
    });
}

run(true);
