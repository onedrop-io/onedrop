// The device relay (DESK-010): one Durable Object per computer (a desktop app sign-in), which the desktop app keeps
// a WebSocket open to, so the hosted app and the gateway can reach sandboxes running in that computer's Docker.
// The contract is in docs/development/desktop-link.mdx ("The device relay"):
//
//   /__onedrop/devices/<id>/connect?ticket=…  → the desktop app's WebSocket, with a ticket the app signs with
//                                               GATEWAY_SECRET. A new one replaces the old.
//   /__onedrop/devices/<id>/<path>            → with X-OneDrop-Gateway-Secret: a request (or WebSocket) relayed to
//                                               the computer over that socket, as a stream with an id.
//
// Text messages are JSON; binary ones are [u32 big-endian stream id][u8 kind][payload]. The desktop app's socket
// uses the hibernation API, so an idle computer costs nothing; the browser's relayed WebSockets keep it awake.

/** Binary message kinds. */
export const BODY = 0;
export const WS_TEXT = 1;
export const WS_BINARY = 2;

/** Body chunks are at most this big. */
export const MAX_CHUNK = 512 * 1024;

const DEVICE_PATH = /^\/__onedrop\/devices\/(\d+)(\/.*)$/;
const DEVICE_TAG = 'device';
const OPEN = 1;

/** Headers that belong to the browser's own connection, not the one the computer makes. */
const HOP_BY_HOP = new Set([
    'connection',
    'upgrade',
    'keep-alive',
    'transfer-encoding',
    'sec-websocket-key',
    'sec-websocket-version',
    'sec-websocket-extensions',
    'x-onedrop-gateway-secret',
]);

/** Statuses whose responses can't have a body. */
const NO_BODY = new Set([101, 204, 205, 304]);

export class DeviceRelay {
    constructor(state, env) {
        this.state = state;
        this.env = env;

        /** Streams in flight, by id: the requests and WebSockets relayed to the computer. */
        this.streams = new Map();
        this.nextId = 1;

        // The desktop app's keep-alive is answered without waking the object.
        if (typeof WebSocketRequestResponsePair !== 'undefined') {
            state.setWebSocketAutoResponse?.(
                new WebSocketRequestResponsePair(
                    JSON.stringify({ t: 'ping' }),
                    JSON.stringify({ t: 'pong' }),
                ),
            );
        }
    }

    async fetch(request) {
        const url = new URL(request.url);
        const match = DEVICE_PATH.exec(url.pathname);

        if (!match) {
            return json(404, { error: 'not_found' });
        }

        const [, device, path] = match;

        if (path === '/connect') {
            return this.connect(request, device, url);
        }

        if (
            !(await sameSecret(
                request.headers.get('X-OneDrop-Gateway-Secret'),
                this.env.GATEWAY_SECRET,
            ))
        ) {
            return json(403, { error: 'forbidden' });
        }

        const socket = this.device();

        if (!socket) {
            return json(503, { error: 'offline' });
        }

        return isWebSocket(request)
            ? this.relayWebSocket(socket, request, path + url.search)
            : this.relayRequest(socket, request, path + url.search);
    }

    /**
     * The desktop app connects, with a ticket for this computer. It replaces any connection already open.
     */
    async connect(request, device, url) {
        if (!isWebSocket(request)) {
            return json(426, { error: 'websocket_required' });
        }

        if (
            !(await verifyTicket(
                url.searchParams.get('ticket') ?? '',
                this.env.GATEWAY_SECRET,
                device,
            ))
        ) {
            return json(403, { error: 'invalid_ticket' });
        }

        for (const old of this.state.getWebSockets(DEVICE_TAG)) {
            this.disconnected(old, 'Replaced by a new connection');
            closeQuietly(old, 1012, 'Replaced by a new connection');
        }

        const [client, server] = Object.values(new WebSocketPair());
        this.state.acceptWebSocket(server, [DEVICE_TAG]);
        server.serializeAttachment({ connection: crypto.randomUUID() });

        return this.switchingProtocols(client);
    }

    /**
     * The answer that hands the browser (or the desktop app) its end of a WebSocket.
     */
    switchingProtocols(webSocket, headers = {}) {
        return new Response(null, { status: 101, webSocket, headers });
    }

