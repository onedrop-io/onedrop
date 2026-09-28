#!/usr/bin/env node
// Front door for the preview and published URLs. Rewrites Host/Origin to localhost so any
// dev server (Vite, Next, Rails, Django, ...) accepts requests for hostnames it
// has never heard of (*.ts.net etc.), without per-framework configuration.
// The original host is passed on as X-Forwarded-Host. WebSockets (hot reload) pass through.
// Apps that ignore X-Forwarded-Host write their own address as localhost into pages (asset URLs,
// redirects); text responses get those links pointed back at the address the visitor used.
import {
    appendFile,
    mkdirSync,
    readFileSync,
    renameSync,
    statSync,
} from 'node:fs';
import http from 'node:http';
import net from 'node:net';

const appPort = Number(process.env.PORT || 8000);
const listenPort = Number(process.env.PROXY_PORT || 8081);
const appHost = `localhost:${appPort}`;

/** Point a same-site URL header (Origin/Referer) at localhost, leaving other sites alone. */
function localize(value, originalHost) {
    try {
        const url = new URL(value);

        if (url.host !== originalHost) {
            return value;
        }

        url.protocol = 'http:';
        url.host = appHost;

        // Origin headers have no path; keep that shape.
        return url.pathname === '/' && !value.endsWith('/')
            ? url.origin
            : url.toString();
    } catch {
        return value;
    }
}

/** The scheme the visitor used: from the proxy in front (Tailscale, Blaxel, Caddy), else http. */
function visitorProto(req) {
    const host = String(req.headers.host ?? '');

    return (
        String(req.headers['x-forwarded-proto'] ?? '').split(',')[0].trim() ||
        (host.endsWith('.ts.net') ? 'https' : 'http')
    );
}

/** Whether the visitor used an address other than the app's own (a preview, published or published-port URL). */
function isForeignHost(host) {
    return host !== '' && host !== appHost && host !== `127.0.0.1:${appPort}`;
}

function rewrite(req) {
    const headers = { ...req.headers };
    const originalHost = req.headers.host ?? '';

    headers['x-forwarded-host'] = originalHost;
    headers['x-forwarded-proto'] = visitorProto(req);
    headers.host = appHost;

    // Uncompressed answers, so links to localhost in them can be pointed at the visitor's address.
    if (isForeignHost(originalHost)) {
        headers['accept-encoding'] = 'identity';
    }

    // Dev servers (Next.js, Vite, ...) refuse their own dev resources and HMR
    // sockets when the page's Origin isn't localhost.
    if (headers.origin) {
        headers.origin = localize(headers.origin, originalHost);
    }

    if (headers.referer) {
        headers.referer = localize(headers.referer, originalHost);
    }

    return headers;
}

// Text answers whose localhost links are rewritten. Event streams stay streamed.
const REWRITABLE =
    /^(text\/(html|css|javascript|plain|xml)|application\/(javascript|json|xml|xhtml\+xml|manifest\+json)|image\/svg\+xml)/i;
const MAX_REWRITE_BYTES = 20_000_000;

/** Every way a page may spell the app's localhost address, plain and JSON-escaped. */
function localAddresses() {
    return ['localhost', '127.0.0.1', '0.0.0.0'].flatMap((name) => [
        [`http://${name}:${appPort}`, 'http'],
        [`http:\\/\\/${name}:${appPort}`, 'http-escaped'],
        [`ws://${name}:${appPort}`, 'ws'],
        [`ws:\\/\\/${name}:${appPort}`, 'ws-escaped'],
    ]);
}

const LOCAL_ADDRESSES = localAddresses();

/** Point links at the app's localhost address at the address the visitor used instead. */
function toVisitor(text, req) {
    const host = String(req.headers.host ?? '');
    const proto = visitorProto(req);
    const secure = proto === 'https';
    const targets = {
        http: `${proto}://${host}`,
        'http-escaped': `${proto}:\\/\\/${host}`,
        ws: `${secure ? 'wss' : 'ws'}://${host}`,
        'ws-escaped': `${secure ? 'wss' : 'ws'}:\\/\\/${host}`,
    };

    return LOCAL_ADDRESSES.reduce(
        (result, [local, kind]) => result.split(local).join(targets[kind]),
        text,
    );
}

