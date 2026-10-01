#!/usr/bin/env node
// Front door for the preview and published URLs. Rewrites Host/Origin to localhost so any
// dev server (Vite, Next, Rails, Django, ...) accepts requests for hostnames it
// has never heard of (*.ts.net etc.), without per-framework configuration.
// The original host is passed on as X-Forwarded-Host. WebSockets (hot reload) pass through.
// Apps that ignore X-Forwarded-Host write their own address as localhost into pages (asset URLs,
// redirects); text responses get those links pointed back at the address the visitor used.
import { spawn } from 'node:child_process';
import { timingSafeEqual } from 'node:crypto';
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
    utimesSync,
    writeFileSync,
} from 'node:fs';
import http from 'node:http';
import net from 'node:net';
import { createInterface } from 'node:readline';

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
        process.env.ONEDROP_TESTS_UI_PORT || 9323,
        process.env.ONEDROP_BROWSER_PORT || 9331,
        // The debugging ports browser.mjs gives the test's browser.
        ...Array.from({ length: 10 }, (_, i) => 9224 + i),
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
        // Growth analytics: which page, which site sent them, browser/device, and where they are.
        m: req.method,
        p: String(req.url ?? '/')
            .split('?')[0]
            .slice(0, 200),
        r: referrerHost(req.headers.referer, host),
        ua: String(req.headers['user-agent'] ?? '').slice(0, 300) || undefined,
        ...location(req.headers),
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

/**
 * Where the visitor is, when something in front of the app says: OneDrop's Cloudflare Worker (X-OneDrop-Geo-*,
 * from Cloudflare's own lookup), or a CDN's location headers (Cloudflare, CloudFront, Vercel). Country code
 * (c), region (rg), city (ci), and coordinates rounded to ~1km (la, lo).
 */
