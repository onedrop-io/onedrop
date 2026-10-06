#!/usr/bin/env node
// Keeps the sandbox's copy of Drive in step with the platform's (DRIVE-003, DRIVE-004), both ways: each place (My
// Drive on a computer, the organization's drive, each group's) is a folder under ONEDROP_DRIVE_DIR. Run by start.sh.
//
// It asks the platform what changed (a feed by revision) every few seconds while the sandbox is in use (the desktop or
// preview open, the Shell, an agent running, or a change here in the last two minutes), and not at all otherwise, so
// an unused sandbox can go to sleep (SBX-007); it asks once on waking. Changes made here are sent at once. A file
// changed in both places is kept twice: the local one as "<name> (conflict <date>)".
//
// Env: ONEDROP_DRIVE_URL (the sandbox's sync address), ONEDROP_DRIVE_TOKEN, ONEDROP_DRIVE_DIR.
import { createHash } from 'node:crypto';
import {
    closeSync,
    createReadStream,
    existsSync,
    lstatSync,
    mkdirSync,
    openSync,
    readdirSync,
    readFileSync,
    readSync,
    renameSync,
    rmSync,
    statSync,
    writeFileSync,
} from 'node:fs';
import { dirname, join, sep } from 'node:path';
import { execFileSync } from 'node:child_process';
import { setTimeout as sleep } from 'node:timers/promises';

const BASE = String(process.env.ONEDROP_DRIVE_URL || '').replace(/\/+$/, '');
const TOKEN = process.env.ONEDROP_DRIVE_TOKEN || '';
const ROOT = process.env.ONEDROP_DRIVE_DIR || '/home/sandbox/drive';
const HOME = process.env.HOME || '/home/sandbox';
const STATE_FILE = `${HOME}/.onedrop/drive-${createHash('sha256').update(BASE).digest('hex').slice(0, 12)}.json`;
const ACTIVE_FILE = '/tmp/onedrop-active';
const BUSY_MS = 3000;
const IDLE_SCAN_MS = 5000;
const IN_USE_MS = 120_000;
// A file changed this recently may still be being written.
const SETTLE_MS = 2000;
const IGNORED = [
    /^\.~lock\./,
    /~$/,
    /\.(crdownload|part|partial|tmp|swp|swx)$/i,
    /^\.onedrop-drive-/,
    /^\.DS_Store$/,
    /^Thumbs\.db$/,
];

if (!BASE || !TOKEN) {
    console.error(
        'drive: ONEDROP_DRIVE_URL and ONEDROP_DRIVE_TOKEN are needed.',
    );
    process.exit(1);
}

/**
 * What it knows: the places, every item it was told of (id => item), the feed's revision, and what each local path was
 * when it last matched Drive (path => {id, folder, size, mtimeMs, ino, revision}).
 */
let state = load();
let lastChangeHere = 0;
let lastPoll = 0;
let lastLoop = Date.now();
let relist = state.cursor === 0;

function load() {
    try {
        const saved = JSON.parse(readFileSync(STATE_FILE, 'utf8'));

        return {
            cursor: saved.cursor ?? 0,
            places: saved.places ?? [],
            items: saved.items ?? {},
            synced: saved.synced ?? {},
        };
    } catch {
        return { cursor: 0, places: [], items: {}, synced: {} };
    }
}

function save() {
    mkdirSync(dirname(STATE_FILE), { recursive: true });
    writeFileSync(`${STATE_FILE}.tmp`, JSON.stringify(state));
    renameSync(`${STATE_FILE}.tmp`, STATE_FILE);
}

function log(...args) {
    console.log(new Date().toISOString(), ...args);
}

async function api(method, path, { json, body, headers = {} } = {}) {
    const response = await fetch(`${BASE}${path}`, {
        method,
        headers: {
            authorization: `Bearer ${TOKEN}`,
            accept: 'application/json',
            ...(json ? { 'content-type': 'application/json' } : {}),
            ...headers,
        },
        ...(json || body ? { body: json ? JSON.stringify(json) : body } : {}),
        redirect: 'follow',
    });

    if (!response.ok) {
        const text = await response.text().catch(() => '');
        let message = text.slice(0, 300);

        try {
            message = JSON.parse(text).message ?? message;
        } catch {
            // Not JSON.
        }

        const error = new Error(
            `${method} ${path}: ${response.status} ${message}`,
        );
        error.status = response.status;
        throw error;
    }

    return response;
}

