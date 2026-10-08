// Run with: node --test infra/cloudflare/preview-gateway/device-relay.test.mjs
import assert from 'node:assert/strict';
import { createHmac } from 'node:crypto';
import test from 'node:test';
import {
    DeviceRelay,
    frame,
    MAX_CHUNK,
    readFrame,
    verifyTicket,
} from './device-relay.js';
import worker from './worker.js';

const SECRET = 'the-secret';

/**
 * One end of a Cloudflare WebSocket pair, recording what's sent on it.
 */
class FakeSocket {
    constructor() {
        this.sent = [];
        this.listeners = {};
        this.readyState = 1;
        this.closed = null;
        this.attachment = null;
    }

    send(message) {
        if (this.readyState !== 1) {
            throw new Error('closed');
        }

        this.sent.push(message);
    }

    close(code, reason) {
        this.readyState = 3;
        this.closed = { code, reason };
    }

    accept() {
        this.accepted = true;
    }

    addEventListener(type, listener) {
        (this.listeners[type] ??= []).push(listener);
    }

    emit(type, event) {
        for (const listener of this.listeners[type] ?? []) {
            listener(event);
        }
    }

    serializeAttachment(value) {
        this.attachment = structuredClone(value);
    }

    deserializeAttachment() {
        return this.attachment;
    }
}

globalThis.WebSocketPair = class {
    constructor() {
        this[0] = new FakeSocket();
        this[1] = new FakeSocket();
    }
};

/** Node's Response can't be a 101, so the relay's WebSocket answers are plain objects here. */
class TestRelay extends DeviceRelay {
    switchingProtocols(webSocket, headers = {}) {
        return { status: 101, webSocket, headers };
    }
}

function fakeState() {
    const sockets = [];

    return {
        sockets,
        acceptWebSocket(socket, tags) {
            socket.tags = tags;
            sockets.push(socket);
        },
        getWebSockets(tag) {
            return sockets.filter((socket) => socket.tags.includes(tag));
        },
    };
}

function makeRelay() {
    const state = fakeState();

    return { relay: new TestRelay(state, { GATEWAY_SECRET: SECRET }), state };
}

function base64url(value) {
    return Buffer.from(value).toString('base64url');
}

function ticket(payload, secret = SECRET) {
    const part = base64url(JSON.stringify(payload));

    return `${part}.${createHmac('sha256', secret).update(part).digest('base64url')}`;
}

function inAMinute() {
    return Math.floor(Date.now() / 1000) + 60;
}

/**
 * The desktop app connects; returns the relay's end of its socket.
 */
async function connect(relay, state, device = 4) {
    const response = await relay.fetch(
        new Request(
            `https://relay.onedrop.io/__onedrop/devices/${device}/connect?ticket=${ticket({ d: device, exp: inAMinute() })}`,
            { headers: { Upgrade: 'websocket' } },
        ),
    );

    assert.equal(response.status, 101);

    return state.sockets.at(-1);
}

function relayed(path, init = {}) {
    return new Request(`https://relay.onedrop.io/__onedrop/devices/4${path}`, {
        ...init,
        headers: { 'X-OneDrop-Gateway-Secret': SECRET, ...init.headers },
    });
}

/** What the relay sent the desktop app: JSON messages, and frames for binary ones. */
function messages(socket) {
    return socket.sent.map((message) =>
        typeof message === 'string' ? JSON.parse(message) : readFrame(message),
    );
}

/** Wait for the desktop app to have been sent this many messages, and return them. */
async function received(socket, count) {
    await until(() => socket.sent.length >= count);

    return messages(socket);
}

async function until(condition) {
    for (let i = 0; i < 100; i++) {
        if (condition()) {
            return;
        }

        await new Promise((resolve) => setImmediate(resolve));
    }

    assert.fail('timed out');
}

test('the desktop app connects only with a valid, unexpired ticket for its own computer', async () => {
    const { relay, state } = makeRelay();
    const attempt = (value, device = 4) =>
        relay.fetch(
            new Request(
                `https://relay.onedrop.io/__onedrop/devices/${device}/connect?ticket=${value}`,
                { headers: { Upgrade: 'websocket' } },
            ),
        );

    assert.equal(
        (await attempt(ticket({ d: 4, exp: inAMinute() }, 'wrong-secret')))
            .status,
        403,
    );
    assert.equal(
        (
            await attempt(
                ticket({ d: 4, exp: Math.floor(Date.now() / 1000) - 1 }),
            )
        ).status,
        403,
    );
    assert.equal(
        (await attempt(ticket({ d: 5, exp: inAMinute() }))).status,
        403,
    );
    assert.equal((await attempt('not-a-ticket')).status, 403);
    assert.equal(state.sockets.length, 0);

    assert.equal(
        (await attempt(ticket({ d: 4, exp: inAMinute() }))).status,
        101,
    );
    assert.equal(state.sockets.length, 1);
});

