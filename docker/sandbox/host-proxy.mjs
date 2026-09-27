#!/usr/bin/env node
// Front door for the preview and published URLs. Rewrites Host/Origin to localhost so any
// dev server (Vite, Next, Rails, Django, ...) accepts requests for hostnames it
// has never heard of (*.ts.net etc.), without per-framework configuration.
// The original host is passed on as X-Forwarded-Host. WebSockets (hot reload) pass through.
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

function rewrite(req) {
    const headers = { ...req.headers };
    const originalHost = req.headers.host ?? '';

    headers['x-forwarded-host'] = originalHost;
    headers['x-forwarded-proto'] =
        req.headers['x-forwarded-proto'] ??
        (originalHost.endsWith('.ts.net') ? 'https' : 'http');
    headers.host = appHost;

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

const starting = `<!doctype html><meta charset="utf-8"><meta http-equiv="refresh" content="3">
<title>Starting…</title><body style="font-family:system-ui;display:grid;place-items:center;min-height:90vh;color:#666">
<p>The app is starting. This page refreshes by itself.</p></body>`;

const server = http.createServer((req, res) => {
    const upstream = http.request(
        {
            host: '127.0.0.1',
            port: appPort,
            method: req.method,
            path: req.url,
            headers: rewrite(req),
        },
        (response) => {
            res.writeHead(response.statusCode ?? 502, response.headers);
            response.pipe(res);
        },
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
