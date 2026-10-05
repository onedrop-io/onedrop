// OneDrop preview gateway: previews and shells at preview-<id>.<domain> / shell-<id>.<domain>, and projects
// published to the domain at <name>-<project id>.<domain> (PUB-002), in front of whichever provider runs the
// sandbox (Blaxel, Runtime). It does what Caddy does on a server (GW-001):
//
//   /__onedrop/enter  → the app trades its short-lived hand-off token for this address's own cookie.
//   anything else → the app checks that cookie (who may see the project), answers with the provider's address
//                   and private preview token, and the Worker forwards the request there with the token in a
//                   header, so the browser only ever deals with onedrop.io addresses and cookies. A public app
//                   needs no cookie; a private one answers with a redirect to sign in, passed on to the browser.
//
// Custom domains (DOM-001) reach it too, as Cloudflare for SaaS custom hostnames on the zone's catch-all route: the
// app says whether one belongs to a project published to the domain, and it's served the same way.
//
// Only this Worker can ask the app where a sandbox lives: it proves itself with GATEWAY_SECRET.
//
// relay.<domain> is the device relay (DESK-010, device-relay.js): a Durable Object per computer that the desktop app
// keeps a WebSocket open to. The app reaches sandboxes on that computer through it, and so do their previews and
// shells: the app's answer names `device:<id>/<container>:<port>` instead of a provider's address.

import { DeviceRelay } from './device-relay.js';

export { DeviceRelay };

const GATEWAY_HOST = /^(preview|shell)-\d+\./;

/** A project published to the domain. Names like this that the app doesn't know go on to their own origin. */
const APP_HOST = /^[a-z0-9-]+-\d+\./;

/** A sandbox on someone's computer, reached through the device relay: device:<id>/<container>:<port>. */
const DEVICE_UPSTREAM = /^device:(\d+)\/([A-Za-z0-9_.-]+):(\d+)$/;
const DEVICE_PATH = /^\/__onedrop\/devices\/(\d+)\//;
const COOKIE = 'onedrop_gateway';

/** How long an authorization answer is reused for the same cookie on the same address. */
const AUTH_CACHE_SECONDS = 60;

/** Text answers whose links to the provider's address are pointed at the gateway address. */
const REWRITABLE =
    /^(text\/(html|css|javascript|plain|xml)|application\/(javascript|json|xml|xhtml\+xml|manifest\+json)|image\/svg\+xml)/i;
const MAX_REWRITE_BYTES = 20_000_000;

export default {
    async fetch(request, env, ctx) {
        const url = new URL(request.url);

        if (url.hostname === `relay.${env.GATEWAY_DOMAIN}`) {
            return toRelay(request, env, url);
        }

        const preview = GATEWAY_HOST.test(url.hostname);
        const ours =
            url.hostname === env.GATEWAY_DOMAIN ||
            url.hostname.endsWith(`.${env.GATEWAY_DOMAIN}`);

        // The app itself, and other names under the wildcard, go on to their own origin.
        if (ours && !(preview || APP_HOST.test(url.hostname))) {
            return fetch(request);
        }

        if (url.pathname === '/__onedrop/enter') {
            return withoutFrameBlock(
                await askApp(
                    env,
                    '/__onedrop/enter' + url.search,
                    url.hostname,
                ),
            );
        }

        const auth = await authorize(request, env, ctx, url);

        // Looked like a published app, but isn't one: some other name under the wildcard, or a custom domain that
        // isn't (or is no longer) connected.
        if (!preview && auth.status === 404) {
            return ours ? fetch(request) : notConnected();
        }

        if (auth.status !== 200) {
            return withoutFrameBlock(auth);
        }

        return forward(request, url, env, {
            upstream: auth.headers.get('X-OneDrop-Upstream'),
            header: auth.headers.get('X-OneDrop-Upstream-Header'),
            token: auth.headers.get('X-OneDrop-Upstream-Token'),
            computer: auth.headers.get('X-OneDrop-Upstream-Computer'),
        });
    },
};

/**
 * Ask the app about a gateway address, as the Worker (with the shared secret and the address it serves).
 */
