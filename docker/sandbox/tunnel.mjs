#!/usr/bin/env node
// The desktop app's way into the sandbox (DESK-007..009; docs/development/desktop-link.mdx). host-proxy.mjs sends
// WebSockets for /__onedrop/tunnel here, and each one is either:
// - a forward (DESK-007, DESK-008): one TCP connection to a port in the sandbox, its bytes as binary messages;
// - the network control connection (DESK-009): the hosts the user's computer will dial for the sandbox. Each gets a
//   listener on 127.0.0.1 (and the HTTP proxy on 7690 takes them by name); a connection to one asks the app to dial,
//   and the app's dial WebSocket carries its bytes;
// - a dial for one of those connections.
// Forwards and the control connection need a ticket signed with the key in ~/.onedrop/tunnel-key (read for each
// connection, so a new key works at once); dials need the one-time token their `open` message gave.
// `/opt/onedrop/tunnel ensure` starts this. No npm packages: the WebSocket server is written out below (RFC 6455).
//
// Env: TUNNEL_PORT (7682), TUNNEL_PROXY_PORT (7690), PORT, PROXY_PORT, SHELL_PORT, SSH_PORT (never given to a host).
import {
    createHash,
    createHmac,
    randomBytes,
    randomUUID,
    timingSafeEqual,
} from 'node:crypto';
import { mkdirSync, readFileSync, renameSync, writeFileSync } from 'node:fs';
import http from 'node:http';
import net from 'node:net';
import { homedir } from 'node:os';

const TUNNEL_PORT = Number(process.env.TUNNEL_PORT || 7682);
const NETWORK_PROXY_PORT = Number(process.env.TUNNEL_PROXY_PORT || 7690);
const NETWORK_PROXY = `http://127.0.0.1:${NETWORK_PROXY_PORT}`;
const TUNNEL_PATH = '/__onedrop/tunnel';
const STATE_DIR = `${process.env.HOME || homedir()}/.onedrop`;
const KEY_FILE = `${STATE_DIR}/tunnel-key`;
const NETWORK_FILE = `${STATE_DIR}/network.json`;
// What `tunnel ensure` compares with the file on disk, to restart a tunnel an update changed.
const VERSION = createHash('sha256')
    .update(readFileSync(new URL(import.meta.url)))
    .digest('hex');

// The sandbox's own services, which a host's listener never takes (and forwards never reach the tunnel itself).
const RESERVED_PORTS = new Set(
    [
        process.env.PORT || 8000,
        process.env.PROXY_PORT || 8081,
        process.env.SHELL_PORT || 7681,
        process.env.SSH_PORT || 2222,
        TUNNEL_PORT,
        NETWORK_PROXY_PORT,
    ].map(Number),
);
// Where a host goes when its own port is taken.
const FIRST_SPARE_PORT = 15000;
const DIAL_TTL_MS = 30_000;
const PING_INTERVAL_MS = 25_000;
// A message larger than this closes its WebSocket (1009); TCP data comes in much smaller pieces.
const MAX_MESSAGE_BYTES = 16 * 1024 * 1024;
const MAX_HOSTS = 100;

/** A ticket's payload when its signature verifies with the current key and it hasn't expired, else null. */
function verifyTicket(ticket) {
    const [body, signature, extra] = String(ticket ?? '').split('.');

    if (!body || !signature || extra !== undefined) {
        return null;
    }

    let key;

    try {
        key = readFileSync(KEY_FILE, 'utf8').trim();
    } catch {
        return null;
    }

    if (!key) {
        return null;
    }

    const expected = createHmac('sha256', key).update(body).digest();
    const given = Buffer.from(signature, 'base64url');

    if (given.length !== expected.length || !timingSafeEqual(given, expected)) {
        return null;
    }

    let payload;

    try {
        payload = JSON.parse(Buffer.from(body, 'base64url').toString('utf8'));
    } catch {
        return null;
    }

    if (
        !payload ||
        typeof payload !== 'object' ||
        !Number.isFinite(payload.exp) ||
        payload.exp * 1000 <= Date.now()
    ) {
        return null;
    }

    if (payload.p === 'network') {
        return payload;
    }

    if (
        payload.p === 'forward' &&
        Number.isInteger(payload.port) &&
        payload.port > 0 &&
        payload.port < 65536 &&
        payload.port !== TUNNEL_PORT &&
        payload.port !== NETWORK_PROXY_PORT
    ) {
        return payload;
    }

    return null;
}