test('tickets are checked the way the app signs them, padding or not', async () => {
    const part = base64url(JSON.stringify({ d: 4, exp: inAMinute() }));
    const signature = createHmac('sha256', SECRET)
        .update(part)
        .digest('base64');

    assert.equal(await verifyTicket(`${part}.${signature}`, SECRET, 4), true);
    assert.equal(await verifyTicket(`${part}.${signature}`, '', 4), false);
    assert.equal(await verifyTicket(`${part}x.${signature}`, SECRET, 4), false);
});

test('requests need the gateway secret', async () => {
    const { relay, state } = makeRelay();
    const desktop = await connect(relay, state);

    const response = await relay.fetch(
        new Request('https://relay.onedrop.io/__onedrop/devices/4/rpc/exec', {
            method: 'POST',
            body: '{}',
            headers: { 'X-OneDrop-Gateway-Secret': 'guess' },
        }),
    );

    assert.equal(response.status, 403);
    assert.equal(desktop.sent.length, 0);
});

test('with no computer connected, requests are answered offline', async () => {
    const { relay } = makeRelay();
    const response = await relay.fetch(
        relayed('/rpc/wake', { method: 'POST', body: '{}' }),
    );

    assert.equal(response.status, 503);
    assert.deepEqual(await response.json(), { error: 'offline' });
});

test('a request and its answer stream through the computer in chunks', async () => {
    const { relay, state } = makeRelay();
    const desktop = await connect(relay, state);
    const big = new Uint8Array(MAX_CHUNK + 100).fill(7);

    const answer = relay.fetch(
        relayed('/rpc/copy-in?id=onedrop-project-9-ab12cd&path=/workspace', {
            method: 'POST',
            duplex: 'half',
            headers: { 'Content-Type': 'application/x-tar' },
            body: new ReadableStream({
                start(controller) {
                    controller.enqueue(big);
                    controller.enqueue(new Uint8Array([1, 2, 3]));
                    controller.close();
                },
            }),
        }),
    );

    await until(() => messages(desktop).some((message) => message.t === 'end'));
    const [head, ...rest] = messages(desktop);

    assert.equal(head.t, 'req');
    assert.equal(head.method, 'POST');
    assert.equal(
        head.path,
        '/rpc/copy-in?id=onedrop-project-9-ab12cd&path=/workspace',
    );
    assert.equal(head.body, true);
    assert.ok(head.headers.some(([name]) => name === 'content-type'));
    // The computer never sees the gateway secret.
    assert.ok(
        !head.headers.some(([name]) => name === 'x-onedrop-gateway-secret'),
    );

    const chunks = rest.slice(0, -1);
    assert.ok(
        chunks.every((chunk) => chunk.id === head.id && chunk.kind === 0),
    );
    assert.ok(chunks.every((chunk) => chunk.payload.byteLength <= MAX_CHUNK));
    assert.equal(
        chunks.reduce((total, chunk) => total + chunk.payload.byteLength, 0),
        MAX_CHUNK + 103,
    );
    assert.deepEqual(rest.at(-1), { t: 'end', id: head.id });

    await relay.webSocketMessage(
        desktop,
        JSON.stringify({
            t: 'res',
            id: head.id,
            status: 201,
            headers: [['content-type', 'application/json']],
        }),
    );
    const response = await answer;
    assert.equal(response.status, 201);
    assert.equal(response.headers.get('Content-Type'), 'application/json');

    await relay.webSocketMessage(
        desktop,
        frame(head.id, 0, new TextEncoder().encode('{"ok"')).buffer,
    );
    await relay.webSocketMessage(
        desktop,
        frame(head.id, 0, new TextEncoder().encode(':true}')).buffer,
    );
    await relay.webSocketMessage(
        desktop,
        JSON.stringify({ t: 'end', id: head.id }),
    );

    assert.deepEqual(await response.json(), { ok: true });
    assert.equal(relay.streams.size, 0);
});