// --- What Drive has ----------------------------------------------------------------------------------------------

function remember(item) {
    if (item.space === null) {
        delete state.items[item.id];
    } else {
        state.items[item.id] = item;
    }
}

/** Start over: every place's items, from a revision taken first so nothing changing meanwhile is missed. */
async function listAll() {
    const { places, cursor } = await (
        await api('GET', `?after=${Number.MAX_SAFE_INTEGER}`)
    ).json();

    state.items = {};

    for (const place of places) {
        await listPlace(place.key);
    }

    state.places = places;
    state.cursor = cursor;
    relist = false;
    save();
}

async function listPlace(key) {
    for (let afterId = 0; ;) {
        const page = await (
            await api(
                'GET',
                `?space=${encodeURIComponent(key)}&after_id=${afterId}`,
            )
        ).json();

        page.items.forEach(remember);

        if (!page.more || page.items.length === 0) {
            return;
        }

        afterId = page.items[page.items.length - 1].id;
    }
}

/** What changed since the last time, and the places now kept. */
async function poll() {
    for (let more = true; more;) {
        const page = await (await api('GET', `?after=${state.cursor}`)).json();

        page.items.forEach(remember);
        await updatePlaces(page.places);
        state.cursor = page.cursor;
        more = page.more;
    }

    lastPoll = Date.now();
    save();
}

/** A new place (just joined a group) is listed; a place gone (left it) leaves this sandbox; a renamed one moves. */
async function updatePlaces(places) {
    const before = new Map(state.places.map((place) => [place.key, place]));

    for (const place of places) {
        const old = before.get(place.key);

        if (!old) {
            await listPlace(place.key);
        } else if (
            old.dir !== place.dir &&
            existsSync(join(ROOT, old.dir)) &&
            !existsSync(join(ROOT, place.dir))
        ) {
            mkdirSync(dirname(join(ROOT, place.dir)), { recursive: true });
            renameSync(join(ROOT, old.dir), join(ROOT, place.dir));
            rebase(old.dir, place.dir);
        }

        before.delete(place.key);
    }

    for (const gone of before.values()) {
        log('no longer kept here:', gone.dir);
        rmSync(join(ROOT, gone.dir), { recursive: true, force: true });
        forget(gone.dir);

        for (const [id, item] of Object.entries(state.items)) {
            if (item.space === gone.key) {
                delete state.items[id];
            }
        }
    }

    state.places = places;
}

/** Where each item belongs here: path (from ROOT) => item, for everything not in Trash in a kept place. */
function desired() {
    const places = new Map(state.places.map((place) => [place.key, place.dir]));
    const paths = new Map();
    const memo = new Map();

    const pathOf = (item, depth = 0) => {
        if (memo.has(item.id)) {
            return memo.get(item.id);
        }

        let path = null;

        if (!item.trashed && places.has(item.space) && depth < 64) {
            if (item.parent_id === null) {
                path = join(places.get(item.space), item.name);
            } else {
                const parent = state.items[item.parent_id];
                const parentPath =
                    parent && parent.folder && parent.space === item.space
                        ? pathOf(parent, depth + 1)
                        : null;

                path = parentPath ? join(parentPath, item.name) : null;
            }
        }

        memo.set(item.id, path);

        return path;
    };

    for (const item of Object.values(state.items)) {
        const path = pathOf(item);

        if (path) {
            paths.set(path, item);
        }
    }

    return paths;
}

// --- What's here ---------------------------------------------------------------------------------------------------

function ignored(name) {
    return IGNORED.some((pattern) => pattern.test(name));
}

/** Every file and folder in the kept places: path => {folder, size, mtimeMs, ino}. Links aren't followed. */
function scan() {
    const found = new Map();

    const walk = (dir) => {
        let entries = [];

        try {
            entries = readdirSync(join(ROOT, dir), { withFileTypes: true });
        } catch {
            return;
        }

        for (const entry of entries) {
            if (ignored(entry.name) || entry.isSymbolicLink()) {
                continue;
            }

            const path = join(dir, entry.name);

            try {
                const stats = lstatSync(join(ROOT, path));

                found.set(path, {
                    folder: stats.isDirectory(),
                    size: stats.isDirectory() ? 0 : stats.size,
                    mtimeMs: stats.mtimeMs,
                    ino: stats.ino,
                });

                if (stats.isDirectory()) {
                    walk(path);
                }
            } catch {
                // Gone meanwhile.
            }
        }
    };

    for (const place of state.places) {
        mkdirSync(join(ROOT, place.dir), { recursive: true });
        walk(place.dir);
    }

    return found;
}

