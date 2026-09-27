#!/usr/bin/env node
// Runs one OpenCode task and forwards its JSON events to the platform.
// The platform never holds a connection open: we POST batches of events to a signed webhook.
//
// Env: ZAP_PROMPT, ZAP_MODEL, ZAP_EVENTS_URL, ZAP_EVENTS_TOKEN, optional ZAP_SESSION_ID.
// Provider keys (ANTHROPIC_API_KEY, OPENAI_API_KEY, OPENROUTER_API_KEY) are read by OpenCode.
import { spawn } from 'node:child_process';
import { createInterface } from 'node:readline';

const {
    ZAP_PROMPT,
    ZAP_MODEL,
    ZAP_EVENTS_URL,
    ZAP_EVENTS_TOKEN,
    ZAP_SESSION_ID,
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
    ZAP_MODEL,
];

if (ZAP_SESSION_ID) {
    args.push('--session', ZAP_SESSION_ID);
}

args.push(ZAP_PROMPT);

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
            const response = await fetch(ZAP_EVENTS_URL, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    Authorization: `Bearer ${ZAP_EVENTS_TOKEN}`,
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

const timer = setInterval(flush, 300);
const agent = spawn('opencode', args, {
    cwd: '/workspace',
    stdio: ['ignore', 'pipe', 'pipe'],
    env: { ...process.env, OPENCODE_CONFIG: '/opt/zap/opencode.json' },
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
    pending.push({ type: 'zap.exit', code, stderr: code === 0 ? '' : stderr });
    await flush();
    process.exit(0);
});