test('a WebSocket is relayed both ways, and closing either side closes the other', async () => {
    const { relay, state } = makeRelay();
    const desktop = await connect(relay, state);

    const answer = relay.fetch(
        relayed('/port/onedrop-project-9-ab12cd/7681/ws', {
            headers: {
                Upgrade: 'websocket',
                'Sec-WebSocket-Key': 'abc',
                'Sec-WebSocket-Protocol': 'tty',
            },
        }),
    );
    const [open] = await received(desktop, 1);

    assert.equal(open.t, 'ws');
    assert.equal(open.path, '/port/onedrop-project-9-ab12cd/7681/ws');
    assert.ok(
        open.headers.some(
            ([name, value]) =>
                name === 'sec-websocket-protocol' && value === 'tty',
        ),
    );
    assert.ok(!open.headers.some(([name]) => name === 'sec-websocket-key'));

    await relay.webSocketMessage(
        desktop,
        JSON.stringify({ t: 'ws-ok', id: open.id, protocol: 'tty' }),
    );
    const response = await answer;
    assert.equal(response.status, 101);
    assert.equal(response.headers['Sec-WebSocket-Protocol'], 'tty');

    // The relay's end of the browser's socket.
    const browser = relay.streams.get(open.id).client;

    await relay.webSocketMessage(
        desktop,
        frame(open.id, 1, new TextEncoder().encode('$ ')).buffer,
    );
    await relay.webSocketMessage(
        desktop,
        frame(open.id, 2, new Uint8Array([9, 9])).buffer,
    );
    assert.equal(browser.sent[0], '$ ');
    assert.deepEqual([...new Uint8Array(browser.sent[1])], [9, 9]);

    browser.emit('message', { data: 'ls\r' });
    browser.emit('message', { data: new Uint8Array([4]).buffer });
    const [text, binary] = (await received(desktop, 3)).slice(1);
    assert.deepEqual(
        [text.kind, new TextDecoder().decode(text.payload)],
        [1, 'ls\r'],
    );
    assert.deepEqual([binary.kind, [...binary.payload]], [2, [4]]);

    // The computer's side closes: so does the browser's, without echoing it back.
    await relay.webSocketMessage(
        desktop,
        JSON.stringify({
            t: 'ws-close',
            id: open.id,
            code: 1000,
            reason: 'exit',
        }),
    );
    assert.deepEqual(browser.closed, { code: 1000, reason: 'exit' });
    browser.emit('close', { code: 1000, reason: 'exit' });
    assert.equal(
        messages(desktop).filter((message) => message.t === 'ws-close').length,
        0,
    );

    // A second one, closed by the browser.
    const second = relay.fetch(
        relayed('/port/onedrop-project-9-ab12cd/7681/ws', {
            headers: { Upgrade: 'websocket' },
        }),
    );
    const again = (await received(desktop, 4)).at(-1);
    await relay.webSocketMessage(
        desktop,
        JSON.stringify({ t: 'ws-ok', id: again.id }),
    );
    await second;
    relay.streams
        .get(again.id)
        .client.emit('close', { code: 1001, reason: 'gone' });

    assert.deepEqual(messages(desktop).at(-1), {
        t: 'ws-close',
        id: again.id,
        code: 1001,
        reason: 'gone',
    });
    assert.equal(relay.streams.size, 0);
});

test("a WebSocket the computer refuses gets the computer's answer", async () => {
    const { relay, state } = makeRelay();
    const desktop = await connect(relay, state);

    const answer = relay.fetch(
        relayed('/port/onedrop-project-9-ab12cd/9999/', {
            headers: { Upgrade: 'websocket' },
        }),
    );
    const [open] = await received(desktop, 1);
    await relay.webSocketMessage(
        desktop,
        JSON.stringify({ t: 'res', id: open.id, status: 502, headers: [] }),
    );
    await relay.webSocketMessage(
        desktop,
        frame(open.id, 0, new TextEncoder().encode('refused')).buffer,
    );
    await relay.webSocketMessage(
        desktop,
        JSON.stringify({ t: 'end', id: open.id }),
    );

    const response = await answer;
    assert.equal(response.status, 502);
    assert.equal(await response.text(), 'refused');
});