function unchanged(here, known) {
    return here.folder
        ? known.folder
        : !known.folder &&
              here.size === known.size &&
              Math.abs(here.mtimeMs - known.mtimeMs) < 1;
}

function record(path, item) {
    const stats = statSync(join(ROOT, path));

    state.synced[path] = {
        id: item.id,
        folder: Boolean(item.folder),
        size: item.folder ? 0 : stats.size,
        mtimeMs: stats.mtimeMs,
        ino: stats.ino,
        revision: item.revision,
    };
}

/** Forget what's recorded at a path and under it. */
function forget(path) {
    for (const known of Object.keys(state.synced)) {
        if (known === path || known.startsWith(`${path}${sep}`)) {
            delete state.synced[known];
        }
    }
}

/** What's recorded under one path now lives under another. */
function rebase(from, to) {
    for (const known of Object.keys(state.synced)) {
        if (known === from || known.startsWith(`${from}${sep}`)) {
            state.synced[to + known.slice(from.length)] = state.synced[known];
            delete state.synced[known];
        }
    }
}

/** The place and folder a path is in: {space, parent_id, name}, or null when its folder isn't in Drive (yet). */
function locate(path, remote) {
    const parentPath = dirname(path);
    const place = state.places.find(
        (candidate) => candidate.dir === parentPath,
    );

    if (place) {
        return {
            space: place.key,
            parent_id: null,
            name: path.slice(parentPath.length + 1),
        };
    }

    const parent = remote.get(parentPath);

    return parent?.folder
        ? {
              space: parent.space,
              parent_id: parent.id,
              name: path.slice(parentPath.length + 1),
          }
        : null;
}

function conflictPath(path) {
    const stamp = new Date()
        .toISOString()
        .slice(0, 16)
        .replace('T', ' ')
        .replace(':', '.');
    const name = path.slice(dirname(path).length + 1);
    const dot = name.lastIndexOf('.');
    const renamed =
        dot > 0
            ? `${name.slice(0, dot)} (conflict ${stamp})${name.slice(dot)}`
            : `${name} (conflict ${stamp})`;

    return join(dirname(path), renamed);
}

function sha256(path) {
    return new Promise((resolve, reject) => {
        const hash = createHash('sha256');

        createReadStream(join(ROOT, path))
            .on('data', (chunk) => hash.update(chunk))
            .on('end', () => resolve(hash.digest('hex')))
            .on('error', reject);
    });
}

// --- Changes, both ways ------------------------------------------------------------------------------------------

async function download(path, item) {
    const target = join(ROOT, path);
    const temp = join(
        dirname(target),
        `.onedrop-drive-${item.id}-${process.pid}`,
    );

    mkdirSync(dirname(target), { recursive: true });
    const response = await api('GET', `/items/${item.id}/content`);
    writeFileSync(temp, Buffer.from(await response.arrayBuffer()));
    renameSync(temp, target);
    record(path, item);
    log('got', path);
}

async function upload(path, here, place, existing) {
    const hash = await sha256(path);
    const { upload: token, part_bytes: partBytes } = await (
        await api('POST', '/uploads', { json: { size: here.size } })
    ).json();
    const file = openSync(join(ROOT, path), 'r');

    try {
        const parts = Math.max(1, Math.ceil(here.size / partBytes));

        for (let index = 0; index < parts && here.size > 0; index++) {
            const length = Math.min(partBytes, here.size - index * partBytes);
            const buffer = Buffer.alloc(length);

            readSync(file, buffer, 0, length, index * partBytes);
            await api('PUT', `/uploads/parts/${index}`, {
                body: buffer,
                headers: {
                    'x-onedrop-upload': token,
                    'content-type': 'application/octet-stream',
                },
            });
        }
    } finally {
        closeSync(file);
    }

    const item = await (
        await api('POST', '/files', {
            json: {
                upload: token,
                ...place,
                sha256: hash,
                ...(existing
                    ? { item_id: existing.id, base_revision: existing.revision }
                    : {}),
            },
        })
    ).json();

    remember(item);

    const saved = join(dirname(path), item.name);

    // Drive kept it under another name (changed elsewhere at the same time): so does this copy.
    if (saved !== path && !existsSync(join(ROOT, saved))) {
        renameSync(join(ROOT, path), join(ROOT, saved));
        forget(path);
    }

    record(saved, item);
    log('sent', saved);
}

