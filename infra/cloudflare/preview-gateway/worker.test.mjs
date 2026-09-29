// Run with: node --test infra/cloudflare/preview-gateway/worker.test.mjs
import assert from 'node:assert/strict';
import test from 'node:test';
import {
    pointAtGateway,
    readCookie,
    withoutCookie,
    withoutFrameBlock,
} from './worker.js';

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
        readCookie('a=1; zap_gateway=abc=; b=2', 'zap_gateway'),
        'abc=',
    );
    assert.equal(readCookie('a=1', 'zap_gateway'), null);
    assert.equal(
        withoutCookie('a=1; zap_gateway=abc; b=2', 'zap_gateway'),
        'a=1; b=2',
    );
    assert.equal(withoutCookie('zap_gateway=abc', 'zap_gateway'), '');
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