test('when the computer disconnects mid-request, everything in flight is cut off', async () => {
    const { relay, state } = makeRelay();
    const desktop = await connect(relay, state);

    const waiting = relay.fetch(
        relayed('/rpc/exec', { method: 'POST', body: '{}' }),
    );
    const streaming = relay.fetch(
        relayed('/rpc/copy-out?id=x&path=/', { method: 'POST' }),
    );
    const socket = relay.fetch(
        relayed('/port/x/7681/ws', { headers: { Upgrade: 'websocket' } }),
    );

    // The three requests' openings, however their bodies and ends interleave with them.
    const opened = () =>
        messages(desktop).filter((message) => message.t && message.t !== 'end');
    await until(() => opened().length >= 3);
    const [first, second, third] = opened();
    await relay.webSocketMessage(
        desktop,
        JSON.stringify({ t: 'res', id: second.id, status: 200, headers: [] }),
    );
    await relay.webSocketMessage(
        desktop,
        frame(second.id, 0, new Uint8Array([1])).buffer,
    );
    await relay.webSocketMessage(
        desktop,
        JSON.stringify({ t: 'ws-ok', id: third.id }),
    );
    const body = (await streaming).body.getReader();
    assert.deepEqual([...(await body.read()).value], [1]);
    await socket;
    const browser = relay.streams.get(third.id).client;

    desktop.readyState = 3;
    await relay.webSocketClose(desktop, 1006, '', false);

    assert.equal((await waiting).status, 502);
    assert.equal(first.t, 'req');
    await assert.rejects(body.read());
    assert.equal(browser.closed.code, 1011);
    assert.equal(relay.streams.size, 0);

    // And with nobody connected now, it's offline.
    assert.equal(
        (await relay.fetch(relayed('/rpc/wake', { method: 'POST' }))).status,
        503,
    );
});

test("the computer's abort gives up on a request", async () => {
    const { relay, state } = makeRelay();
    const desktop = await connect(relay, state);

    const answer = relay.fetch(
        relayed('/rpc/exec', { method: 'POST', body: '{}' }),
    );
    const [request] = await received(desktop, 1);
    await relay.webSocketMessage(
        desktop,
        JSON.stringify({
            t: 'abort',
            id: request.id,
            reason: 'no such container',
        }),
    );

    const response = await answer;
    assert.equal(response.status, 502);
    assert.equal((await response.json()).reason, 'no such container');
});

test('a new connection replaces the old one, and only it is listened to', async () => {
    const { relay, state } = makeRelay();
    const old = await connect(relay, state);
    const waiting = relay.fetch(
        relayed('/rpc/exec', { method: 'POST', body: '{}' }),
    );
    const [request] = await received(old, 1);

    const current = await connect(relay, state);

    assert.equal(old.closed.code, 1012);
    assert.equal((await waiting).status, 502);

    // The old socket's late messages are ignored; the new one carries requests.
    const answer = relay.fetch(
        relayed('/rpc/wake', { method: 'POST', body: '{}' }),
    );
    const [wake] = await received(current, 1);
    await relay.webSocketMessage(
        old,
        JSON.stringify({ t: 'res', id: request.id, status: 200, headers: [] }),
    );
    await relay.webSocketMessage(
        old,
        JSON.stringify({ t: 'res', id: wake.id, status: 500, headers: [] }),
    );
    await relay.webSocketMessage(
        current,
        JSON.stringify({ t: 'res', id: wake.id, status: 200, headers: [] }),
    );
    await relay.webSocketMessage(
        current,
        JSON.stringify({ t: 'end', id: wake.id }),
    );
    assert.equal((await answer).status, 200);

    // The old socket's close doesn't touch the new connection's streams.
    const pending = relay.fetch(
        relayed('/rpc/exec', { method: 'POST', body: '{}' }),
    );
    const last = (await received(current, 4))
        .filter((message) => message.t === 'req')
        .at(-1);
    await relay.webSocketClose(old, 1012, '', true);
    assert.equal(relay.streams.size, 1);
    await relay.webSocketMessage(
        current,
        JSON.stringify({ t: 'res', id: last.id, status: 200, headers: [] }),
    );
    assert.equal((await pending).status, 200);
});

test('a ping is answered with a pong', async () => {
    const { relay, state } = makeRelay();
    const desktop = await connect(relay, state);

    await relay.webSocketMessage(desktop, JSON.stringify({ t: 'ping' }));

    assert.deepEqual(messages(desktop), [{ t: 'pong' }]);
});

/**
 * Run the Worker with a relay namespace whose objects are recorded (or real relays, when given).
 */