// --- WebSockets (RFC 6455, server side) ---

const WS_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
const utf8 = new TextDecoder('utf-8', { fatal: true });
/** @type {Set<WebSocket>} */
const sockets = new Set();

class WebSocket {
    constructor(socket, head) {
        this.socket = socket;
        this.buffer = head?.length ? Buffer.from(head) : Buffer.alloc(0);
        this.fragments = [];
        this.fragmentsBytes = 0;
        this.fragmentsBinary = false;
        this.closeSent = false;
        this.closed = false;
        this.alive = true;
        this.listeners = {
            message: [],
            close: [],
            drain: [],
        };

        socket.setNoDelay(true);
        socket.on('data', (chunk) => this.receive(chunk));
        socket.on('drain', () => this.emit('drain'));
        socket.on('error', () => socket.destroy());
        socket.on('close', () => this.finish());
        sockets.add(this);

        if (this.buffer.length) {
            queueMicrotask(() => this.receive(Buffer.alloc(0)));
        }
    }

    on(event, listener) {
        this.listeners[event].push(listener);
    }

    emit(event, ...args) {
        for (const listener of this.listeners[event]) {
            listener(...args);
        }
    }

    /** Send a message; false when the socket's write buffer is full (wait for 'drain'). */
    send(data, binary = true) {
        if (this.closeSent || this.closed) {
            return false;
        }

        return this.frame(
            binary ? 0x2 : 0x1,
            Buffer.isBuffer(data) ? data : Buffer.from(String(data)),
        );
    }

    sendJson(message) {
        return this.send(JSON.stringify(message), false);
    }

    ping() {
        if (!this.alive) {
            this.socket.destroy();

            return;
        }

        this.alive = false;
        this.frame(0x9, Buffer.alloc(0));
    }

    /** Start the closing handshake; the socket ends when the other side answers, or after 5 seconds. */
    close(code = 1000, reason = '') {
        if (this.closeSent || this.closed) {
            return;
        }

        const text = Buffer.from(String(reason)).subarray(0, 123);
        const payload = Buffer.alloc(2 + text.length);
        payload.writeUInt16BE(code, 0);
        text.copy(payload, 2);
        this.frame(0x8, payload);
        this.closeSent = true;
        this.closeCode = code;
        setTimeout(() => this.socket.destroy(), 5000).unref();
    }

    pause() {
        this.socket.pause();
    }

    resume() {
        this.socket.resume();
    }

    frame(opcode, payload) {
        let header;

        if (payload.length < 126) {
            header = Buffer.from([0x80 | opcode, payload.length]);
        } else if (payload.length < 65536) {
            header = Buffer.alloc(4);
            header[0] = 0x80 | opcode;
            header[1] = 126;
            header.writeUInt16BE(payload.length, 2);
        } else {
            header = Buffer.alloc(10);
            header[0] = 0x80 | opcode;
            header[1] = 127;
            header.writeBigUInt64BE(BigInt(payload.length), 2);
        }

        if (!this.socket.writable) {
            return false;
        }

        this.socket.cork();
        this.socket.write(header);
        const ok = this.socket.write(payload);
        this.socket.uncork();

        return ok;
    }

    /** Fail the connection: say why, then drop it. */
    fail(code, reason) {
        this.close(code, reason);
        this.buffer = Buffer.alloc(0);
        this.socket.end();
    }

    receive(chunk) {
        this.alive = true;
        this.buffer = this.buffer.length
            ? Buffer.concat([this.buffer, chunk])
            : chunk;

        while (!this.closed && this.buffer.length >= 2) {
            const first = this.buffer[0];
            const second = this.buffer[1];
            const fin = (first & 0x80) !== 0;
            const opcode = first & 0x0f;
            const masked = (second & 0x80) !== 0;
            let length = second & 0x7f;
            let offset = 2;

            if (first & 0x70) {
                return this.fail(1002, 'Unexpected reserved bits');
            }

            if (!masked) {
                return this.fail(1002, 'Client frames must be masked');
            }

            if (length === 126) {
                if (this.buffer.length < 4) {
                    return;
                }

                length = this.buffer.readUInt16BE(2);
                offset = 4;
            } else if (length === 127) {
                if (this.buffer.length < 10) {
                    return;
                }

                const long = this.buffer.readBigUInt64BE(2);

                if (long > BigInt(MAX_MESSAGE_BYTES)) {
                    return this.fail(1009, 'Message too large');
                }

                length = Number(long);
                offset = 10;
            }

            if (length > MAX_MESSAGE_BYTES) {
                return this.fail(1009, 'Message too large');
            }

            if (this.buffer.length < offset + 4 + length) {
                return;
            }

            const mask = this.buffer.subarray(offset, offset + 4);
            const payload = Buffer.from(
                this.buffer.subarray(offset + 4, offset + 4 + length),
            );
            this.buffer = this.buffer.subarray(offset + 4 + length);

            for (let i = 0; i < payload.length; i++) {
                payload[i] ^= mask[i & 3];
            }

            if (this.handle(fin, opcode, payload) === false) {
                return;
            }
        }
    }