function askApp(env, path, host, cookie = '', uri = '') {
    return fetch(new URL(path, env.APP_URL), {
        headers: {
            'X-OneDrop-Gateway-Secret': env.GATEWAY_SECRET,
            'X-OneDrop-Gateway-Host': host,
            Accept: 'text/html',
            // The page asked for, so a sign-in or a move to the primary domain comes back to it.
            ...(uri ? { 'X-Forwarded-Uri': uri } : {}),
            ...(cookie ? { Cookie: `${COOKIE}=${cookie}` } : {}),
        },
        redirect: 'manual',
    });
}

/**
 * A custom domain pointed here that no published app has.
 */
export function notConnected() {
    return new Response(
        '<!doctype html><title>Not connected</title><p>This domain isn’t connected to a published app.</p>',
        {
            status: 404,
            headers: {
                'Content-Type': 'text/html; charset=utf-8',
                'Cache-Control': 'no-store',
            },
        },
    );
}

/**
 * A preview whose project runs on someone's computer while the OneDrop app isn't open there (DESK-010).
 */
export function waitingForComputer(computer) {
    const name = (computer ? decodeURIComponent(computer) : 'its computer')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');

    return new Response(
        `<!doctype html><meta name="viewport" content="width=device-width"><title>Waiting for ${name}</title>` +
            '<body style="font:16px/1.5 system-ui,sans-serif;display:grid;place-items:center;min-height:90vh;margin:0;color:#555">' +
            `<p style="max-width:28rem;text-align:center">This project runs on ${name}. It’s back as soon as the OneDrop app is open there.</p>`,
        {
            status: 503,
            headers: {
                'Content-Type': 'text/html; charset=utf-8',
                'Cache-Control': 'no-store',
                'Retry-After': '10',
            },
        },
    );
}

/**
 * The app's answer for this browser on this address, reused for a minute so assets don't each cost a request.
 * Without a cookie only a public app is let through, so that answer is shared by everyone on the address.
 */
async function authorize(request, env, ctx, url) {
    const host = url.hostname;
    const cookie = readCookie(request.headers.get('Cookie'), COOKIE);
    // The desktop app's tunnel (DESK-007..009) is let in by a ticket in its address, not a cookie: its answer is for
    // that one request, and must never stand for everyone without a cookie.
    const tunnel = url.pathname === '/__onedrop/tunnel';
    const key = new Request(
        `https://gateway-auth.internal/${host}/${cookie ? await sha256(cookie) : 'public'}`,
    );
    const cached = tunnel ? null : await caches.default.match(key);

    if (cached) {
        return cached;
    }

    const answer = await askApp(
        env,
        '/sandbox-gateway/authorize',
        host,
        cookie ?? '',
        url.pathname + url.search,
    );

    if (answer.status === 200 && !tunnel) {
        const keep = new Response(null, {
            status: 200,
            headers: answer.headers,
        });
        keep.headers.set('Cache-Control', `max-age=${AUTH_CACHE_SECONDS}`);
        ctx.waitUntil(caches.default.put(key, keep));
    }

    return answer;
}

/**
 * relay.<domain>: each computer's requests go to its own Durable Object, which checks the ticket or the secret.
 */
function toRelay(request, env, url) {
    const device = DEVICE_PATH.exec(url.pathname)?.[1];

    if (!device) {
        return new Response('Not found', { status: 404 });
    }

    return relayFor(env, device).fetch(request);
}

function relayFor(env, device) {
    return env.DEVICE_RELAY.get(env.DEVICE_RELAY.idFromName(String(device)));
}

/**
 * Send the request on to the provider with its token, and point its answer back at the gateway address. A sandbox
 * on someone's computer is reached through its device relay instead.
 */