async function runWorker(request, authorize, relays = {}) {
    const sent = [];
    const toRelay = [];
    const store = new Map();
    const env = {
        APP_URL: 'https://onedrop.io',
        GATEWAY_DOMAIN: 'onedrop.io',
        GATEWAY_SECRET: SECRET,
        DEVICE_RELAY: {
            idFromName: (name) => ({ name }),
            get: ({ name }) => ({
                fetch: async (input, init) => {
                    const call = new Request(input, init);
                    toRelay.push({ name, request: call });

                    return relays[name]
                        ? relays[name].fetch(call)
                        : new Response(`from device ${name}`);
                },
            }),
        },
    };

    globalThis.caches = {
        default: {
            match: async (key) => store.get(key.url)?.clone(),
            put: async (key, response) => store.set(key.url, response),
        },
    };
    globalThis.fetch = async (input, init = {}) => {
        const target = String(input instanceof Request ? input.url : input);
        sent.push({
            url: target,
            headers: new Headers(init.headers ?? input.headers),
        });

        if (target.startsWith('https://onedrop.io/sandbox-gateway/authorize')) {
            return authorize();
        }

        return new Response(`from ${target}`);
    };

    const response = await worker.fetch(request, env, { waitUntil: () => {} });

    return { response, sent, toRelay };
}

const onDevice = () =>
    new Response(null, {
        status: 200,
        headers: {
            'X-OneDrop-Upstream': 'device:4/onedrop-project-9-ab12cd:8000',
        },
    });

test("a device sandbox's preview goes to its computer's relay, with the secret", async () => {
    const { response, sent, toRelay } = await runWorker(
        new Request('https://preview-12.onedrop.io/invoices?page=2', {
            headers: { Cookie: 'onedrop_gateway=abc; theme=dark' },
        }),
        onDevice,
    );

    assert.equal(await response.text(), 'from device 4');
    // Only the app was asked; nothing went to a provider.
    assert.equal(sent.length, 1);
    assert.equal(toRelay[0].name, '4');

    const { request } = toRelay[0];
    assert.equal(
        request.url,
        'https://relay.onedrop.io/__onedrop/devices/4/port/onedrop-project-9-ab12cd/8000/invoices?page=2',
    );
    assert.equal(request.headers.get('X-OneDrop-Gateway-Secret'), SECRET);
    assert.equal(
        request.headers.get('X-OneDrop-Host'),
        'preview-12.onedrop.io',
    );
    assert.equal(request.headers.get('Cookie'), 'theme=dark');
});

test("a device sandbox's Shell WebSocket reaches the computer through the relay", async () => {
    const { relay, state } = makeRelay();
    const desktop = await connect(relay, state);

    const running = runWorker(
        new Request('https://shell-12.onedrop.io/ws', {
            headers: { Upgrade: 'websocket', Cookie: 'onedrop_gateway=abc' },
        }),
        () =>
            new Response(null, {
                status: 200,
                headers: {
                    'X-OneDrop-Upstream':
                        'device:4/onedrop-project-9-ab12cd:7681',
                },
            }),
        { 4: relay },
    );

    await until(() => desktop.sent.length > 0);
    const [open] = messages(desktop);
    assert.equal(open.t, 'ws');
    assert.equal(open.path, '/port/onedrop-project-9-ab12cd/7681/ws');
    assert.ok(
        open.headers.some(
            ([name, value]) =>
                name === 'x-onedrop-host' && value === 'shell-12.onedrop.io',
        ),
    );
    assert.ok(
        !open.headers.some(([name]) => name === 'x-onedrop-gateway-secret'),
    );

    await relay.webSocketMessage(
        desktop,
        JSON.stringify({ t: 'ws-ok', id: open.id }),
    );
    assert.equal((await running).response.status, 101);
});

test('relay.<domain> goes to the computer in the path, and needs the secret', async () => {
    const { relay } = makeRelay();

    const { response, sent, toRelay } = await runWorker(
        new Request('https://relay.onedrop.io/__onedrop/devices/4/rpc/wake', {
            method: 'POST',
            body: '{}',
        }),
        () => {
            throw new Error('asked the app');
        },
        { 4: relay },
    );

    assert.equal(sent.length, 0);
    assert.equal(toRelay[0].name, '4');
    assert.equal(response.status, 403);

    const other = await runWorker(
        new Request('https://relay.onedrop.io/'),
        () => {
            throw new Error('asked the app');
        },
    );
    assert.equal(other.response.status, 404);
    assert.equal(other.toRelay.length, 0);
});
