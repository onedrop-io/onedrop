import { http, router } from '@inertiajs/react';
import type { Page } from '@inertiajs/core';
import {
    openInBrowser,
    openWindow,
    saveDownload,
    setServerOrigin,
} from './native';
import type { Session } from './types';

/**
 * How the web app's own pages (DESK-001) reach the server from the desktop app: its requests, links, images and
 * redirects go to the server the user signed in to, with the app's token, instead of to the page's own origin.
 */

/** The web app's UI cookies the server reads (sidebar, open project, appearance); sent as a header instead. */
const UI_COOKIES = [
    'appearance',
    'sidebar_state',
    'sidebar_width',
    'open_project',
];

/** Paths the app's own origin serves: the development server's modules and the built app's files. */
const OWN_PATHS = ['/@', '/src/', '/node_modules/', '/assets/', '/favicon'];

let current: Session | null = null;

function session(): Session {
    if (!current) {
        throw new Error('Not signed in');
    }

    return current;
}

const COOKIES_KEY = 'onedrop.cookies';

/** The cookies the web app sets, kept in local storage: the app's own origin (tauri://) may not keep cookies. */
function storedCookies(): Record<string, string> {
    try {
        return JSON.parse(localStorage.getItem(COOKIES_KEY) ?? '{}') as Record<
            string,
            string
        >;
    } catch {
        return {};
    }
}

/**
 * `document.cookie` for the web app's own cookies (the sidebar's state, the open project, appearance): set and read
 * as in a browser, kept in local storage. A cookie set with `max-age=0` is removed.
 */
function keepCookies(): void {
    Object.defineProperty(document, 'cookie', {
        configurable: true,
        get: () =>
            Object.entries(storedCookies())
                .map(([name, value]) => `${name}=${value}`)
                .join('; '),
        set: (cookie: string) => {
            const [pair, ...attributes] = cookie.split(';');
            const separator = pair.indexOf('=');
            const name = pair.slice(0, separator).trim();
            const cookies = storedCookies();

            if (
                attributes.some((attribute) =>
                    /^\s*max-age=0\s*$/i.test(attribute),
                )
            ) {
                delete cookies[name];
            } else {
                cookies[name] = pair.slice(separator + 1).trim();
            }

            localStorage.setItem(COOKIES_KEY, JSON.stringify(cookies));
        },
    });
}

/** The UI cookies the web app set, as the header the server turns back into cookies. */
function uiCookies(): string {
    return Object.entries(storedCookies())
        .filter(([name]) => UI_COOKIES.includes(name))
        .map(([name, value]) => `${name}=${value}`)
        .join('; ');
}

/** The headers every request to the server carries. */
export function serverHeaders(): Record<string, string> {
    return {
        Authorization: `Bearer ${session().token}`,
        'X-Onedrop-Cookies': uiCookies(),
    };
}

/**
 * The server's address for a URL the web app made: its paths (`/projects/5`) and the app's own origin are the
 * server's; another origin (a sandbox's preview, GitHub) is left alone, as are the app's own files.
 */
export function toServer(url: string | URL): URL | null {
    const resolved = new URL(url.toString(), window.location.href);
    const server = new URL(session().server);

    if (resolved.origin === server.origin) {
        return resolved;
    }

    if (
        resolved.origin !== window.location.origin ||
        OWN_PATHS.some((path) => resolved.pathname.startsWith(path))
    ) {
        return null;
    }

    return new URL(resolved.pathname + resolved.search + resolved.hash, server);
}

/** A path in the app for a server URL, e.g. `http://server/projects/5?x=1` → `/projects/5?x=1`. */
function appPath(url: URL): string {
    return url.pathname + url.search + url.hash;
}

/** Inertia's requests: to the server, with the token. */
function routeInertiaRequests(): void {
    http.onRequest((config) => {
        const target = toServer(config.url);

        return target
            ? {
                  ...config,
                  url: target.toString(),
                  headers: { ...config.headers, ...serverHeaders() },
              }
            : config;
    });
}

/** The web app's own fetches (its JSON helpers, the tools' APIs): to the server, with the token. */
function routeFetches(): void {
    const fetch = window.fetch.bind(window);

    window.fetch = (input, init) => {
        const url = input instanceof Request ? input.url : input;
        const target = toServer(url);

        if (!target) {
            return fetch(input, init);
        }

        const headers = new Headers(
            input instanceof Request ? input.headers : undefined,
        );

        new Headers(init?.headers).forEach((value, key) =>
            headers.set(key, value),
        );
        Object.entries(serverHeaders()).forEach(([key, value]) =>
            headers.set(key, value),
        );

        return fetch(
            input instanceof Request ? new Request(target, input) : target,
            {
                ...init,
                headers,
                credentials: 'omit',
            },
        );
    };
}

const loaded = new Map<string, Promise<string>>();