    /** One frame; false once the connection is failing. */
    handle(fin, opcode, payload) {
        if (opcode >= 0x8) {
            if (!fin || payload.length > 125) {
                this.fail(1002, 'Bad control frame');

                return false;
            }

            if (opcode === 0x8) {
                const code = payload.length >= 2 ? payload.readUInt16BE(0) : 0;

                // Echo the other side's code when it's one that may be sent.
                if (!this.closeSent) {
                    this.close(
                        (code >= 1000 && code <= 1003) ||
                            (code >= 1007 && code <= 1011) ||
                            (code >= 3000 && code <= 4999)
                            ? code
                            : 1000,
                    );
                }

                this.socket.end();

                return false;
            }

            if (opcode === 0x9) {
                this.frame(0xa, payload);
            } else if (opcode !== 0xa) {
                this.fail(1002, 'Unknown opcode');

                return false;
            }

            return true;
        }

        if (opcode === 0x0) {
            if (!this.fragments.length) {
                this.fail(1002, 'Unexpected continuation frame');

                return false;
            }
        } else if (opcode === 0x1 || opcode === 0x2) {
            if (this.fragments.length) {
                this.fail(1002, 'Expected a continuation frame');

                return false;
            }

            this.fragmentsBinary = opcode === 0x2;
        } else {
            this.fail(1002, 'Unknown opcode');

            return false;
        }

        this.fragments.push(payload);
        this.fragmentsBytes += payload.length;

        if (this.fragmentsBytes > MAX_MESSAGE_BYTES) {
            this.fail(1009, 'Message too large');

            return false;
        }

        if (!fin) {
            return true;
        }

        const message =
            this.fragments.length === 1
                ? this.fragments[0]
                : Buffer.concat(this.fragments);
        this.fragments = [];
        this.fragmentsBytes = 0;

        if (this.fragmentsBinary) {
            this.emit('message', message, true);

            return true;
        }

        let text;

        try {
            text = utf8.decode(message);
        } catch {
            this.fail(1007, 'Text messages must be UTF-8');

            return false;
        }

        this.emit('message', text, false);

        return true;
    }

    finish() {
        if (this.closed) {
            return;
        }

        this.closed = true;
        sockets.delete(this);
        this.emit('close');
    }
}

/** Finish the opening handshake on an upgrade request; null (after refusing it) if it isn't a WebSocket. */
function accept(req, socket, head) {
    const key = req.headers['sec-websocket-key'];

    if (
        String(req.headers.upgrade ?? '').toLowerCase() !== 'websocket' ||
        req.headers['sec-websocket-version'] !== '13' ||
        typeof key !== 'string' ||
        Buffer.from(key, 'base64').length !== 16
    ) {
        refuse(socket, 400, 'Bad Request');

        return null;
    }

    const answer = createHash('sha1')
        .update(key + WS_GUID)
        .digest('base64');

    socket.write(
        'HTTP/1.1 101 Switching Protocols\r\n' +
            'Upgrade: websocket\r\n' +
            'Connection: Upgrade\r\n' +
            `Sec-WebSocket-Accept: ${answer}\r\n\r\n`,
    );

    return new WebSocket(socket, head);
}

function refuse(socket, status, text) {
    socket.end(
        `HTTP/1.1 ${status} ${text}\r\nConnection: close\r\nContent-Length: 0\r\n\r\n`,
    );
}

/**
 * Carry a TCP connection's bytes over a WebSocket, both ways, until either closes. Each side stops reading while
 * the other's write buffer is full.
 */