function location(headers) {
    const first = (...names) => {
        for (const name of names) {
            const value = String(headers[name] ?? '').trim();

            if (value !== '') {
                try {
                    return decodeURIComponent(value);
                } catch {
                    return value;
                }
            }
        }

        return undefined;
    };
    const text = (value) => value?.slice(0, 80) || undefined;
    const coordinate = (value, limit) => {
        const number = Number(value);

        return value !== undefined &&
            Number.isFinite(number) &&
            Math.abs(number) <= limit
            ? Math.round(number * 100) / 100
            : undefined;
    };

    const code = String(
        first(
            'x-onedrop-geo-country',
            'cf-ipcountry',
            'cloudfront-viewer-country',
            'x-vercel-ip-country',
            'x-country-code',
        ) ?? '',
    ).toUpperCase();
    const c =
        /^[A-Z]{2}$/.test(code) && !['XX', 'T1'].includes(code)
            ? code
            : undefined;

    if (!c) {
        return {};
    }

    const la = coordinate(
        first(
            'x-onedrop-geo-latitude',
            'cf-iplatitude',
            'cloudfront-viewer-latitude',
            'x-vercel-ip-latitude',
        ),
        90,
    );
    const lo = coordinate(
        first(
            'x-onedrop-geo-longitude',
            'cf-iplongitude',
            'cloudfront-viewer-longitude',
            'x-vercel-ip-longitude',
        ),
        180,
    );

    return {
        c,
        rg: text(
            first(
                'x-onedrop-geo-region',
                'cf-region',
                'cloudfront-viewer-country-region-name',
                'x-vercel-ip-country-region',
            ),
        ),
        ci: text(
            first(
                'x-onedrop-geo-city',
                'cf-ipcity',
                'cloudfront-viewer-city',
                'x-vercel-ip-city',
            ),
        ),
        ...(la !== undefined && lo !== undefined ? { la, lo } : {}),
    };
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

// Live reload (LIVE-002): apps without hot reload (Laravel's `vite build --watch`, plain PHP pages) would only
// change in the preview when the agent finishes. Preview pages listen here and reload once a build has been
// written or a PHP file changed. Dev servers with their own hot reload write neither, so they're left alone.
const LIVE_PATH = '/__onedrop/live';
const LIVE_CHANGE = /(\/public\/build\/|\.php$)/;
const LIVE_EXCLUDE =
    '/(node_modules|\\.git|vendor|storage|bootstrap/cache|\\.onedrop)(/|$)';
// Reload once writes have been quiet this long, so a build's files are all there.
const LIVE_QUIET_MS = 300;
const LIVE_PING_MS = 25_000;
// Keep watching this long after the last page went away: a reloading page reconnects a moment later.
const LIVE_LINGER_MS = 30_000;
const liveClients = new Set();
let liveWatcher = null;
let liveReloadTimer = null;
let liveStopTimer = null;

function tellPagesToReload() {
    for (const res of liveClients) {
        res.write('data: reload\n\n');
    }
}

function watchForReloads() {
    clearTimeout(liveStopTimer);

    if (liveWatcher) {
        return;
    }

    liveWatcher = spawn(
        'inotifywait',
        [
            '--monitor',
            '--recursive',
            '--quiet',
            '--event',
            'close_write,moved_to,delete',
            '--exclude',
            LIVE_EXCLUDE,
            '--format',
            '%w%f',
            '/workspace',
        ],
        { stdio: ['ignore', 'pipe', 'ignore'] },
    );

    const watcher = liveWatcher;
    watcher.on('error', () => {});
    watcher.on('close', () => {
        if (liveWatcher === watcher) {
            liveWatcher = null;
        }
    });

    createInterface({ input: watcher.stdout }).on('line', (path) => {
        if (LIVE_CHANGE.test(path)) {
            clearTimeout(liveReloadTimer);
            liveReloadTimer = setTimeout(tellPagesToReload, LIVE_QUIET_MS);
        }
    });
}

function stopWatchingSoon() {
    clearTimeout(liveStopTimer);
    liveStopTimer = setTimeout(() => {
        if (liveClients.size === 0 && liveWatcher) {
            liveWatcher.kill();
            liveWatcher = null;
        }
    }, LIVE_LINGER_MS);
}

/** An event stream that says "reload" when the preview's page is out of date; preview pages only. */
function serveLive(req, res) {
    if (!isPreview(String(req.headers.host ?? ''))) {
        res.writeHead(404).end();

        return;
    }

    res.writeHead(200, {
        'content-type': 'text/event-stream',
        'cache-control': 'no-cache',
        'x-accel-buffering': 'no',
    });
    res.write(': live\n\n');

    const ping = setInterval(() => res.write(': ping\n\n'), LIVE_PING_MS);
    liveClients.add(res);
    watchForReloads();

    req.on('close', () => {
        clearInterval(ping);
        liveClients.delete(res);

        if (liveClients.size === 0) {
            stopWatchingSoon();
        }
    });
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
    // Tell the workspace which page is showing, so reloading the workspace comes back to it (LAYOUT-005).
    if (window.parent !== window) {
        const where = () => {
            try { window.parent.postMessage({ onedrop: 'location', page: location.pathname + location.search + location.hash }, '*'); } catch {}
        };
        for (const name of ['pushState', 'replaceState']) {
            const original = history[name];
            history[name] = function () {
                const result = original.apply(this, arguments);
                where();
                return result;
            };
        }
        addEventListener('popstate', where);
        addEventListener('hashchange', where);
        where();
    }
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
    if (window.EventSource) {
        new EventSource('${LIVE_PATH}').onmessage = (event) => {
            if (event.data === 'reload') location.reload();
        };
    }
})();
`;

// Annotate (AGT-013): the workspace around the preview asks for a picture of the page as it's shown right now, and
// then for the elements under the user's marks. The picture is taken in the page (modern-screenshot, loaded only
// when asked) so it has what's open, typed and scrolled. Only the frame's own parent is answered, at its origin.
const CAPTURE_SCRIPT_PATH = '/__onedrop/capture.js';
const CAPTURE_LIBRARY =
    '/opt/onedrop/capture/node_modules/modern-screenshot/dist/index.js';
const PREVIEW_ANNOTATOR = `(() => {
    if (window.__onedropAnnotator || window.parent === window) return;
    window.__onedropAnnotator = true;
    let library;
    const load = () => library || (library = new Promise((resolve, reject) => {
        const tag = document.createElement('script');
        tag.src = '${CAPTURE_SCRIPT_PATH}';
        tag.onload = () => { tag.remove(); resolve(window.modernScreenshot); };
        tag.onerror = () => { tag.remove(); library = undefined; reject(new Error("Couldn't load the capture script")); };
        (document.head || document.documentElement).appendChild(tag);
    }));
    // The viewport only: the page is moved by its scroll, fixed elements are moved back, and scrolled boxes
    // (a dashboard's main column) keep their scroll, since a copy of the page can't be scrolled.
    const capture = async () => {
        const shot = await load();
        const x = scrollX, y = scrollY;
        const scrolled = [];
        for (const element of document.body ? document.body.querySelectorAll('*') : []) {
            if (element.scrollTop || element.scrollLeft) {
                element.setAttribute('data-onedrop-scroll', element.scrollLeft + ' ' + element.scrollTop);
                scrolled.push(element);
            }
        }
        try {
            return await shot.domToPng(document.documentElement, {
                width: innerWidth,
                height: innerHeight,
                scale: Math.min(devicePixelRatio || 1, 2),
                style: { transform: 'translate(' + -x + 'px, ' + -y + 'px)' },
                onCloneEachNode: (copy) => {
                    if (!(copy instanceof Element)) return;
                    if (copy.style && copy.style.position === 'fixed') copy.style.translate = x + 'px ' + y + 'px';
                    const scroll = copy.getAttribute('data-onedrop-scroll');
                    if (!scroll) return;
                    copy.removeAttribute('data-onedrop-scroll');
                    const [left, top] = scroll.split(' ');
                    for (const child of Array.from(copy.childNodes)) {
                        let moved = child;
                        if (child.nodeType === Node.TEXT_NODE) {
                            if (!child.textContent.trim()) continue;
                            moved = document.createElement('span');
                            child.replaceWith(moved);
                            moved.append(child);
                        }
                        if (moved.style) moved.style.translate = -left + 'px ' + -top + 'px';
                    }
                },
            });
        } finally {
            scrolled.forEach((element) => element.removeAttribute('data-onedrop-scroll'));
        }
    };
    const INLINE = new Set(['SPAN', 'B', 'I', 'EM', 'STRONG', 'SMALL', 'svg', 'path', 'g', 'use', 'circle', 'rect', 'line', 'polyline', 'polygon']);
    const SKIP = new Set(['HTML', 'BODY']);
    const describe = (element) => {
        let name = element.tagName.toLowerCase();
        if (element.id) name += '#' + element.id;
        const classes = typeof element.className === 'string' ? element.className.trim().split(/ +/).filter(Boolean) : [];
        if (classes.length) name += '.' + classes.slice(0, 3).join('.') + (classes.length > 3 ? '…' : '');
        const testId = element.getAttribute('data-testid') || element.getAttribute('data-test');
        const text = (element.innerText || element.getAttribute('aria-label') || element.getAttribute('placeholder')
            || element.getAttribute('alt') || element.getAttribute('name') || '').replace(/ *[\\n\\r]+ */g, ' ').trim();
        // Like a CSS selector, then the text in brackets: button#save.btn ("Save changes").
        return name + (testId ? '[data-test="' + testId + '"]' : '')
            + (text ? ' ("' + (text.length > 80 ? text.slice(0, 79) + '…' : text) + '")' : '');
    };
    // The element a mark points at: for a box, the one under its middle that fits it best; for a point, the one
    // there, skipping the icons and formatting inside it (a button, not its svg).
    const elementAt = (mark) => {
        const stack = document.elementsFromPoint(mark.x + mark.width / 2, mark.y + mark.height / 2)
            .filter((element) => !SKIP.has(element.tagName));
        if (!stack.length) return null;
        if (mark.width > 8 && mark.height > 8) {
            const area = mark.width * mark.height;
            let best = null, bestScore = 0;
            for (const element of stack) {
                const box = element.getBoundingClientRect();
                const overlap = Math.max(0, Math.min(box.right, mark.x + mark.width) - Math.max(box.left, mark.x))
                    * Math.max(0, Math.min(box.bottom, mark.y + mark.height) - Math.max(box.top, mark.y));
                const score = overlap / (area + box.width * box.height - overlap);
                if (score > bestScore) { best = element; bestScore = score; }
            }
            if (best) return describe(best);
        }
        let element = stack[0];
        while (INLINE.has(element.tagName) && element.parentElement && !SKIP.has(element.parentElement.tagName)) {
            element = element.parentElement;
        }
        return describe(element);
    };
    addEventListener('message', async (event) => {
        const data = event.data;
        if (event.source !== window.parent || !data || typeof data !== 'object') return;
        const reply = (message) => {
            try { window.parent.postMessage({ ...message, id: data.id }, event.origin === 'null' ? '*' : event.origin); } catch {}
        };
        if (data.onedrop === 'capture') {
            try {
                reply({ onedrop: 'captured', image: await capture(), width: innerWidth, height: innerHeight, page: location.pathname + location.search + location.hash });
            } catch (error) {
                reply({ onedrop: 'captured', error: String((error && error.message) || error) });
            }
        } else if (data.onedrop === 'inspect' && Array.isArray(data.marks)) {
            reply({ onedrop: 'inspected', elements: data.marks.slice(0, 50).map((mark) => {
                try { return elementAt(mark); } catch { return null; }
            }) });
        }
    });
})();
`;

/** The page-capture library the annotator loads (pinned in the Dockerfile), read once. */
let captureLibrary;

function serveCaptureLibrary(res) {
    try {
        captureLibrary ??= readFileSync(CAPTURE_LIBRARY);
    } catch {
        res.writeHead(404, { 'content-type': 'text/plain; charset=utf-8' });
        res.end('Capture library not installed');

        return;
    }

    res.writeHead(200, {
        'content-type': 'text/javascript; charset=utf-8',
        'cache-control': 'public, max-age=86400',
    });
    res.end(captureLibrary);
}

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

// The test runner (Playwright's UI mode, TEST-004), started by tests.mjs on a local port, is served under
// /__onedrop/tests-ui/ on the preview's address, never the published one. Only a browser holding the runner's
// token gets in: the Tests tab opens it with ?onedrop_tests_ui=<token>, traded here for a cookie. The runner
// stops itself when no one has used it for a while; every request (and open socket) here counts as use.
const TESTS_UI_PATH = '/__onedrop/tests-ui';
const TESTS_UI_PORT = Number(process.env.ONEDROP_TESTS_UI_PORT || 9323);
const TESTS_UI_STATE =
    process.env.ONEDROP_TESTS_UI_STATE || '/tmp/onedrop-tests-ui.json';
const TESTS_UI_SEEN = `${TESTS_UI_STATE}.seen`;
const TESTS_UI_COOKIE = 'onedrop_tests_ui';

function isTestsUi(path) {
    return path === TESTS_UI_PATH || path.startsWith(`${TESTS_UI_PATH}/`);
}

function testsUiToken() {
    try {
        const token = JSON.parse(readFileSync(TESTS_UI_STATE, 'utf8')).token;

        return typeof token === 'string' && token.length >= 32 ? token : null;
    } catch {
        return null;
    }
}

function sameToken(given, token) {
    const a = Buffer.from(String(given ?? ''));
    const b = Buffer.from(token);

    return a.length === b.length && timingSafeEqual(a, b);
}

function cookieValue(req, name) {
    for (const part of String(req.headers.cookie ?? '').split(';')) {
        const [key, ...value] = part.trim().split('=');

        if (key === name) {
            return value.join('=');
        }
    }

    return null;
}

/** Why this request can't reach the test runner, or null when it can. */
function testsUiRefusal(req) {
    const token = testsUiToken();

    if (!isPreview(String(req.headers.host ?? '')) || !token) {
        return [404, "The test runner isn't open. Open it from the Tests tab."];
    }

    return sameToken(cookieValue(req, TESTS_UI_COOKIE), token)
        ? null
        : [403, 'Open the test runner from the Tests tab.'];
}

function touchTestsUi() {
    const now = new Date();

    try {
        utimesSync(TESTS_UI_SEEN, now, now);
    } catch {
        try {
            writeFileSync(TESTS_UI_SEEN, '');
        } catch {
            // Not being able to note the visit only lets the runner stop sooner.
        }
    }
}

/** The runner's own path for a request under TESTS_UI_PATH. */
function testsUiPath(url) {
    return String(url ?? '').slice(TESTS_UI_PATH.length) || '/';
}

/** Headers for the runner: it only takes requests for its own local address. */
function testsUiHeaders(req) {
    const headers = { ...req.headers, host: `127.0.0.1:${TESTS_UI_PORT}` };

    delete headers.cookie;

    if (headers.origin) {
        headers.origin = `http://127.0.0.1:${TESTS_UI_PORT}`;
    }

    delete headers.referer;

    return headers;
}