/** A server file (a project's icon, an attachment) as a local address `<img>` can show, fetched with the token. */
function localCopy(url: URL): Promise<string> {
    const key = url.toString();

    if (!loaded.has(key)) {
        loaded.set(
            key,
            window
                .fetch(url, { headers: serverHeaders(), credentials: 'omit' })
                .then((response) =>
                    response.ok
                        ? response.blob()
                        : Promise.reject(new Error(String(response.status))),
                )
                .then((blob) => URL.createObjectURL(blob))
                .catch((error: unknown) => {
                    loaded.delete(key);
                    throw error;
                }),
        );
    }

    return loaded.get(key)!;
}

/** A file the server serves as it is (no route behind it), e.g. `/images/logos/claude.svg`. */
const STATIC_FILE = /\.(svg|png|jpe?g|gif|webp|avif|ico)$/i;

/** A transparent pixel, shown while a server image loads with the token. */
const PLACEHOLDER =
    'data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==';

/**
 * Show the server's images, which may need the token (a project's icon, an attachment): their addresses are caught
 * as they're set, so the webview never asks for them without it (and gets sent to the sign-in page).
 */
function loadServerImages(): void {
    const source = Object.getOwnPropertyDescriptor(
        HTMLImageElement.prototype,
        'src',
    )!;
    const setAttribute = Object.getOwnPropertyDescriptor(
        Element.prototype,
        'setAttribute',
    )!.value as (this: Element, name: string, value: string) => void;
    // The address the page asked for, per image, so a later change wins over an earlier one still loading.
    const wanted = new WeakMap<HTMLImageElement, string>();

    const show = (image: HTMLImageElement, value: string): boolean => {
        const target =
            value.startsWith('blob:') || value.startsWith('data:')
                ? null
                : toServer(value);

        if (!target) {
            wanted.delete(image);

            return false;
        }

        // The server's own files (its logos, under /images) need no token: shown straight from it.
        if (STATIC_FILE.test(target.pathname)) {
            wanted.delete(image);
            source.set!.call(image, target.toString());

            return true;
        }

        wanted.set(image, value);
        source.set!.call(image, PLACEHOLDER);
        void localCopy(target)
            .then((local) => {
                if (wanted.get(image) === value) {
                    source.set!.call(image, local);
                }
            })
            .catch(() => {});

        return true;
    };

    Object.defineProperty(HTMLImageElement.prototype, 'src', {
        ...source,
        set(this: HTMLImageElement, value: string) {
            if (!show(this, String(value))) {
                source.set!.call(this, value);
            }
        },
    });

    Element.prototype.setAttribute = function (
        this: Element,
        name: string,
        value: string,
    ) {
        if (
            this instanceof HTMLImageElement &&
            name.toLowerCase() === 'src' &&
            show(this, String(value))
        ) {
            return;
        }

        setAttribute.call(this, name, value);
    };
}

/** The file name a download should get: from the server's Content-Disposition, else the URL. */
function fileName(response: Response, url: URL): string {
    const header = response.headers.get('Content-Disposition') ?? '';
    const match = /filename\*=UTF-8''([^;]+)|filename="?([^";]+)"?/i.exec(
        header,
    );

    return decodeURIComponent(
        match?.[1] ?? match?.[2] ?? url.pathname.split('/').pop() ?? 'download',
    );
}

/** Save a server file to the Downloads folder (the app's window can't follow a download link). */
export async function download(url: URL): Promise<void> {
    const response = await window.fetch(url, {
        headers: serverHeaders(),
        credentials: 'omit',
    });

    if (!response.ok) {
        throw new Error(`Download failed (${response.status})`);
    }

    await saveDownload(
        fileName(response, url),
        new Uint8Array(await response.arrayBuffer()),
    );
}

/** Where a preview or shell link signs in (Gateway::enterUrl() on the server). */
const HAND_OFF_PATH = '/__onedrop/enter';

/**
 * A preview or shell opened in a new tab: in a window of the app's own instead, since the browser may not be signed
 * in to OneDrop, at a hand-off the server makes now (the page's lasts a minute) (DESK-002).
 */
async function openGatewayWindow(link: URL): Promise<void> {
    const response = await window.fetch(
        new URL(
            `/desktop/gateway?${new URLSearchParams({ url: link.href })}`,
            session().server,
        ),
        {
            headers: { ...serverHeaders(), Accept: 'application/json' },
            credentials: 'omit',
        },
    );

    if (!response.ok) {
        throw new Error(`Opening the window failed (${response.status})`);
    }

    const { url } = (await response.json()) as { url: string };

    await openWindow(url, link.hostname);
}

/**
 * Links the page doesn't handle itself: the server's pages open in the app, its files download, previews and shells
 * open in a window of their own, and other sites open in the browser. Inertia's own links handle their clicks first
 * (and say so).
 */