/** One pass: Drive's changes reach here, then this sandbox's changes reach Drive. */
async function reconcile() {
    let remote = desired();
    let byId = new Map([...remote].map(([path, item]) => [item.id, path]));
    const here = scan();
    const now = Date.now();
    const sortedSynced = () =>
        Object.entries(state.synced).sort(([a], [b]) => a.length - b.length);

    // Moved or renamed in Drive: move it here too, when it's as it was.
    for (const [path, known] of sortedSynced()) {
        const to = byId.get(known.id);
        const current = here.get(path);

        if (
            to &&
            to !== path &&
            current &&
            unchanged(current, known) &&
            !here.has(to) &&
            state.synced[path]
        ) {
            mkdirSync(dirname(join(ROOT, to)), { recursive: true });
            renameSync(join(ROOT, path), join(ROOT, to));
            rebase(path, to);

            // A copy: entries are moved while going through them.
            for (const [old, value] of Array.from(here)) {
                if (old === path || old.startsWith(`${path}${sep}`)) {
                    here.delete(old);
                    here.set(to + old.slice(path.length), value);
                }
            }

            log('moved', path, '→', to);
        }
    }

    // Gone from Drive (deleted, or moved where this sandbox doesn't keep): gone here, unless changed here meanwhile.
    for (const [path, known] of sortedSynced().reverse()) {
        if (byId.has(known.id) || !state.synced[path]) {
            continue;
        }

        const current = here.get(path);

        if (current && unchanged(current, known)) {
            if (known.folder) {
                const left = [...here.keys()].filter((other) =>
                    other.startsWith(`${path}${sep}`),
                );

                // Only an empty folder, or one holding only what was deleted with it.
                if (
                    left.every(
                        (other) =>
                            state.synced[other] &&
                            unchanged(here.get(other), state.synced[other]),
                    )
                ) {
                    rmSync(join(ROOT, path), { recursive: true, force: true });
                    left.forEach((other) => here.delete(other));
                    forget(path);
                    here.delete(path);
                    log('removed', path);
                }
            } else {
                rmSync(join(ROOT, path), { force: true });
                here.delete(path);
                delete state.synced[path];
                log('removed', path);
            }
        } else if (!current) {
            delete state.synced[path];
        } else {
            // Changed here: sent again below as a new file.
            delete state.synced[path];
        }
    }

    // New or changed in Drive.
    for (const [path, item] of [...remote].sort(
        ([a], [b]) => a.length - b.length,
    )) {
        const current = here.get(path);
        const known = state.synced[path];

        try {
            if (item.folder) {
                if (!current) {
                    if (known) {
                        continue; // Deleted here: sent below.
                    }

                    mkdirSync(join(ROOT, path), { recursive: true });
                    here.set(path, {
                        folder: true,
                        size: 0,
                        mtimeMs: now,
                        ino: statSync(join(ROOT, path)).ino,
                    });
                }

                if (here.get(path)?.folder) {
                    record(path, item);
                }

                continue;
            }

            if (!current) {
                if (!known) {
                    await download(path, item);
                }

                continue;
            }

            if (known && known.id === item.id) {
                if (item.revision > known.revision) {
                    // Only its place or folder changed (a folder moved to another drive): the contents are the same.
                    if (
                        unchanged(current, known) &&
                        item.sha256 &&
                        current.size === item.size &&
                        (await sha256(path)) === item.sha256
                    ) {
                        record(path, item);
                        continue;
                    }

                    if (!unchanged(current, known)) {
                        // Changed in both places: keep this one beside Drive's.
                        renameSync(
                            join(ROOT, path),
                            join(ROOT, conflictPath(path)),
                        );
                    }

                    await download(path, item);
                }

                continue;
            }

            if (!known && !current.folder) {
                // Made in both places at once.
                if (
                    item.sha256 &&
                    current.size === item.size &&
                    (await sha256(path)) === item.sha256
                ) {
                    record(path, item);
                } else {
                    renameSync(
                        join(ROOT, path),
                        join(ROOT, conflictPath(path)),
                    );
                    await download(path, item);
                }
            }
        } catch (error) {
            log("couldn't get", path, error.message);
        }
    }

    // From here on, this sandbox's changes go to Drive.
    const fresh = scan();
    remote = desired();
    byId = new Map([...remote].map(([path, item]) => [item.id, path]));

    const missing = Object.entries(state.synced).filter(
        ([path]) => !fresh.has(path),
    );
    const added = [...fresh]
        .filter(([path]) => !state.synced[path] && !remote.has(path))
        .sort(([a], [b]) => a.length - b.length);
    const movedFrom = new Set();

    for (const [path, value] of added) {
        try {
            // A rename or move here: the same file (inode) under a new name.
            const source = missing.find(
                ([from, known]) =>
                    !movedFrom.has(from) &&
                    known.ino === value.ino &&
                    Boolean(known.folder) === value.folder &&
                    (known.folder || known.size === value.size),
            );
            const place = locate(path, remote);

            if (source && place && state.items[source[1].id]) {
                const [from, known] = source;
                const item = await (
                    await api('PATCH', `/items/${known.id}`, {
                        json: {
                            ...place,
                            base_revision: state.items[known.id].revision,
                        },
                    })
                ).json();

                remember(item);
                movedFrom.add(from);
                rebase(from, path);
                record(path, item);
                lastChangeHere = Date.now();
                log('moved', from, '→', path);
                continue;
            }

            if (value.folder) {
                if (!place) {
                    continue; // Its own folder goes first; next pass.
                }

                const item = await (
                    await api('POST', '/folders', { json: place })
                ).json();

                remember(item);
                record(path, item);
                remote.set(path, item);
                lastChangeHere = Date.now();
                log('made', path);
                continue;
            }

            if (place && now - value.mtimeMs > SETTLE_MS) {
                await upload(path, value, place, null);
                lastChangeHere = Date.now();
            }
        } catch (error) {
            log("couldn't send", path, error.message);

            if (error.status === 409) {
                relist = true;
            }
        }
    }

    // Changed here.
    for (const [path, value] of fresh) {
        const known = state.synced[path];
        const item = known ? state.items[known.id] : null;

        if (
            !known ||
            value.folder ||
            unchanged(value, known) ||
            Date.now() - value.mtimeMs < SETTLE_MS
        ) {
            continue;
        }

        try {
            const place = locate(path, remote);

            if (place) {
                await upload(
                    path,
                    value,
                    place,
                    item && byId.get(item.id) === path
                        ? { id: item.id, revision: known.revision }
                        : null,
                );
                lastChangeHere = Date.now();
            }
        } catch (error) {
            log("couldn't send", path, error.message);
        }
    }

    // Deleted here: to Drive's Trash, deepest last so a folder takes what's in it.
    for (const [path, known] of missing.sort(
        ([a], [b]) => a.length - b.length,
    )) {
        if (movedFrom.has(path) || !state.synced[path] || fresh.has(path)) {
            continue;
        }

        try {
            if (byId.get(known.id) === path) {
                await api('DELETE', `/items/${known.id}`);
                log('trashed', path);
                lastChangeHere = Date.now();
            }
        } catch (error) {
            log("couldn't trash", path, error.message);
        }

        forget(path);
    }

    save();
}