async function forward(
    request,
    url,
    env,
    { upstream, header, token, computer },
) {
    const headers = new Headers(request.headers);

    // Our cookie is for us; the app in the sandbox never sees it.
    const cookies = withoutCookie(headers.get('Cookie'), COOKIE);
    cookies ? headers.set('Cookie', cookies) : headers.delete('Cookie');

    if (header && token) {
        headers.set(header, token);
    }

    setLocation(headers, request.cf);

    // The provider's proxy sees its own address as Host; this is the one the browser used, so the sandbox can
    // tell the page's own Origin from another site's (Phoenix LiveView and Vite refuse sockets otherwise).
    headers.set('X-OneDrop-Host', url.host);

    const init = { method: request.method, headers, redirect: 'manual' };

    if (!['GET', 'HEAD'].includes(request.method)) {
        init.body = request.body;
    }

    const device = DEVICE_UPSTREAM.exec(upstream ?? '');

    // The computer gets the browser's request as it is (HTTP or WebSocket): its links are already to this address.
    if (device) {
        const [, id, container, port] = device;
        headers.set('X-OneDrop-Gateway-Secret', env.GATEWAY_SECRET);

        const response = await relayFor(env, id).fetch(
            `https://relay.${env.GATEWAY_DOMAIN}/__onedrop/devices/${id}/port/${container}/${port}${url.pathname}${url.search}`,
            init,
        );

        // The computer isn't connected: say so in words, for a person looking at the preview.
        return response.status === 503 &&
            request.headers.get('Accept')?.includes('text/html')
            ? waitingForComputer(computer)
            : response;
    }

    const target = new URL(url.pathname + url.search, upstream);

    // WebSockets (the Shell tab, hot reload) pass straight through.
    if (request.headers.get('Upgrade')?.toLowerCase() === 'websocket') {
        return fetch(target, init);
    }

    const response = await fetch(target, init);

    return pointAtGateway(response, new URL(upstream).host, url.host);
}

/**
 * Where the visitor is, from Cloudflare's own lookup, for the app's Growth analytics (the sandbox's proxy logs
 * it). Whatever the browser sent under these names is dropped, so visitors can't place themselves.
 */
export function setLocation(headers, cf) {
    const values = {
        country: cf?.country,
        region: cf?.region,
        city: cf?.city,
        latitude: cf?.latitude,
        longitude: cf?.longitude,
    };

    for (const [name, value] of Object.entries(values)) {
        headers.delete(`X-OneDrop-Geo-${name}`);

        if (value !== undefined && value !== null && value !== '') {
            // Header values are ASCII: names like Montréal are sent URL-encoded.
            headers.set(
                `X-OneDrop-Geo-${name}`,
                encodeURIComponent(String(value)),
            );
        }
    }
}

/**
 * Links and redirects to the provider's address become links to the gateway address.
 */
export async function pointAtGateway(response, upstreamHost, publicHost) {
    const headers = new Headers(response.headers);
    const location = headers.get('Location');

    if (location) {
        headers.set('Location', location.split(upstreamHost).join(publicHost));
    }

    const type = headers.get('Content-Type') ?? '';
    const size = Number(headers.get('Content-Length') ?? 0);

    if (
        !REWRITABLE.test(type) ||
        size > MAX_REWRITE_BYTES ||
        response.body === null
    ) {
        return new Response(response.body, {
            status: response.status,
            statusText: response.statusText,
            headers,
        });
    }

    // Read decoded, so sent on uncompressed with its new length.
    const text = await response.text();
    headers.delete('Content-Encoding');
    headers.delete('Content-Length');

    return new Response(text.split(upstreamHost).join(publicHost), {
        status: response.status,
        statusText: response.statusText,
        headers,
    });
}

/**
 * The app's own pages here (the hand-off, "Reopen") are shown inside the workspace's frame.
 */
export function withoutFrameBlock(response) {
    const headers = new Headers(response.headers);
    headers.delete('X-Frame-Options');
    headers.set('Cache-Control', 'no-store');

    return new Response(response.body, {
        status: response.status,
        statusText: response.statusText,
        headers,
    });
}

export function readCookie(header, name) {
    for (const part of (header ?? '').split(';')) {
        const [key, ...value] = part.trim().split('=');

        if (key === name) {
            return value.join('=');
        }
    }

    return null;
}

export function withoutCookie(header, name) {
    return (header ?? '')
        .split(';')
        .map((part) => part.trim())
        .filter((part) => part !== '' && part.split('=')[0] !== name)
        .join('; ');
}

async function sha256(value) {
    const digest = await crypto.subtle.digest(
        'SHA-256',
        new TextEncoder().encode(value),
    );

    return [...new Uint8Array(digest)]
        .map((byte) => byte.toString(16).padStart(2, '0'))
        .join('');
}