function serveTestsUi(req, res) {
    const url = new URL(String(req.url ?? '/'), 'http://preview');
    const given = url.searchParams.get(TESTS_UI_COOKIE);
    const token = testsUiToken();

    // Coming from the Tests tab: trade the token for a cookie and drop it from the address.
    if (given !== null && token && isPreview(String(req.headers.host ?? ''))) {
        if (!sameToken(given, token)) {
            res.writeHead(403, { 'content-type': 'text/plain; charset=utf-8' });
            res.end(
                'This test runner link has expired. Open it again from the Tests tab.',
            );

            return;
        }

        url.searchParams.delete(TESTS_UI_COOKIE);
        const secure = visitorProto(req) === 'https' ? '; Secure' : '';
        res.writeHead(302, {
            'set-cookie': `${TESTS_UI_COOKIE}=${token}; Path=${TESTS_UI_PATH}; HttpOnly; SameSite=Lax${secure}`,
            location: `${url.pathname === TESTS_UI_PATH ? `${TESTS_UI_PATH}/` : url.pathname}${url.search}`,
            'cache-control': 'no-store',
        });
        res.end();

        return;
    }

    const refusal = testsUiRefusal(req);

    if (refusal) {
        res.writeHead(refusal[0], {
            'content-type': 'text/plain; charset=utf-8',
        });
        res.end(refusal[1]);

        return;
    }

    if (url.pathname === TESTS_UI_PATH) {
        res.writeHead(302, { location: `${TESTS_UI_PATH}/` });
        res.end();

        return;
    }

    touchTestsUi();

    const upstream = http.request(
        {
            host: '127.0.0.1',
            port: TESTS_UI_PORT,
            method: req.method,
            path: testsUiPath(req.url),
            headers: testsUiHeaders(req),
        },
        (response) => {
            res.writeHead(response.statusCode ?? 502, response.headers);
            response.pipe(res);
        },
    );

    upstream.on('error', () => {
        if (!res.headersSent) {
            res.writeHead(502, { 'content-type': 'text/plain; charset=utf-8' });
        }

        res.end(
            "The test runner isn't running. Open it again from the Tests tab.",
        );
    });

    req.pipe(upstream);
}