    /**
     * The desktop app's open connection, if there is one.
     */
    device() {
        return (
            this.state
                .getWebSockets(DEVICE_TAG)
                .filter((socket) => socket.readyState === OPEN)
                .at(-1) ?? null
        );
    }

    /**
     * An HTTP request: its head, then its body in chunks; the answer streams back the same way.
     */
    relayRequest(socket, request, path) {
        const id = this.newId();
        const body =
            request.body !== null && !['GET', 'HEAD'].includes(request.method);
        const stream = this.open(id, socket, 'http');

        this.send(stream, {
            t: 'req',
            id,
            method: request.method,
            path,
            headers: relayedHeaders(request.headers),
            body,
        });

        if (body) {
            this.sendBody(stream, request.body);
        }

        return stream.answer;
    }

    /**
     * A WebSocket: the computer opens its own, and messages pass both ways as kinds 1 and 2.
     */
    relayWebSocket(socket, request, path) {
        const id = this.newId();
        const stream = this.open(id, socket, 'ws');

        this.send(stream, {
            t: 'ws',
            id,
            path,
            headers: relayedHeaders(request.headers),
        });

        return stream.answer;
    }

    /**
     * A message from the desktop app.
     */
    async webSocketMessage(socket, message) {
        if (typeof message !== 'string') {
            this.received(socket, message);

            return;
        }

        let data;

        try {
            data = JSON.parse(message);
        } catch {
            return;
        }

        if (data.t === 'ping') {
            socket.send(JSON.stringify({ t: 'pong' }));

            return;
        }

        const stream = this.streams.get(data.id);

        if (!stream || stream.connection !== connectionOf(socket)) {
            return;
        }

        switch (data.t) {
            case 'res':
                this.answered(stream, data);
                break;
            case 'end':
                this.finish(stream);
                break;
            case 'abort':
                this.fail(stream, data.reason || 'Aborted by the computer');
                break;
            case 'ws-ok':
                this.webSocketOpened(stream, data);
                break;
            case 'ws-close':
                this.forget(stream);
                closeQuietly(stream.client, data.code, data.reason ?? '');
                break;
        }
    }

    async webSocketClose(socket) {
        this.disconnected(socket, 'The computer disconnected');
    }

    async webSocketError(socket) {
        this.disconnected(socket, 'The computer disconnected');
    }

    /**
     * A body chunk or a WebSocket message for one of the streams.
     */
    received(socket, message) {
        const bytes = ArrayBuffer.isView(message)
            ? new Uint8Array(
                  message.buffer,
                  message.byteOffset,
                  message.byteLength,
              )
            : new Uint8Array(message);

        if (bytes.byteLength < 5) {
            return;
        }

        const { id, kind, payload } = readFrame(bytes);
        const stream = this.streams.get(id);

        if (!stream || stream.connection !== connectionOf(socket)) {
            return;
        }

        if (kind === BODY) {
            // Copied: the payload is a view into a buffer the runtime may reuse.
            stream.controller?.enqueue(payload.slice());
        } else if (kind === WS_TEXT) {
            stream.client?.send(new TextDecoder().decode(payload));
        } else if (kind === WS_BINARY) {
            stream.client?.send(payload.slice());
        }
    }

    /**
     * The response's head: the request's answer, with its body to follow. A WebSocket's refusal comes this way too.
     */
    answered(stream, { status, headers = [] }) {
        if (stream.settled) {
            return;
        }

        const head = new Headers();

        for (const [name, value] of headers) {
            head.append(name, value);
        }

        let body = null;

        if (!NO_BODY.has(status)) {
            body = new ReadableStream({
                start: (controller) => {
                    stream.controller = controller;
                },
                cancel: () => {
                    // The browser (or the app) went away: the computer can stop sending.
                    if (this.forget(stream)) {
                        this.send(stream, {
                            t: 'abort',
                            id: stream.id,
                            reason: 'The client went away',
                        });
                    }
                },
            });
        }

        stream.kind = 'http';
        this.settle(
            stream,
            new Response(body, {
                status,
                headers: head,
                // A compressed body arrives as is: it's sent on without being compressed again.
                encodeBody: head.has('Content-Encoding')
                    ? 'manual'
                    : 'automatic',
            }),
        );
    }