/** Send the app's answer on, with localhost links rewritten for visitors from other addresses. */
function relay(req, res, response) {
    const headers = { ...response.headers };
    const host = String(req.headers.host ?? '');
    const foreign = isForeignHost(host);

    if (foreign && headers.location) {
        headers.location = toVisitor(String(headers.location), req);
    }

    const rewritable =
        foreign &&
        REWRITABLE.test(String(headers['content-type'] ?? '')) &&
        !headers['content-encoding'] &&
        Number(headers['content-length'] ?? 0) <= MAX_REWRITE_BYTES;

    if (!rewritable) {
        res.writeHead(response.statusCode ?? 502, headers);
        response.pipe(res);

        return;
    }

    const chunks = [];
    let size = 0;

    response.on('data', (chunk) => {
        size += chunk.length;
        chunks.push(chunk);
    });
    response.on('end', () => {
        const body =
            size > MAX_REWRITE_BYTES
                ? Buffer.concat(chunks)
                : Buffer.from(toVisitor(Buffer.concat(chunks).toString('utf8'), req), 'utf8');

        delete headers['transfer-encoding'];
        headers['content-length'] = String(body.length);
        res.writeHead(response.statusCode ?? 502, headers);
        res.end(body);
    });
    response.on('error', () => res.destroy());
}

const starting = `<!doctype html><meta charset="utf-8"><meta http-equiv="refresh" content="3">
<title>Starting…</title><body style="font-family:system-ui;display:grid;place-items:center;min-height:90vh;color:#666">
<p>The app is starting. This page refreshes by itself.</p></body>`;

// Monitoring: one JSON line per request, and a CPU/memory sample every minute. Kept in the
// workspace so they survive sandbox recreation; each file is rotated once it gets large.
const MONITOR_DIR = '/workspace/.zap';
const ACCESS_LOG = `${MONITOR_DIR}/access.log`;
const METRICS_LOG = `${MONITOR_DIR}/metrics.log`;
const EVENTS_LOG = `${MONITOR_DIR}/events.log`;
const MAX_LOG_BYTES = 5_000_000;

function record(file, entry) {
    try {
        mkdirSync(MONITOR_DIR, { recursive: true });

        if (statSync(file, { throwIfNoEntry: false })?.size > MAX_LOG_BYTES) {
            renameSync(file, `${file}.1`);
        }

        appendFile(file, `${JSON.stringify(entry)}\n`, () => {});
    } catch {
        // Monitoring must never break the app.
    }
}

/** Dev-server plumbing (Vite modules, HMR) isn't traffic worth counting. */
function isInternal(url) {
    return /^\/(@|node_modules\/|__vite|src\/)/.test(url ?? '');
}

/** Preview traffic comes from the workspace; anything else reached the published address. */
function isPreview(host) {
    return (
        /^preview-\d+\./.test(host) ||
        /\.(preview\.bl\.run|runtimehost\.com)$/.test(host) ||
        /^(127\.0\.0\.1|localhost)(:\d+)?$/.test(host)
    );
}

function logRequest(req, status, startedAt) {
    if (isInternal(req.url)) {
        return;
    }

    const host = String(req.headers.host ?? '');

    record(ACCESS_LOG, {
        t: Date.now(),
        s: status,
        d: Math.round(performance.now() - startedAt),
        ip: clientIp(req),
        pub: !isPreview(host),
        // Growth analytics: which page, which site sent them, browser/device, and country.
        m: req.method,
        p: String(req.url ?? '/')
            .split('?')[0]
            .slice(0, 200),
        r: referrerHost(req.headers.referer, host),
        ua: String(req.headers['user-agent'] ?? '').slice(0, 300) || undefined,
        c: country(req.headers),
    });
}

/** The visitor's address: the first X-Forwarded-For hop (Tailscale, Caddy) or the socket's. */
function clientIp(req) {
    const forwarded = String(req.headers['x-forwarded-for'] ?? '')
        .split(',')[0]
        .trim();

    return forwarded || req.socket.remoteAddress;
}

/** The referring site's host, when it's another site (not the app itself). */
function referrerHost(referer, host) {
    try {
        const url = new URL(referer);

        return url.host === host ? undefined : url.host;
    } catch {
        return undefined;
    }
}

/** Two-letter country code, when a CDN in front of the app (Cloudflare, CloudFront, Vercel...) adds one. */
function country(headers) {
    const code = String(
        headers['cf-ipcountry'] ??
            headers['cloudfront-viewer-country'] ??
            headers['x-vercel-ip-country'] ??
            headers['x-country-code'] ??
            '',
    ).toUpperCase();

    return /^[A-Z]{2}$/.test(code) && code !== 'XX' ? code : undefined;
}

// Custom analytics events (docker/sandbox/guides/analytics.md): the app POSTs
// {"name": "signed_up", "props": {"plan": "pro"}} to its own /__zap/event, and it's logged here,
// never passed on to the app. Names and property keys are snake_case; values are short strings,
// numbers or booleans. Anything else is dropped.
const EVENT_PATH = '/__zap/event';
const EVENT_NAME = /^[a-z][a-z0-9_]{0,63}$/;
const MAX_EVENT_BYTES = 4096;
const MAX_EVENT_PROPS = 10;

