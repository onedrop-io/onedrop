#!/usr/bin/env node
// Tells the platform when files in /workspace are added, removed or renamed (by the agent, the Shell,
// the app itself, ...), so the Files panel reloads its tree only when something changed (FILE-004).
// start.sh keeps this running. It posts to a signed address; no secrets are needed.
//
// Env: APP_FILES_CHANGED_URL (set when the sandbox is created).
import { spawn } from 'node:child_process';
import { existsSync } from 'node:fs';
import { createInterface } from 'node:readline';

const { APP_FILES_CHANGED_URL } = process.env;

// Folders the Files panel lists but doesn't expand (WorkspaceFiles::COLLAPSED); not watched at all.
const EXCLUDE = '/(node_modules|\\.git|vendor|\\.cache)(/|$)';
// Files that come and go on their own: SQLite journals, editor swap and backup files.
const NOISE = /(-journal|-wal|-shm|\.sw[a-p]|~|\/4913)$/;
// Report once things have been quiet this long, but at least this often while they aren't.
const QUIET_MS = 300;
const MAX_WAIT_MS = 2000;

if (!APP_FILES_CHANGED_URL) {
    console.error('file-watcher: APP_FILES_CHANGED_URL is not set');
    process.exit(1);
}

// Path → whether it existed before this burst of events, so a file made and removed again doesn't count.
let touched = new Map();
let quietTimer = null;
let firstEventAt = 0;

async function report() {
    try {
        await fetch(APP_FILES_CHANGED_URL, {
            method: 'POST',
            headers: { Accept: 'application/json' },
        });
    } catch (error) {
        console.error(`file-watcher: post failed: ${error.message}`);
    }
}

function settle() {
    const paths = touched;
    touched = new Map();
    quietTimer = null;

    for (const [path, existed] of paths) {
        if (existsSync(path) !== existed) {
            void report();

            return;
        }
    }
}

const watcher = spawn(
    'inotifywait',
    [
        '--monitor',
        '--recursive',
        '--quiet',
        '--event',
        'create,delete,moved_to,moved_from',
        '--exclude',
        EXCLUDE,
        '--format',
        '%e %w%f',
        '/workspace',
    ],
    { stdio: ['ignore', 'pipe', 'inherit'] },
);

createInterface({ input: watcher.stdout }).on('line', (line) => {
    const space = line.indexOf(' ');
    const events = line.slice(0, space);
    const path = line.slice(space + 1);

    if (NOISE.test(path)) {
        return;
    }

    if (!touched.has(path)) {
        touched.set(path, /DELETE|MOVED_FROM/.test(events));
    }

    if (quietTimer === null) {
        firstEventAt = Date.now();
    } else {
        clearTimeout(quietTimer);
    }

    quietTimer = setTimeout(
        settle,
        Math.max(0, Math.min(QUIET_MS, firstEventAt + MAX_WAIT_MS - Date.now())),
    );
});

watcher.on('close', (code) => process.exit(code ?? 1));

// Say we're watching (e.g. after a restart, when files may have changed unseen), so the panel reloads once.
void report();