function pipe(ws, tcp) {
    tcp.on('data', (chunk) => {
        if (!ws.send(chunk)) {
            tcp.pause();
        }
    });
    ws.on('drain', () => tcp.resume());
    ws.on('message', (data, binary) => {
        if (binary && !tcp.destroyed && !tcp.write(data)) {
            ws.pause();
        }
    });
    tcp.on('drain', () => ws.resume());
    tcp.on('end', () => ws.close(1000));
    tcp.on('error', (error) => ws.close(1011, error.message));
    tcp.on('close', () => ws.close(1000));
    ws.on('close', () => tcp.end());
}

// --- Forwards ---

function forward(ws, port) {
    const tcp = net.connect(port, '127.0.0.1');

    pipe(ws, tcp);
}

// --- The network ---

/**
 * The control connection and what it set up.
 * @type {{ ws: WebSocket, through: string|null, hosts: Array<{ host: string, port: number, address: string, server: net.Server }> } | null}
 */
let network = null;
/** Connections to a host's listener waiting for the app to dial: id => what's needed to finish it. */
const dials = new Map();

function writeNetworkFile(state) {
    try {
        mkdirSync(STATE_DIR, { recursive: true, mode: 0o700 });
        const temporary = `${NETWORK_FILE}.${process.pid}.tmp`;
        writeFileSync(temporary, `${JSON.stringify(state, null, 2)}\n`);
        renameSync(temporary, NETWORK_FILE);
    } catch (error) {
        console.error(
            `tunnel: couldn't write ${NETWORK_FILE}: ${error.message}`,
        );
    }
}

function publicHosts(hosts) {
    return hosts.map(({ host, port, address }) => ({ host, port, address }));
}

function saveNetwork() {
    writeNetworkFile({
        connected: network !== null,
        through: network?.through ?? null,
        proxy: NETWORK_PROXY,
        hosts: network ? publicHosts(network.hosts) : [],
    });
}

/** Listen on 127.0.0.1:port; false if the port can't be had. */
function listenOn(server, port) {
    return new Promise((resolve) => {
        const failed = () => resolve(false);

        server.once('error', failed);
        server.listen({ port, host: '127.0.0.1', exclusive: true }, () => {
            server.off('error', failed);
            resolve(true);
        });
    });
}

/** A listener for a host: its own port when it's free and not the sandbox's, else the first free one from 15000. */
async function listenFor({ host, port: hostPort }, taken) {
    const entry = { host, port: hostPort, address: null, server: null };
    const server = net.createServer((client) => openDial(entry, client));
    const candidates = [];

    if (!RESERVED_PORTS.has(entry.port) && !taken.has(entry.port)) {
        candidates.push(entry.port);
    }

    for (let port = FIRST_SPARE_PORT; port < 65536; port++) {
        if (!RESERVED_PORTS.has(port) && !taken.has(port)) {
            candidates.push(port);
        }
    }

    for (const port of candidates) {
        if (await listenOn(server, port)) {
            taken.add(port);
            entry.address = `127.0.0.1:${port}`;
            entry.server = server;

            return entry;
        }
    }

    return null;
}

/** A host from the app's list, or null when it isn't one. */
function parseHost(item) {
    const host = String(item?.host ?? '')
        .trim()
        .toLowerCase();
    const port = Number(item?.port);

    if (
        !/^[a-z0-9._-]{1,253}$|^\[[0-9a-f:.]+\]$/.test(host) ||
        !Number.isInteger(port) ||
        port < 1 ||
        port > 65535
    ) {
        return null;
    }

    return { host, port };
}

/** Replace the listened-for hosts with the app's new list; hosts on both lists keep their listener. */
async function setHosts(control, message) {
    const wanted = new Map();

    for (const item of Array.isArray(message.hosts) ? message.hosts : []) {
        const entry = parseHost(item);

        if (entry && wanted.size < MAX_HOSTS) {
            wanted.set(`${entry.host}:${entry.port}`, entry);
        }
    }

    const kept = [];

    for (const entry of control.hosts) {
        if (wanted.has(`${entry.host}:${entry.port}`)) {
            kept.push(entry);
            wanted.delete(`${entry.host}:${entry.port}`);
        } else {
            entry.server.close();
        }
    }

    const taken = new Set(
        kept.map((entry) => Number(entry.address.split(':')[1])),
    );

    for (const entry of wanted.values()) {
        const listening = await listenFor(entry, taken);

        if (listening) {
            kept.push(listening);
        }
    }

    if (network !== control) {
        for (const entry of kept) {
            entry.server.close();
        }

        return;
    }

    control.hosts = kept;
    control.through =
        typeof message.through === 'string'
            ? message.through.slice(0, 200)
            : null;
    saveNetwork();
    control.ws.sendJson({
        type: 'listening',
        hosts: publicHosts(kept),
        proxy: NETWORK_PROXY,
    });
}

