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
// Only this Worker can ask the app where a sandbox lives: it proves itself with GATEWAY_SECRET.

const GATEWAY_HOST = /^(preview|shell)-\d+\./;

/** A project published to the domain. Names like this that the app doesn't know go on to their own origin. */
const APP_HOST = /^[a-z0-9-]+-\d+\./;
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

        const preview = GATEWAY_HOST.test(url.hostname);

        // Other names under the wildcard go on to their own origin.
        if (
            !(preview || APP_HOST.test(url.hostname)) ||
            !url.hostname.endsWith(`.${env.GATEWAY_DOMAIN}`)
        ) {
            return fetch(request);
        }

        if (url.pathname === '/__onedrop/enter') {
            return withoutFrameBlock(
                await askApp(env, '/__onedrop/enter' + url.search, url.hostname),
            );
        }

        const auth = await authorize(request, env, ctx, url.hostname);

        // Looked like a published app, but isn't one: some other name under the wildcard.
        if (!preview && auth.status === 404) {
            return fetch(request);
        }

        if (auth.status !== 200) {
            return withoutFrameBlock(auth);
        }

        return forward(request, url, {
            upstream: auth.headers.get('X-OneDrop-Upstream'),
            header: auth.headers.get('X-OneDrop-Upstream-Header'),
            token: auth.headers.get('X-OneDrop-Upstream-Token'),
        });
    },
};

/**
 * Ask the app about a gateway address, as the Worker (with the shared secret and the address it serves).
 */
function askApp(env, path, host, cookie = '') {
    return fetch(new URL(path, env.APP_URL), {
        headers: {
            'X-OneDrop-Gateway-Secret': env.GATEWAY_SECRET,
            'X-OneDrop-Gateway-Host': host,
            Accept: 'text/html',
            ...(cookie ? { Cookie: `${COOKIE}=${cookie}` } : {}),
        },
        redirect: 'manual',
    });
}

/**
 * The app's answer for this browser on this address, reused for a minute so assets don't each cost a request.
 * Without a cookie only a public app is let through, so that answer is shared by everyone on the address.
 */
async function authorize(request, env, ctx, host) {
    const cookie = readCookie(request.headers.get('Cookie'), COOKIE);
    const key = new Request(
        `https://gateway-auth.internal/${host}/${cookie ? await sha256(cookie) : 'public'}`,
    );
    const cached = await caches.default.match(key);

    if (cached) {
        return cached;
    }

    const answer = await askApp(
        env,
        '/sandbox-gateway/authorize',
        host,
        cookie ?? '',
    );

    if (answer.status === 200) {
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
 * Send the request on to the provider with its token, and point its answer back at the gateway address.
 */
async function forward(request, url, { upstream, header, token }) {
    const target = new URL(url.pathname + url.search, upstream);
    const headers = new Headers(request.headers);

    // Our cookie is for us; the app in the sandbox never sees it.
    const cookies = withoutCookie(headers.get('Cookie'), COOKIE);
    cookies ? headers.set('Cookie', cookies) : headers.delete('Cookie');

    if (header && token) {
        headers.set(header, token);
    }

    const init = { method: request.method, headers, redirect: 'manual' };

    if (!['GET', 'HEAD'].includes(request.method)) {
        init.body = request.body;
    }

    // WebSockets (the Shell tab, hot reload) pass straight through.
    if (request.headers.get('Upgrade')?.toLowerCase() === 'websocket') {
        return fetch(target, init);
    }

    const response = await fetch(target, init);

    return pointAtGateway(response, new URL(upstream).host, url.host);
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