/** The event to log, or null when it doesn't follow the rules. */
function parseEvent(body) {
    let event;

    try {
        event = JSON.parse(body);
    } catch {
        return null;
    }

    if (!event || typeof event !== 'object' || !EVENT_NAME.test(event.name)) {
        return null;
    }

    const given =
        event.props && typeof event.props === 'object' ? event.props : {};
    const props = {};

    for (const [key, value] of Object.entries(given).slice(
        0,
        MAX_EVENT_PROPS,
    )) {
        if (!EVENT_NAME.test(key)) {
            continue;
        }

        if (typeof value === 'string' && value !== '') {
            props[key] = value.slice(0, 100);
        } else if (
            typeof value === 'boolean' ||
            (typeof value === 'number' && Number.isFinite(value))
        ) {
            props[key] = value;
        }
    }

    return { name: event.name, props };
}

function recordEvent(req, res) {
    let body = '';
    let tooLarge = false;

    req.setEncoding('utf8');
    req.on('data', (chunk) => {
        tooLarge ||= body.length + chunk.length > MAX_EVENT_BYTES;
        body = tooLarge ? '' : body + chunk;
    });
    req.on('end', () => {
        const event = tooLarge ? null : parseEvent(body);
        const host = String(req.headers.host ?? '');

        if (event) {
            record(EVENTS_LOG, {
                t: Date.now(),
                n: event.name,
                props: Object.keys(event.props).length
                    ? event.props
                    : undefined,
                ip: clientIp(req),
                pub: !isPreview(host),
            });
        }

        res.writeHead(event ? 204 : 400, { 'cache-control': 'no-store' });
        res.end();
    });
    req.on('error', () => res.destroy());
}

function readCgroup(name) {
    try {
        return readFileSync(`/sys/fs/cgroup/${name}`, 'utf8').trim();
    } catch {
        return null;
    }
}

let lastCpu = null;

function sampleResources() {
    const usage = Number(
        readCgroup('cpu.stat')?.match(/usage_usec (\d+)/)?.[1],
    );
    const [quota, period] = (readCgroup('cpu.max') ?? 'max 100000').split(' ');
    const cores =
        quota === 'max'
            ? navigator.hardwareConcurrency || 1
            : Number(quota) / Number(period);
    const memory = Number(readCgroup('memory.current'));
    const memoryMax = Number(readCgroup('memory.max')) || null;
    const now = Date.now();

    if (Number.isFinite(usage) && lastCpu) {
        const cpu =
            (usage - lastCpu.usage) / ((now - lastCpu.at) * 1000 * cores);

        record(METRICS_LOG, {
            t: now,
            cpu: Math.min(1, Math.max(0, +cpu.toFixed(4))),
            mem: Number.isFinite(memory) ? memory : null,
            memMax: memoryMax,
        });
    }

    if (Number.isFinite(usage)) {
        lastCpu = { usage, at: now };
    }
}

sampleResources();
setInterval(
    sampleResources,
    Number(process.env.APP_METRICS_INTERVAL || 60_000),
).unref();

const server = http.createServer((req, res) => {
    if (
        req.method === 'POST' &&
        String(req.url ?? '').split('?')[0] === EVENT_PATH
    ) {
        recordEvent(req, res);

        return;
    }

    const startedAt = performance.now();
    res.on('finish', () => logRequest(req, res.statusCode, startedAt));

    const upstream = http.request(
        {
            host: '127.0.0.1',
            port: appPort,
            method: req.method,
            path: req.url,
            headers: rewrite(req),
        },
        (response) => relay(req, res, response),
    );

    upstream.on('error', () => {
        if (!res.headersSent) {
            res.writeHead(502, { 'content-type': 'text/html; charset=utf-8' });
        }

        res.end(starting);
    });

    req.pipe(upstream);
});

server.on('upgrade', (req, socket, head) => {
    const upstream = net.connect(appPort, '127.0.0.1', () => {
        const lines = [`${req.method} ${req.url} HTTP/${req.httpVersion}`];

        for (const [name, value] of Object.entries(rewrite(req))) {
            for (const item of Array.isArray(value) ? value : [value]) {
                lines.push(`${name}: ${String(item)}`);
            }
        }

        upstream.write(`${lines.join('\r\n')}\r\n\r\n`);

        if (head?.length) {
            upstream.write(head);
        }

        socket.pipe(upstream).pipe(socket);
    });

    upstream.on('error', () => socket.destroy());
    socket.on('error', () => upstream.destroy());
});

server.listen(listenPort, '0.0.0.0');
