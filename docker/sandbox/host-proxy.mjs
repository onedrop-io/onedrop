#!/usr/bin/env node
// Front door for the preview and published URLs. Rewrites Host/Origin to localhost so any
// dev server (Vite, Next, Rails, Django, ...) accepts requests for hostnames it
// has never heard of (*.ts.net etc.), without per-framework configuration.
// The original host is passed on as X-Forwarded-Host. WebSockets (hot reload) pass through.
// Apps that ignore X-Forwarded-Host write their own address as localhost into pages (asset URLs,
// redirects); text responses get those links pointed back at the address the visitor used.
import {
    appendFile,
    closeSync,
    fstatSync,
    mkdirSync,
    openSync,
    readFileSync,
    readSync,
    renameSync,
    statSync,
} from 'node:fs';
import http from 'node:http';
import net from 'node:net';

const appPort = Number(process.env.PORT || 8000);
const listenPort = Number(process.env.PROXY_PORT || 8081);
const appHost = `localhost:${appPort}`;

// Extra servers behind the same address (a realtime server like Laravel Reverb, a separate WebSocket
// server): /workspace/.onedrop/routes.json maps path prefixes to local ports, e.g. {"/app": 8080}. Requests
// and WebSockets under a prefix go to its port instead of the app's. The sandbox's own services
// (this proxy, the web terminal, SSH) can never be routed to, so they stay behind the platform's auth.
const ROUTES_FILE =
    process.env.ONEDROP_ROUTES_FILE || '/workspace/.onedrop/routes.json';
const RESERVED_PORTS = new Set(
    [
        listenPort,
        process.env.SHELL_PORT || 7681,
        process.env.SSH_PORT || 2222,
    ].map(Number),
);
const ROUTES_TTL_MS = 1000;
/** @type {{ at: number, list: Array<[string, number]> }} */
let routes = { at: 0, list: [] };

/** The routes in routes.json, longest prefix first; invalid entries are skipped. */
function readRoutes() {
    let config;

    try {
        config = JSON.parse(readFileSync(ROUTES_FILE, 'utf8'));
    } catch {
        return [];
    }

    if (!config || typeof config !== 'object' || Array.isArray(config)) {
        return [];
    }

    return Object.entries(config)
        .map(([prefix, port]) => [prefix.replace(/\/+$/, ''), Number(port)])
        .filter(
            ([prefix, port]) =>
                /^\/[\w\-.~/]+$/.test(prefix) &&
                Number.isInteger(port) &&
                port > 0 &&
                port < 65536 &&
                port !== appPort &&
                !RESERVED_PORTS.has(port),
        )
        .sort(([a], [b]) => b.length - a.length);
}

/** The local port a request goes to: a routed server's, or the app's. */
function targetPort(url) {
    if (Date.now() - routes.at > ROUTES_TTL_MS) {
        routes = { at: Date.now(), list: readRoutes() };
    }

    const path = String(url ?? '/').split('?')[0];
    const route = routes.list.find(
        ([prefix]) => path === prefix || path.startsWith(`${prefix}/`),
    );

    return route ? route[1] : appPort;
}

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
        String(req.headers['x-forwarded-proto'] ?? '')
            .split(',')[0]
            .trim() || (host.endsWith('.ts.net') ? 'https' : 'http')
    );
}

/** Whether the visitor used an address other than the app's own (a preview, published or published-port URL). */
function isForeignHost(host) {
    return host !== '' && host !== appHost && host !== `127.0.0.1:${appPort}`;
}