// --- When to look ------------------------------------------------------------------------------------------------

function inUse() {
    const now = Date.now();

    try {
        if (now - statSync(ACTIVE_FILE).mtimeMs < IN_USE_MS) {
            return true;
        }
    } catch {
        // Never opened.
    }

    if (now - lastChangeHere < IN_USE_MS) {
        return true;
    }

    try {
        // An agent working.
        if (
            readdirSync('/tmp').some((name) =>
                /^onedrop-agent.*\.pid$/.test(name),
            )
        ) {
            return true;
        }
    } catch {
        // No /tmp listing.
    }

    try {
        // Someone in the Shell.
        return (
            execFileSync('tmux', ['-L', 'onedrop', 'list-clients'], {
                encoding: 'utf8',
                stdio: ['ignore', 'pipe', 'ignore'],
            }).trim() !== ''
        );
    } catch {
        return false;
    }
}

mkdirSync(ROOT, { recursive: true });
log('keeping Drive in', ROOT);

for (;;) {
    const now = Date.now();
    // A long gap means the sandbox slept: look once on waking.
    const woke = now - lastLoop > 30_000;
    const active = inUse();

    lastLoop = now;

    try {
        if (relist) {
            await listAll();
        } else if (active || woke || lastPoll === 0) {
            await poll();
        }

        await reconcile();
    } catch (error) {
        log(error.message);

        if (error.status === 403) {
            // This sandbox may no longer reach Drive (its project went); start.sh tries again later.
            process.exit(1);
        }
    }

    await sleep(active ? BUSY_MS : IDLE_SCAN_MS);
}
