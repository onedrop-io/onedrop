// Run with: node --test infra/cloudflare/preview-gateway/worker.test.mjs
import assert from 'node:assert/strict';
import test from 'node:test';
import worker, {
    pointAtGateway,
    readCookie,
    setLocation,
    withoutCookie,
    withoutFrameBlock,
} from './worker.js';

const env = {
    APP_URL: 'https://onedrop.io',
    GATEWAY_DOMAIN: 'onedrop.io',
    GATEWAY_SECRET: 'the-secret',
};

/**
 * Run the Worker on a request, with the app's authorize answer given and every outgoing fetch recorded.
 */
async function run(url, authorize, { cookie, headers: sentHeaders = {} } = {}) {
    const sent = [];
    const store = new Map();

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

        return new Response(`from ${target}`, {
            headers: { 'Content-Type': 'text/plain' },
        });
    };

    const headers = {
        ...sentHeaders,
        ...(cookie ? { Cookie: `onedrop_gateway=${cookie}` } : {}),
    };
    const waits = [];
    const response = await worker.fetch(new Request(url, { headers }), env, {
        waitUntil: (p) => waits.push(p),
    });
    await Promise.all(waits);

    return { response, sent, store };
}

test('links and redirects to the provider address point at the gateway address', async () => {
    const response = new Response(
        '<link href="https://abc.preview.bl.run/app.css"><a href="/x">',
        {
            status: 200,
            headers: {
                'Content-Type': 'text/html',
                'Content-Length': '60',
                Location: 'https://abc.preview.bl.run/contacts',
            },
        },
    );

    const out = await pointAtGateway(
        response,
        'abc.preview.bl.run',
        'preview-12.onedrop.io',
    );

    assert.equal(
        await out.text(),
        '<link href="https://preview-12.onedrop.io/app.css"><a href="/x">',
    );
    assert.equal(
        out.headers.get('Location'),
        'https://preview-12.onedrop.io/contacts',
    );
    assert.equal(out.headers.get('Content-Length'), null);
});

test('binary answers pass through untouched', async () => {
    const out = await pointAtGateway(
        new Response('abc.preview.bl.run', {
            headers: { 'Content-Type': 'image/png' },
        }),
        'abc.preview.bl.run',
        'preview-12.onedrop.io',
    );

    assert.equal(await out.text(), 'abc.preview.bl.run');
});

test('the gateway cookie is read, and kept from the app in the sandbox', () => {
    assert.equal(
        readCookie('a=1; onedrop_gateway=abc=; b=2', 'onedrop_gateway'),
        'abc=',
    );
    assert.equal(readCookie('a=1', 'onedrop_gateway'), null);
    assert.equal(
        withoutCookie('a=1; onedrop_gateway=abc; b=2', 'onedrop_gateway'),
        'a=1; b=2',
    );
    assert.equal(withoutCookie('onedrop_gateway=abc', 'onedrop_gateway'), '');
});

test("the visitor's location comes from Cloudflare, never from the browser", () => {
    const headers = new Headers({
        'X-OneDrop-Geo-City': 'Faked',
        'X-OneDrop-Geo-Region': 'Faked',
    });

    setLocation(headers, {
        country: 'CA',
        city: 'Montréal',
        latitude: '45.50884',
        longitude: '-73.58781',
    });

    assert.equal(headers.get('X-OneDrop-Geo-Country'), 'CA');
    assert.equal(headers.get('X-OneDrop-Geo-City'), 'Montr%C3%A9al');
    assert.equal(headers.get('X-OneDrop-Geo-Latitude'), '45.50884');
    assert.equal(headers.get('X-OneDrop-Geo-Longitude'), '-73.58781');
    assert.equal(headers.get('X-OneDrop-Geo-Region'), null);

    setLocation(headers, undefined);
    assert.equal(headers.get('X-OneDrop-Geo-Country'), null);
});

test("the app's own gateway pages can be shown in the workspace frame", () => {
    const out = withoutFrameBlock(
        new Response('Reopen', {
            status: 401,
            headers: { 'X-Frame-Options': 'DENY' },
        }),
    );

    assert.equal(out.headers.get('X-Frame-Options'), null);
    assert.equal(out.status, 401);
});

test('a public app is served without a cookie, and the answer is shared for a minute', async () => {
    const { response, sent, store } = await run(
        'https://time-tracker-4.onedrop.io/invoices',
        () =>
            new Response(null, {
                status: 200,
                headers: {
                    'X-OneDrop-Upstream': 'https://abc.preview.bl.run',
                    'X-OneDrop-Upstream-Header': 'X-Blaxel-Preview-Token',
                    'X-OneDrop-Upstream-Token': 'secret-token',
                },
            }),
    );

    assert.equal(response.status, 200);
    // Forwarded to the provider; its links in the answer point back at the app's own address.
    assert.equal(sent[1].url, 'https://abc.preview.bl.run/invoices');
    assert.equal(
        await response.text(),
        'from https://time-tracker-4.onedrop.io/invoices',
    );
    assert.equal(
        sent[0].headers.get('X-OneDrop-Gateway-Host'),
        'time-tracker-4.onedrop.io',
    );
    assert.equal(sent[1].headers.get('X-Blaxel-Preview-Token'), 'secret-token');
    assert.ok(
        store.has(
            'https://gateway-auth.internal/time-tracker-4.onedrop.io/public',
        ),
    );
});

test('the sandbox is told the address the browser used, never one the browser names', async () => {
    const { sent } = await run(
        'https://preview-25.onedrop.io/live/websocket',
        () =>
            new Response(null, {
                status: 200,
                headers: { 'X-OneDrop-Upstream': 'https://abc.runtime.dev' },
            }),
        {
            headers: {
                Origin: 'https://preview-25.onedrop.io',
                'X-OneDrop-Host': 'evil.example.com',
            },
        },
    );

    assert.equal(sent[1].url, 'https://abc.runtime.dev/live/websocket');
    assert.equal(
        sent[1].headers.get('X-OneDrop-Host'),
        'preview-25.onedrop.io',
    );
    assert.equal(
        sent[1].headers.get('Origin'),
        'https://preview-25.onedrop.io',
    );
});

test("a private app's sign-in redirect reaches the browser, and isn't cached", async () => {
    const { response, sent, store } = await run(
        'https://time-tracker-4.onedrop.io/',
        () =>
            new Response(null, {
                status: 302,
                headers: {
                    Location: 'https://onedrop.io/projects/4/open/app?path=%2F',
                },
            }),
    );

    assert.equal(response.status, 302);
    assert.equal(
        response.headers.get('Location'),
        'https://onedrop.io/projects/4/open/app?path=%2F',
    );
    assert.equal(sent.length, 1);
    assert.equal(store.size, 0);
});

test('a name that only looks like a published app goes on to its own origin', async () => {
    const { response, sent } = await run(
        'https://status-2.onedrop.io/',
        () => new Response('Not found', { status: 404 }),
    );

    assert.equal(await response.text(), 'from https://status-2.onedrop.io/');
    assert.equal(sent.length, 2);
});

test('an unknown preview is not found, not passed on', async () => {
    const { response } = await run(
        'https://preview-99.onedrop.io/',
        () => new Response('Not found', { status: 404 }),
    );

    assert.equal(response.status, 404);
});

test("the app's other names are never sent to the gateway", async () => {
    const { response, sent } = await run(
        'https://docs.onedrop.io/install',
        () => {
            throw new Error('asked the app');
        },
    );

    assert.equal(await response.text(), 'from https://docs.onedrop.io/install');
    assert.equal(sent.length, 1);
});