    /**
     * The computer opened the WebSocket: the browser gets its end.
     */
    webSocketOpened(stream, { protocol }) {
        if (stream.settled || stream.kind !== 'ws') {
            return;
        }

        const [client, server] = Object.values(new WebSocketPair());
        server.accept();
        stream.client = server;

        server.addEventListener('message', ({ data }) => {
            if (this.streams.get(stream.id) !== stream) {
                return;
            }

            if (typeof data === 'string') {
                this.sendFrame(stream, WS_TEXT, new TextEncoder().encode(data));
            } else {
                this.sendFrame(stream, WS_BINARY, new Uint8Array(data));
            }
        });

        const closed = ({ code, reason }) => {
            if (this.forget(stream)) {
                this.send(stream, {
                    t: 'ws-close',
                    id: stream.id,
                    code: code ?? 1000,
                    reason: reason ?? '',
                });
            }
        };

        server.addEventListener('close', closed);
        server.addEventListener('error', () =>
            closed({ code: 1011, reason: 'WebSocket error' }),
        );

        this.settle(
            stream,
            this.switchingProtocols(
                client,
                protocol ? { 'Sec-WebSocket-Protocol': protocol } : {},
            ),
        );
    }

    /**
     * The request's body, in chunks, then its end.
     */
    async sendBody(stream, body) {
        const reader = body.getReader();
        stream.reader = reader;

        try {
            for (;;) {
                const { done, value } = await reader.read();

                if (done) {
                    break;
                }

                if (this.streams.get(stream.id) !== stream) {
                    return;
                }

                for (let at = 0; at < value.byteLength; at += MAX_CHUNK) {
                    this.sendFrame(
                        stream,
                        BODY,
                        value.subarray(at, at + MAX_CHUNK),
                    );
                }
            }

            if (this.streams.get(stream.id) === stream) {
                this.send(stream, { t: 'end', id: stream.id });
            }
        } catch {
            if (this.streams.get(stream.id) === stream) {
                this.send(stream, {
                    t: 'abort',
                    id: stream.id,
                    reason: 'The request body failed',
                });
                this.fail(stream, 'The request body failed');
            }
        }
    }

    /**
     * A new stream on the computer's connection, with the promise of its answer.
     */
    open(id, socket, kind) {
        const stream = {
            id,
            kind,
            socket,
            connection: connectionOf(socket),
            settled: false,
            controller: null,
            client: null,
            reader: null,
        };
        stream.answer = new Promise((resolve) => {
            stream.resolve = resolve;
        });
        this.streams.set(id, stream);

        return stream;
    }

    settle(stream, response) {
        stream.settled = true;
        stream.resolve(response);
    }

    /**
     * The response's body is done.
     */
    finish(stream) {
        this.forget(stream);

        try {
            stream.controller?.close();
        } catch {
            // Already closed or cancelled.
        }

        if (!stream.settled) {
            this.settle(stream, json(502, { error: 'no_response' }));
        }
    }

    /**
     * Give up on a stream: a request not yet answered gets a 502, a body under way errors, a WebSocket closes.
     */
    fail(stream, reason) {
        this.forget(stream);

        if (!stream.settled) {
            this.settle(stream, json(502, { error: 'disconnected', reason }));
        }

        try {
            stream.controller?.error(new Error(reason));
        } catch {
            // Already closed or cancelled.
        }

        stream.reader?.cancel(reason).catch(() => {});
        closeQuietly(stream.client, 1011, reason);
    }

    /**
     * Stop tracking a stream; false when it was already gone.
     */
    forget(stream) {
        if (this.streams.get(stream.id) !== stream) {
            return false;
        }

        this.streams.delete(stream.id);

        return true;
    }

    /**
     * A connection to the computer closed (or was replaced): everything on it is cut off.
     */
    disconnected(socket, reason) {
        const connection = connectionOf(socket);

        for (const stream of [...this.streams.values()]) {
            if (stream.connection === connection) {
                this.fail(stream, reason);
            }
        }
    }

    newId() {
        do {
            const id = this.nextId;
            this.nextId = id >= 0xffffffff ? 1 : id + 1;

            if (!this.streams.has(id)) {
                return id;
            }
        } while (true);
    }