function upgradeTestsUi(req, socket, head) {
    if (testsUiRefusal(req)) {
        socket.end('HTTP/1.1 403 Forbidden\r\n\r\n');

        return;
    }

    touchTestsUi();
    // An open runner is in use: keep noting it while its socket stays open.
    const keepAlive = setInterval(touchTestsUi, 60_000);

    connectUpgrade(
        req,
        socket,
        head,
        TESTS_UI_PORT,
        testsUiPath(req.url),
        testsUiHeaders(req),
    );
    socket.on('close', () => clearInterval(keepAlive));
}

// The browser's viewer (browser.mjs, TEST-005) under /__onedrop/browser/, on the preview's address only. It's shown
// in an iframe in the workspace, where a cookie from this address wouldn't be sent, so every request (the page and
// its socket) carries the browser's token in the query instead, and is checked each time.
const BROWSER_PATH = '/__onedrop/browser';
const BROWSER_PORT = Number(process.env.ONEDROP_BROWSER_PORT || 9331);
const BROWSER_STATE =
    process.env.ONEDROP_BROWSER_STATE || '/tmp/onedrop-browser.json';

function isBrowser(path) {
    return path === BROWSER_PATH || path.startsWith(`${BROWSER_PATH}/`);
}

function browserAllowed(req) {
    let token = null;

    try {
        const state = JSON.parse(readFileSync(BROWSER_STATE, 'utf8'));
        token =
            state.ready &&
            typeof state.token === 'string' &&
            state.token.length >= 32
                ? state.token
                : null;
    } catch {
        // Not open.
    }

    const given = new URL(
        String(req.url ?? '/'),
        'http://preview',
    ).searchParams.get('token');

    return (
        !!token &&
        isPreview(String(req.headers.host ?? '')) &&
        sameToken(given, token)
    );
}

