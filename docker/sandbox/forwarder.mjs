#!/usr/bin/env node
// Runs one OpenCode task and forwards its JSON events to the platform.
// The platform never holds a connection open: we POST batches of events to a signed webhook.
//
// Env: APP_PROMPT, APP_MODEL, APP_EVENTS_URL, APP_EVENTS_TOKEN, optional APP_SESSION_ID, APP_VARIANT (reasoning level)
// and APP_FILES (JSON list of image paths the model should see with the prompt).
// Provider keys (ANTHROPIC_API_KEY, OPENAI_API_KEY, OPENROUTER_API_KEY) are read by OpenCode.
import { spawn } from 'node:child_process';
import { writeFileSync, rmSync } from 'node:fs';
import { createInterface } from 'node:readline';

// /opt/zap/stop-agent signals this process to end the run.
const PID_FILE = '/tmp/zap-agent.pid';

const {
    APP_PROMPT,
    APP_MODEL,
    APP_EVENTS_URL,
    APP_EVENTS_TOKEN,
    APP_SESSION_ID,
    APP_VARIANT,
    APP_FILES,
} = process.env;

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
                body: JSON.stringify({ events }),
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
const agent = spawn('opencode', args, {
    cwd: '/workspace',
    stdio: ['ignore', 'pipe', 'pipe'],
    env: { ...process.env, OPENCODE_CONFIG: '/opt/zap/opencode.json' },
    // Own process group, so stopping also ends anything the agent started (npm, builds, ...).
    detached: true,
});

let stopped = false;

process.on('SIGTERM', () => {
    stopped = true;

    try {
        process.kill(-agent.pid, 'SIGTERM');
    } catch {
        // Already gone.
    }

    // Force it if it hasn't exited shortly.
    setTimeout(() => {
        try {
            process.kill(-agent.pid, 'SIGKILL');
        } catch {
            // Already gone.
        }

        process.exit(0);
    }, 3000).unref();
});

let stderr = '';
agent.stderr.on('data', (chunk) => {
    stderr = (stderr + chunk).slice(-4000);
});

createInterface({ input: agent.stdout }).on('line', (line) => {
    try {
        pending.push(JSON.parse(line));
    } catch {
        // Not JSON (e.g. a warning); ignore.
    }
});

agent.on('close', async (code) => {
    clearInterval(timer);
    rmSync(PID_FILE, { force: true });

    // A stopped run's events are ignored by the platform anyway; don't report it as a crash.
    if (!stopped) {
        pending.push({
            type: 'zap.exit',
            code,
            stderr: code === 0 ? '' : stderr,
        });
        await flush();
    }

    process.exit(0);
});