    send(stream, message) {
        try {
            stream.socket.send(JSON.stringify(message));
        } catch {
            this.disconnected(stream.socket, 'The computer disconnected');
        }
    }

    sendFrame(stream, kind, payload) {
        try {
            stream.socket.send(frame(stream.id, kind, payload));
        } catch {
            this.disconnected(stream.socket, 'The computer disconnected');
        }
    }
}

/**
 * A binary message: [u32 big-endian stream id][u8 kind][payload].
 */
export function frame(id, kind, payload) {
    const out = new Uint8Array(5 + payload.byteLength);
    const view = new DataView(out.buffer);
    view.setUint32(0, id);
    view.setUint8(4, kind);
    out.set(payload, 5);

    return out;
}

export function readFrame(bytes) {
    const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);

    return {
        id: view.getUint32(0),
        kind: view.getUint8(4),
        payload: bytes.subarray(5),
    };
}

/**
 * A desktop app's ticket: base64url(json payload) "." base64url(HMAC-SHA256(secret, the first part)), for this
 * computer, not yet expired.
 */
export async function verifyTicket(
    ticket,
    secret,
    device,
    now = Date.now() / 1000,
) {
    const parts = ticket.split('.');

    if (!secret || parts.length !== 2) {
        return false;
    }

    try {
        const key = await crypto.subtle.importKey(
            'raw',
            new TextEncoder().encode(secret),
            { name: 'HMAC', hash: 'SHA-256' },
            false,
            ['verify'],
        );
        const signed = await crypto.subtle.verify(
            'HMAC',
            key,
            fromBase64Url(parts[1]),
            new TextEncoder().encode(parts[0]),
        );

        if (!signed) {
            return false;
        }

        const payload = JSON.parse(
            new TextDecoder().decode(fromBase64Url(parts[0])),
        );

        return (
            String(payload.d) === String(device) &&
            typeof payload.exp === 'number' &&
            payload.exp > now
        );
    } catch {
        return false;
    }
}

function fromBase64Url(text) {
    const base64 = text.replace(/-/g, '+').replace(/_/g, '/');
    const binary = atob(base64 + '='.repeat((4 - (base64.length % 4)) % 4));

    return Uint8Array.from(binary, (char) => char.charCodeAt(0));
}

/**
 * The shared secret, compared without leaking how much of it matched.
 */
async function sameSecret(given, secret) {
    if (!given || !secret) {
        return false;
    }

    const [a, b] = await Promise.all(
        [given, secret].map(
            async (value) =>
                new Uint8Array(
                    await crypto.subtle.digest(
                        'SHA-256',
                        new TextEncoder().encode(value),
                    ),
                ),
        ),
    );
    let difference = 0;

    for (let i = 0; i < a.length; i++) {
        difference |= a[i] ^ b[i];
    }

    return difference === 0;
}

/**
 * The request's headers for the computer: never the gateway secret, nor the browser's own connection details.
 */
function relayedHeaders(headers) {
    return [...headers].filter(([name]) => !HOP_BY_HOP.has(name.toLowerCase()));
}

function isWebSocket(request) {
    return request.headers.get('Upgrade')?.toLowerCase() === 'websocket';
}

function connectionOf(socket) {
    return socket.deserializeAttachment?.()?.connection ?? null;
}

/**
 * Close a WebSocket with a code it may send (1000 for the reserved ones) and a reason that fits (123 bytes).
 */
function closeQuietly(socket, code, reason) {
    if (!socket) {
        return;
    }

    const sendable =
        code === 1000 ||
        (code >= 1001 && code <= 1014 && ![1004, 1005, 1006].includes(code)) ||
        (code >= 3000 && code <= 4999);
    let text = String(reason ?? '');

    while (new TextEncoder().encode(text).byteLength > 123) {
        text = text.slice(0, -1);
    }

    try {
        socket.close(sendable ? code : 1000, text);
    } catch {
        // Already closed.
    }
}

function json(status, body) {
    return new Response(JSON.stringify(body), {
        status,
        headers: {
            'Content-Type': 'application/json',
            'Cache-Control': 'no-store',
        },
    });
}