function browserHeaders(req) {
    const headers = {
        ...req.headers,
        host: `127.0.0.1:${BROWSER_PORT}`,
        'x-onedrop-proxied': '1',
    };

    delete headers.cookie;
    delete headers.origin;
    delete headers.referer;

    return headers;
}

function serveBrowser(req, res) {
    if (!browserAllowed(req)) {
        res.writeHead(404, { 'content-type': 'text/plain; charset=utf-8' });
        res.end(
            "The browser isn't open. Start it from a step in the Tests tab.",
        );

        return;
    }

    const upstream = http.request(
        {
            host: '127.0.0.1',
            port: BROWSER_PORT,
            method: req.method,
            path: String(req.url).slice(BROWSER_PATH.length) || '/',
            headers: browserHeaders(req),
        },
        (response) => {
            res.writeHead(response.statusCode ?? 502, {
                ...response.headers,
                'referrer-policy': 'no-referrer',
            });
            response.pipe(res);
        },
    );

    upstream.on('error', () => {
        if (!res.headersSent) {
            res.writeHead(502, { 'content-type': 'text/plain; charset=utf-8' });
        }

        res.end(
            "The browser isn't running. Start it again from the Tests tab.",
        );
    });

    req.pipe(upstream);
}

const server = http.createServer((req, res) => {
    const path = String(req.url ?? '').split('?')[0];

    if (isTestsUi(path)) {
        serveTestsUi(req, res);

        return;
    }

    if (isBrowser(path)) {
        serveBrowser(req, res);

        return;
    }

    if (req.method === 'POST' && path === EVENT_PATH) {
        recordEvent(req, res);

        return;
    }

    if (req.method === 'POST' && path === ERROR_PATH) {
        recordBrowserError(req, res);

        return;
    }

    if (req.method === 'GET' && path === LIVE_PATH) {
        serveLive(req, res);

        return;
    }

    if (req.method === 'GET' && path === ERROR_SCRIPT_PATH) {
        res.writeHead(200, {
            'content-type': 'text/javascript; charset=utf-8',
            'cache-control': 'no-cache',
        });
        res.end(ERROR_REPORTER + PREVIEW_ANNOTATOR);

        return;
    }

    if (req.method === 'GET' && path === CAPTURE_SCRIPT_PATH) {
        serveCaptureLibrary(res);

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

/** Pass a WebSocket (or other upgrade) through to a local port, as the given path with the given headers. */
function connectUpgrade(req, socket, head, port, path, headers) {
    const upstream = net.connect(port, '127.0.0.1', () => {
        const lines = [`${req.method} ${path} HTTP/${req.httpVersion}`];

        for (const [name, value] of Object.entries(headers)) {
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
}

server.on('upgrade', (req, socket, head) => {
    if (isTestsUi(String(req.url ?? '').split('?')[0])) {
        upgradeTestsUi(req, socket, head);

        return;
    }

    if (isBrowser(String(req.url ?? '').split('?')[0])) {
        if (browserAllowed(req)) {
            connectUpgrade(
                req,
                socket,
                head,
                BROWSER_PORT,
                String(req.url).slice(BROWSER_PATH.length),
                browserHeaders(req),
            );
        } else {
            socket.end('HTTP/1.1 404 Not Found\r\n\r\n');
        }

        return;
    }

    const port = targetPort(req.url);

    connectUpgrade(req, socket, head, port, req.url, rewrite(req, port));
});

server.listen(listenPort, '0.0.0.0');