function followLinks(): void {
    document.addEventListener('click', (event) => {
        const link =
            event.target instanceof Element ? event.target.closest('a') : null;
        const href = link?.getAttribute('href');

        if (
            event.defaultPrevented ||
            !link ||
            !href ||
            href.startsWith('#') ||
            event.button !== 0
        ) {
            return;
        }

        const resolved = new URL(href, window.location.href);

        if (
            !['http:', 'https:', window.location.protocol].includes(
                resolved.protocol,
            )
        ) {
            return;
        }

        event.preventDefault();
        const target = toServer(resolved);

        if (
            !target &&
            link.target === '_blank' &&
            resolved.pathname === HAND_OFF_PATH
        ) {
            void openGatewayWindow(resolved).catch(() =>
                openInBrowser(resolved.toString()),
            );
        } else if (!target) {
            void openInBrowser(resolved.toString());
        } else if (link.hasAttribute('download')) {
            void download(target).catch(() => openInBrowser(target.toString()));
        } else if (link.target === '_blank') {
            void openInBrowser(target.toString());
        } else {
            router.visit(appPath(target));
        }
    });
}

/**
 * Files the page makes and "clicks" to save (a project's files as a zip): saved to the Downloads folder, which the
 * app's webview may not do for it.
 */
function saveMadeFiles(): void {
    // The page revokes a file's address right after clicking it, so the file is kept from when the address is made.
    const made = new Map<string, Blob>();
    const createObjectURL = URL.createObjectURL.bind(URL);
    const revokeObjectURL = URL.revokeObjectURL.bind(URL);

    URL.createObjectURL = (object: Blob | MediaSource) => {
        const address = createObjectURL(object);

        if (object instanceof Blob) {
            made.set(address, object);
        }

        return address;
    };
    URL.revokeObjectURL = (address: string) => {
        made.delete(address);
        revokeObjectURL(address);
    };

    const click = Object.getOwnPropertyDescriptor(
        HTMLElement.prototype,
        'click',
    )!.value as (this: HTMLElement) => void;

    HTMLAnchorElement.prototype.click = function (this: HTMLAnchorElement) {
        const file = this.hasAttribute('download')
            ? made.get(this.href)
            : undefined;

        if (!file) {
            return click.call(this);
        }

        void file
            .arrayBuffer()
            .then((bytes) =>
                saveDownload(
                    this.download || 'download',
                    new Uint8Array(bytes),
                ),
            );
    };
}

/**
 * A redirect Inertia would follow by leaving the page (an external site, or a full reload of one of the server's):
 * the server's pages open in the app instead, and other sites in the browser.
 */
function keepRedirectsInApp(): void {
    document.addEventListener('inertia:location', (event) => {
        const { url } = (event as CustomEvent<{ url: URL }>).detail;
        const target = toServer(url);

        event.preventDefault();

        if (target) {
            router.visit(appPath(target));
        } else {
            void openInBrowser(url.toString());
        }
    });
}

/**
 * Signing out: "Log out" signs the app out (its token stops working) instead of showing the server's welcome page;
 * and a page the server sends when the token stopped working (revoked in Settings) does the same.
 */
function watchSignOut(onSignedOut: () => void): void {
    router.on('before', (event) => {
        const { url } = (event as CustomEvent<{ visit: { url: URL } }>).detail
            .visit;

        if (url.pathname === '/logout') {
            event.preventDefault();
            onSignedOut();
        }
    });

    router.on('navigate', (event) => {
        const page = (event as CustomEvent<{ page: Page }>).detail.page;

        if (
            page.component === 'welcome' ||
            page.component.startsWith('auth/login')
        ) {
            onSignedOut();
        }
    });
}

/** Route the web app's traffic to the server; call once, before the app starts. */
export function connectToServer(next: Session, onSignedOut: () => void): void {
    current = next;
    void setServerOrigin(next.server).catch(() => {});
    keepCookies();
    routeInertiaRequests();
    routeFetches();
    loadServerImages();
    followLinks();
    saveMadeFiles();
    keepRedirectsInApp();
    watchSignOut(onSignedOut);
}

/** When the server's release was made (a Unix time), or null when it doesn't say (a checkout, not an image). */
export let serverReleasedAt: number | null = null;

/**
 * The first page to show: the one at the app's address (after a reload), else the user's home. Null when the
 * server no longer takes the token (it sends the sign-in page).
 */
export async function firstPage(): Promise<Page | null> {
    const path =
        window.location.pathname === '/' ||
        window.location.pathname === '/index.html'
            ? '/dashboard'
            : appPath(new URL(window.location.href));
    // As Inertia's own requests do (JSON, not the page's HTML, whose preload links the webview would follow). The
    // first answer is the server's asset version, which the page is then asked for with.
    const visit = (version: string | null) =>
        window.fetch(new URL(path, session().server), {
            headers: {
                ...serverHeaders(),
                Accept: 'text/html, application/xhtml+xml',
                'X-Inertia': 'true',
                ...(version !== null ? { 'X-Inertia-Version': version } : {}),
            },
            credentials: 'omit',
        });

    let response = await visit(null);

    if (response.status === 409) {
        response = await visit(response.headers.get('X-Inertia-Version') ?? '');
    }

    serverReleasedAt =
        Number(response.headers.get('X-Onedrop-Released')) || null;

    if (!response.headers.has('X-Inertia')) {
        throw new Error(`The server sent no page (${response.status}).`);
    }

    const page = (await response.json()) as Page;

    return page.component.startsWith('auth/login') ||
        page.component === 'welcome'
        ? null
        : page;
}
