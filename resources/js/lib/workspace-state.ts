import type { BrowserSession } from '@/components/workspace/browser-view';
import type { Layout, ShellTab } from '@/components/workspace/panes';

/**
 * What a project's workspace had open, so reloading the page comes back to it (LAYOUT-005). Kept in
 * `sessionStorage`: it survives a reload of this browser tab, but a new tab starts afresh with new Shells,
 * rather than attaching to (and typing into) the other tab's.
 */
export type SavedWorkspace = {
    layout: Layout;
    /** Each open Shell's tmux session (see docker/sandbox/shell-entry) and the folder it started in. */
    shells: Partial<Record<ShellTab, SavedShell>>;
    openPath: string | null;
    tool: string | null;
    recentFiles: string[];
    fileSearch: { folder: string; query: string };
    testsFocus: string | null;
    /** The test page taken over in the Browser tab (TEST-005). */
    browserSession: BrowserSession | null;
    /** The app's page the preview was on, e.g. `/settings?tab=billing`. */
    previewPage: string | null;
};

export type SavedShell = { session: string; folder: string | null };

/** A chat's half-typed message and where it was scrolled to. */
export type SavedChat = { draft: string; scrollTop: number | null };

function read<T>(key: string): Partial<T> | null {
    try {
        const value: unknown = JSON.parse(
            window.sessionStorage.getItem(key) ?? 'null',
        );

        return value && typeof value === 'object'
            ? (value as Partial<T>)
            : null;
    } catch {
        return null;
    }
}

function write(key: string, value: unknown): void {
    try {
        window.sessionStorage.setItem(key, JSON.stringify(value));
    } catch {
        // Storage full or blocked: the page still works, it just won't come back after a reload.
    }
}

const workspaceKey = (projectId: number) => `onedrop.workspace.${projectId}`;

/** What was saved for the project in this tab; each field still needs checking before use. */
export function loadWorkspace(
    projectId: number,
): Partial<SavedWorkspace> | null {
    return read<SavedWorkspace>(workspaceKey(projectId));
}

export function saveWorkspace(projectId: number, state: SavedWorkspace): void {
    write(workspaceKey(projectId), state);
}

const chatKey = (projectId: number, chat: string) =>
    `onedrop.chat.${projectId}.${chat}`;

export function loadChat(projectId: number, chat: string): SavedChat {
    const saved = read<SavedChat>(chatKey(projectId, chat));

    return {
        draft: typeof saved?.draft === 'string' ? saved.draft : '',
        scrollTop:
            typeof saved?.scrollTop === 'number' ? saved.scrollTop : null,
    };
}

export function saveChat(
    projectId: number,
    chat: string,
    state: SavedChat,
): void {
    write(chatKey(projectId, chat), state);
}

/** A new Shell's tmux session name: random, so it's never another tab's. */
export function newShellSession(): string {
    return Array.from(crypto.getRandomValues(new Uint8Array(8)), (byte) =>
        byte.toString(16).padStart(2, '0'),
    ).join('');
}

/** A page path the preview may be put back on: same-site only, like the gateway's `path`. */
export function isPagePath(value: unknown): value is string {
    return (
        typeof value === 'string' &&
        value.startsWith('/') &&
        !value.startsWith('//') &&
        !value.includes('\\') &&
        value.length <= 2000
    );
}

/**
 * The preview's address on one of the app's pages. Through the gateway, the page goes in its `path`;
 * otherwise it replaces the address's path, keeping its query (e.g. a provider's preview token).
 */
export function previewUrlAt(
    url: string,
    page: string | null,
    viaGateway: boolean,
): string {
    if (!page || page === '/' || !isPagePath(page)) {
        return url;
    }

    const base = new URL(url, window.location.origin);

    if (viaGateway) {
        base.searchParams.set('path', page);

        return base.href;
    }

    const target = new URL(page, base.origin);
    base.searchParams.forEach(
        (value, key) =>
            !target.searchParams.has(key) &&
            target.searchParams.append(key, value),
    );

    return target.href;
}