/** A connection to a host's listener: hold it and ask the app to dial. */
function openDial(entry, client) {
    const control = network;

    if (!control || !control.hosts.includes(entry)) {
        client.destroy();

        return;
    }

    const id = randomUUID();
    const token = randomBytes(24).toString('base64url');
    const timer = setTimeout(() => dropDial(id), DIAL_TTL_MS);

    client.pause();
    client.on('error', () => dropDial(id));
    client.on('close', () => dials.delete(id));
    dials.set(id, { token, client, timer, control });
    control.ws.sendJson({
        type: 'open',
        id,
        host: entry.host,
        port: entry.port,
        token,
    });
}

function dropDial(id) {
    const dial = dials.get(id);

    if (dial) {
        dials.delete(id);
        clearTimeout(dial.timer);
        dial.client.destroy();
    }
}

/** The waiting connection a dial's id and token belong to, taken so the token works once; null otherwise. */
function takeDial(id, token) {
    const dial = dials.get(String(id ?? ''));

    if (!dial) {
        return null;
    }

    const given = createHash('sha256')
        .update(String(token ?? ''))
        .digest();
    const expected = createHash('sha256').update(dial.token).digest();

    if (!timingSafeEqual(given, expected)) {
        return null;
    }

    dials.delete(String(id));
    clearTimeout(dial.timer);

    return dial;
}

/** A new control connection, which replaces any other. */
function controlNetwork(ws) {
    if (network) {
        const old = network;
        endNetwork(old);
        old.ws.close(1000, 'Replaced by a newer connection');
    }

    const control = { ws, through: null, hosts: [] };
    let queue = Promise.resolve();
    network = control;

    ws.on('message', (data, binary) => {
        if (binary) {
            return;
        }

        let message;

        try {
            message = JSON.parse(data);
        } catch {
            return;
        }

        if (message?.type === 'hosts') {
            queue = queue.then(() => setHosts(control, message));
        } else if (message?.type === 'refused') {
            const dial = dials.get(String(message.id ?? ''));

            if (dial?.control === control) {
                dropDial(String(message.id));
            }
        }
    });
    ws.on('close', () => endNetwork(control));
}

/** Close a control connection's listeners and waiting connections; the network's gone if it was the current one. */
function endNetwork(control) {
    for (const entry of control.hosts) {
        entry.server.close();
    }

    control.hosts = [];

    for (const [id, dial] of dials) {
        if (dial.control === control) {
            dropDial(id);
        }
    }

    if (network === control) {
        network = null;
        writeNetworkFile({
            connected: false,
            through: control.through,
            proxy: NETWORK_PROXY,
            hosts: [],
        });
    }
}

/** The listener for host:port, if the app listed it. */
function listedHost(host, port) {
    const name = String(host ?? '').toLowerCase();

    return (
        network?.hosts.find(
            (entry) => entry.host === name && entry.port === port,
        ) ?? null
    );
}

function listenerPort(entry) {
    return Number(entry.address.split(':')[1]);
}

// --- The HTTP proxy for listed hosts, under their own names ---

/** The listed host an absolute http:// URL is for, and the path to ask it for; null otherwise. */
function proxyTarget(url) {
    let parsed;

    try {
        parsed = new URL(String(url));
    } catch {
        return null;
    }

    if (parsed.protocol !== 'http:') {
        return null;
    }

    const entry = listedHost(parsed.hostname, Number(parsed.port || 80));

    return entry ? { entry, path: `${parsed.pathname}${parsed.search}` } : null;
}

function withoutProxyHeaders(headers) {
    const copy = { ...headers };
    delete copy['proxy-connection'];
    delete copy['proxy-authorization'];

    return copy;
}