function rewrite(req, port = appPort) {
    const headers = { ...req.headers };
    const originalHost = req.headers.host ?? '';

    headers['x-forwarded-host'] = originalHost;
    headers['x-forwarded-proto'] = visitorProto(req);
    headers.host = `localhost:${port}`;

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

    if ((response.statusCode ?? 502) >= 500) {
        watchServerError(req, response);
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
        let body = Buffer.concat(chunks);

        if (size <= MAX_REWRITE_BYTES) {
            let text = toVisitor(body.toString('utf8'), req);

            if (
                isPreview(host) &&
                /^text\/html/i.test(String(headers['content-type']))
            ) {
                text = withErrorReporter(text, response.statusCode ?? 502);
            }

            body = Buffer.from(text, 'utf8');
        }

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
const MONITOR_DIR = '/workspace/.onedrop';
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
// {"name": "signed_up", "props": {"plan": "pro"}} to its own /__onedrop/event, and it's logged here,
// never passed on to the app. Names and property keys are snake_case; values are short strings,
// numbers or booleans. Anything else is dropped.
const EVENT_PATH = '/__onedrop/event';
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

/** Read a small request body; `done` gets null when it's larger than `max` bytes. */
function readBody(req, max, done) {
    let body = '';
    let tooLarge = false;

    req.setEncoding('utf8');
    req.on('data', (chunk) => {
        tooLarge ||= body.length + chunk.length > max;
        body = tooLarge ? '' : body + chunk;
    });
    req.on('end', () => done(tooLarge ? null : body));
}

function recordEvent(req, res) {
    readBody(req, MAX_EVENT_BYTES, (body) => {
        const event = body === null ? null : parseEvent(body);
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

// Errors, whatever the app's stack (instructions.md, "When something breaks"): 5xx answers (with the page's
// text and the end of the dev server's log), errors in the preview's browser (reported by a small script added
// to preview pages), and the app not answering. The agent reads them in errors.log; the app builder shows the
// browser's over the preview. Browser reports are only taken from the preview, which is behind the platform's auth.
const ERRORS_LOG = `${MONITOR_DIR}/errors.log`;
const ERROR_PATH = '/__onedrop/error';
const ERROR_SCRIPT_PATH = '/__onedrop/errors.js';
const SERVER_LOG = '/tmp/onedrop-server.log';
const BROWSER_ERROR_TYPES = new Set([
    'error',
    'rejection',
    'console',
    'resource',
]);
const MAX_ERROR_REPORT_BYTES = 16_384;
const MAX_ERROR_BODY_BYTES = 200_000;
const MAX_ERROR_TEXT = 2000;
const MAX_LOG_TAIL_BYTES = 4000;
// The same error again within this window isn't logged twice; the app being down is logged at most this often.
const ERROR_REPEAT_MS = 5000;
const DOWN_REPEAT_MS = 30_000;
// Terminal colour codes, stripped from logged errors; matching the ESC control character is the point.
// eslint-disable-next-line no-control-regex
const ANSI = /\u001b\[[0-9;?]*[A-Za-z]/g;
const lastErrors = new Map();

// Added to preview pages. Reports to the proxy (for errors.log) and to the app builder around the preview.
// Server errors (the page itself, or fetch/XHR answers) are already logged by the proxy, so they're only shown.
const ERROR_REPORTER = `(() => {
    if (window.__onedropErrors) return;
    window.__onedropErrors = true;
    const script = document.currentScript;
    const seen = new Set();
    let count = 0;
    const describe = (value) => {
        if (value instanceof Error) return value.message || String(value);
        if (typeof value === 'string') return value;
        try { return JSON.stringify(value); } catch { return String(value); }
    };
    const report = (type, message, stack, source) => {
        message = String(message || '').slice(0, 1000);
        if (!message || seen.has(type + message) || count >= 20) return;
        seen.add(type + message);
        count++;
        const error = { type, message, stack: stack ? String(stack).slice(0, 4000) : undefined, source, page: location.pathname };
        if (type !== 'server') {
            try { navigator.sendBeacon('${ERROR_PATH}', JSON.stringify(error)); } catch {}
        }
        if (type !== 'console' && window.parent !== window) {
            try { window.parent.postMessage({ onedrop: 'error', error }, '*'); } catch {}
        }
    };
    const status = Number(script && script.dataset.status);
    if (status) {
        const show = () => report('server', status + ' ' + (document.title || 'Server error') + ': '
            + (document.body ? document.body.innerText : '').replace(/\\s+/g, ' ').slice(0, 300), undefined, location.pathname);
        document.readyState === 'loading' ? addEventListener('DOMContentLoaded', show) : show();
    }
    addEventListener('error', (event) => {
        const target = event.target;
        if (target instanceof HTMLScriptElement || target instanceof HTMLLinkElement) {
            report('resource', "Couldn't load " + (target.src || target.href), undefined, target.src || target.href);
        } else if (target === window || !(target instanceof Element)) {
            report('error', event.message || describe(event.error), event.error && event.error.stack,
                event.filename ? event.filename + ':' + event.lineno + ':' + event.colno : undefined);
        }
    }, true);
    addEventListener('unhandledrejection', (event) => report('rejection', describe(event.reason), event.reason && event.reason.stack));
    const consoleError = console.error;
    console.error = function (...args) {
        const error = args.find((arg) => arg instanceof Error);
        report('console', args.map(describe).join(' '), error && error.stack);
        return consoleError.apply(this, args);
    };
    const failed = (code, method, url) => {
        if (code >= 500) report('server', code + ' from ' + String(method || 'GET').toUpperCase() + ' ' + url, undefined, url);
    };
    const originalFetch = window.fetch;
    if (originalFetch) {
        window.fetch = function (input, init) {
            const url = input instanceof Request ? input.url : String(input);
            const method = (init && init.method) || (input instanceof Request ? input.method : 'GET');
            return originalFetch.apply(this, arguments).then((response) => {
                failed(response.status, method, url);
                return response;
            });
        };
    }
    const open = XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open = function (method, url) {
        this.addEventListener('loadend', () => failed(this.status, method, String(url)));
        return open.apply(this, arguments);
    };
})();
`;

/** Add the error reporter to a preview page, first thing in its head (or body), so it sees the app's first errors. */
function withErrorReporter(html, status) {
    const tag = `<script src="${ERROR_SCRIPT_PATH}"${status >= 500 ? ` data-status="${status}"` : ''}></script>`;
    const opening = /<head\b[^>]*>/i.exec(html) ?? /<body\b[^>]*>/i.exec(html);

    if (!opening) {
        return html;
    }

    const at = opening.index + opening[0].length;

    return html.slice(0, at) + tag + html.slice(at);
}

/** An error page's readable text: HTML without its tags, scripts and styles; other types as they are. */
function pageText(raw, type) {
    const text = /html/i.test(type)
        ? raw
              .replace(/<(head|script|style|svg)\b[\s\S]*?<\/\1>/gi, ' ')
              .replace(/<[^>]+>/g, ' ')
              .replace(/&nbsp;/g, ' ')
              .replace(/&lt;/g, '<')
              .replace(/&gt;/g, '>')
              .replace(/&quot;/g, '"')
              .replace(/&#0?39;/g, "'")
              .replace(/&amp;/g, '&')
        : raw;

    return (
        text.replace(/\s+/g, ' ').trim().slice(0, MAX_ERROR_TEXT) || undefined
    );
}

/** The end of the dev server's output, where most stacks print what went wrong. */
function serverLogTail() {
    try {
        const fd = openSync(SERVER_LOG, 'r');

        try {
            const size = fstatSync(fd).size;
            const length = Math.min(size, MAX_LOG_TAIL_BYTES);
            const buffer = Buffer.alloc(length);

            readSync(fd, buffer, 0, length, size - length);

            const lines = buffer.toString('utf8').replace(ANSI, '').split('\n');

            // Drop the partial first line.
            if (length < size) {
                lines.shift();
            }

            return lines.join('\n').trim() || undefined;
        } finally {
            closeSync(fd);
        }
    } catch {
        return undefined;
    }
}

function recordError(req, entry, key, repeatMs = ERROR_REPEAT_MS) {
    const now = Date.now();

    if (now - (lastErrors.get(key) ?? 0) < repeatMs) {
        return;
    }

    if (lastErrors.size > 200) {
        lastErrors.clear();
    }

    lastErrors.set(key, now);
    record(ERRORS_LOG, {
        t: now,
        ...entry,
        pub: !isPreview(String(req.headers.host ?? '')),
    });
}

function requestPath(req) {
    return String(req.url ?? '/').slice(0, 200);
}

/** Log a 5xx answer with its text once it has arrived (the answer itself goes on unchanged). */
function watchServerError(req, response) {
    const chunks = [];
    let size = 0;

    response.on('data', (chunk) => {
        if (size < MAX_ERROR_BODY_BYTES) {
            chunks.push(chunk);
            size += chunk.length;
        }
    });
    response.on('end', () => {
        const type = String(response.headers['content-type'] ?? '');
        // Compressed answers (to requests from inside the sandbox) can't be read here; the log still helps.
        const text = response.headers['content-encoding']
            ? undefined
            : pageText(
                  Buffer.concat(chunks)
                      .toString('utf8')
                      .slice(0, MAX_ERROR_BODY_BYTES),
                  type,
              );
        const path = requestPath(req);

        recordError(
            req,
            {
                k: 'server',
                s: response.statusCode,
                m: req.method,
                p: path,
                text,
                log: serverLogTail(),
            },
            `server ${response.statusCode} ${path} ${text?.slice(0, 200)}`,
        );
    });
}

/** The app didn't answer (not started, restarting, or crashed). */
function recordDown(req) {
    recordError(
        req,
        { k: 'down', m: req.method, p: requestPath(req), log: serverLogTail() },
        'down',
        DOWN_REPEAT_MS,
    );
}

/** A browser error from the preview's reporter, or null when it doesn't look like one. */
function parseBrowserError(body) {
    let error;

    try {
        error = JSON.parse(body);
    } catch {
        return null;
    }

    if (
        !error ||
        typeof error !== 'object' ||
        !BROWSER_ERROR_TYPES.has(error.type) ||
        typeof error.message !== 'string' ||
        error.message === ''
    ) {
        return null;
    }

    const text = (value, max) =>
        typeof value === 'string' && value !== ''
            ? value.slice(0, max)
            : undefined;

    return {
        type: error.type,
        msg: error.message.slice(0, 1000),
        stack: text(error.stack, 4000),
        src: text(error.source, 500),
        page: text(error.page, 200),
    };
}

function recordBrowserError(req, res) {
    readBody(req, MAX_ERROR_REPORT_BYTES, (body) => {
        const error =
            body !== null && isPreview(String(req.headers.host ?? ''))
                ? parseBrowserError(body)
                : null;

        if (error) {
            recordError(
                req,
                { k: 'browser', ...error },
                `browser ${error.type} ${error.msg.slice(0, 200)}`,
            );
        }

        res.writeHead(error ? 204 : 400, { 'cache-control': 'no-store' });
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
    const path = String(req.url ?? '').split('?')[0];

    if (req.method === 'POST' && path === EVENT_PATH) {
        recordEvent(req, res);

        return;
    }

    if (req.method === 'POST' && path === ERROR_PATH) {
        recordBrowserError(req, res);

        return;
    }

    if (req.method === 'GET' && path === ERROR_SCRIPT_PATH) {
        res.writeHead(200, {
            'content-type': 'text/javascript; charset=utf-8',
            'cache-control': 'no-cache',
        });
        res.end(ERROR_REPORTER);

        return;
    }

    const startedAt = performance.now();
    res.on('finish', () => logRequest(req, res.statusCode, startedAt));

    const port = targetPort(req.url);
    const upstream = http.request(
        {
            host: '127.0.0.1',
            port,
            method: req.method,
            path: req.url,
            headers: rewrite(req, port),
        },
        (response) => relay(req, res, response),
    );

    upstream.on('error', () => {
        recordDown(req);

        if (!res.headersSent) {
            res.writeHead(502, { 'content-type': 'text/html; charset=utf-8' });
        }

        res.end(starting);
    });

    req.pipe(upstream);
});

server.on('upgrade', (req, socket, head) => {
    const port = targetPort(req.url);
    const upstream = net.connect(port, '127.0.0.1', () => {
        const lines = [`${req.method} ${req.url} HTTP/${req.httpVersion}`];

        for (const [name, value] of Object.entries(rewrite(req, port))) {
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