const networkProxy = http.createServer((req, res) => {
    const target = proxyTarget(req.url);

    if (!target) {
        res.writeHead(403, { 'content-type': 'text/plain' });
        res.end('Not a host this sandbox can reach.\n');

        return;
    }

    const upstream = http.request(
        {
            host: '127.0.0.1',
            port: listenerPort(target.entry),
            method: req.method,
            path: target.path,
            headers: withoutProxyHeaders(req.headers),
            agent: false,
        },
        (response) => {
            res.writeHead(
                response.statusCode ?? 502,
                response.statusMessage,
                response.headers,
            );
            response.pipe(res);
        },
    );

    upstream.on('error', () => {
        if (!res.headersSent) {
            res.writeHead(502, { 'content-type': 'text/plain' });
        }

        res.end();
    });
    req.pipe(upstream);
});

networkProxy.on('connect', (req, socket, head) => {
    const match = /^(\[[^\]]+\]|[^:]+):(\d+)$/.exec(String(req.url ?? ''));
    const entry = match ? listedHost(match[1], Number(match[2])) : null;

    if (!entry) {
        refuse(socket, 403, 'Forbidden');

        return;
    }

    const upstream = net.connect(listenerPort(entry), '127.0.0.1', () => {
        socket.write('HTTP/1.1 200 Connection Established\r\n\r\n');

        if (head?.length) {
            upstream.write(head);
        }

        socket.pipe(upstream).pipe(socket);
    });

    upstream.on('error', () => socket.destroy());
    socket.on('error', () => upstream.destroy());
});

// WebSockets to a listed host (ws://), passed on as they are.
networkProxy.on('upgrade', (req, socket, head) => {
    const target = proxyTarget(req.url);

    if (!target) {
        refuse(socket, 403, 'Forbidden');

        return;
    }

    const upstream = net.connect(
        listenerPort(target.entry),
        '127.0.0.1',
        () => {
            const lines = [
                `${req.method} ${target.path} HTTP/${req.httpVersion}`,
            ];

            for (let i = 0; i < req.rawHeaders.length; i += 2) {
                if (!/^proxy-/i.test(req.rawHeaders[i])) {
                    lines.push(
                        `${req.rawHeaders[i]}: ${req.rawHeaders[i + 1]}`,
                    );
                }
            }

            upstream.write(`${lines.join('\r\n')}\r\n\r\n`);

            if (head?.length) {
                upstream.write(head);
            }

            socket.pipe(upstream).pipe(socket);
        },
    );

    upstream.on('error', () => socket.destroy());
    socket.on('error', () => upstream.destroy());
});

// --- The tunnel itself ---

const server = http.createServer((req, res) => {
    // For `tunnel ensure`: is it up, and is it the current version?
    if (req.method === 'GET' && req.url === '/health') {
        res.writeHead(200, { 'content-type': 'application/json' });
        res.end(JSON.stringify({ pid: process.pid, version: VERSION }));

        return;
    }

    res.writeHead(404);
    res.end();
});

server.on('upgrade', (req, socket, head) => {
    const url = new URL(String(req.url ?? '/'), 'http://localhost');

    socket.on('error', () => socket.destroy());

    if (url.pathname !== TUNNEL_PATH) {
        refuse(socket, 404, 'Not Found');

        return;
    }

    if (url.searchParams.has('dial')) {
        const dial = takeDial(
            url.searchParams.get('dial'),
            url.searchParams.get('token'),
        );

        if (!dial) {
            refuse(socket, 403, 'Forbidden');

            return;
        }

        const ws = accept(req, socket, head);

        if (ws) {
            pipe(ws, dial.client);
            dial.client.resume();
        } else {
            dial.client.destroy();
        }

        return;
    }

    const ticket = verifyTicket(url.searchParams.get('ticket'));

    if (!ticket) {
        refuse(socket, 403, 'Forbidden');

        return;
    }

    const ws = accept(req, socket, head);

    if (!ws) {
        return;
    }

    if (ticket.p === 'forward') {
        forward(ws, ticket.port);
    } else {
        controlNetwork(ws);
    }
});

setInterval(() => {
    for (const ws of sockets) {
        ws.ping();
    }
}, PING_INTERVAL_MS).unref();

// A tunnel that stopped while connected left the file saying so.
saveNetwork();

for (const [listener, port] of [
    [server, TUNNEL_PORT],
    [networkProxy, NETWORK_PROXY_PORT],
]) {
    listener.on('error', (error) => {
        console.error(`tunnel: ${error.message}`);
        process.exit(1);
    });
    listener.listen(port, '127.0.0.1');
}
