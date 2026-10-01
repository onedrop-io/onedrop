import { useSyncExternalStore } from 'react';
import type { AttachedFile } from '@/components/prompt-composer';

/**
 * What the workspace shows on the right (TASK-004): its tab, the Tools section, and the open file.
 * It lives in the URL (`?tab=tools&tool=database`, `?tab=file&file=src/App.tsx`), so a reload or a link keeps
 * it, and links between a project's pages (Main, its tasks, the board) carry it along: switching tasks keeps
 * the Database tool open on each.
 */
export type WorkspaceView = {
    tab: string | null;
    tool: string | null;
    file: string | null;
};

const PARAMS = ['tab', 'tool', 'file'] as const;
const EMPTY: WorkspaceView = { tab: null, tool: null, file: null };

let current: { projectId: number | null; view: WorkspaceView } = {
    projectId: null,
    view: EMPTY,
};
const listeners = new Set<() => void>();

/** The view a URL asks for. */
export function viewFromUrl(url: string): WorkspaceView {
    const params = new URL(url, 'http://localhost').searchParams;

    return {
        tab: params.get('tab'),
        tool: params.get('tool'),
        file: params.get('file'),
    };
}

/**
 * The workspace changed what it shows: remember it for links, and put it in the address bar
 * (replacing, not adding, a history entry). The preview is the default, so it adds nothing.
 */
export function setWorkspaceView(projectId: number, view: WorkspaceView): void {
    const next: WorkspaceView = {
        tab: view.tab === 'preview' ? null : view.tab,
        tool: view.tab === 'tools' ? view.tool : null,
        file: view.tab === 'file' ? view.file : null,
    };

    current = { projectId, view: next };
    listeners.forEach((listener) => listener());

    if (typeof window === 'undefined') {
        return;
    }

    const url = new URL(window.location.href);

    PARAMS.forEach((param) =>
        next[param]
            ? url.searchParams.set(param, next[param])
            : url.searchParams.delete(param),
    );

    if (url.href !== window.location.href) {
        window.history.replaceState(window.history.state, '', url.href);
    }
}

function subscribe(listener: () => void): () => void {
    listeners.add(listener);

    return () => listeners.delete(listener);
}

/**
 * Adds the workspace view the user has open to links to a project's pages, so the page a link leads to shows
 * the same tab and tool. Links to other projects are left alone.
 */
export function useWorkspaceLinks(): (
    projectId: number,
    href: string,
) => string {
    const state = useSyncExternalStore(
        subscribe,
        () => current,
        () => current,
    );

    return (projectId, href) =>
        state.projectId === projectId ? withView(href, state.view) : href;
}

function withView(href: string, view: WorkspaceView): string {
    const url = new URL(href, 'http://localhost');

    PARAMS.forEach((param) => {
        if (view[param]) {
            url.searchParams.set(param, view[param]);
        }
    });

    return href.startsWith('http') ? url.href : `${url.pathname}${url.search}`;
}

const OPEN_TOOL_EVENT = 'workspace:open-tool';

/** Show a Tools section in the workspace from outside it (e.g. the header's git menu opening Tools → Git). */
export function openWorkspaceTool(tool: string): void {
    window.dispatchEvent(new CustomEvent(OPEN_TOOL_EVENT, { detail: tool }));
}

/** Calls `open` with the section whenever something asks the workspace to show a Tools section. */
export function onOpenWorkspaceTool(open: (tool: string) => void): () => void {
    const listener = (event: Event) =>
        open((event as CustomEvent<string>).detail);

    window.addEventListener(OPEN_TOOL_EVENT, listener);

    return () => window.removeEventListener(OPEN_TOOL_EVENT, listener);
}

const ASK_AGENT_EVENT = 'workspace:ask-agent';

type AskAgent = { text: string; files: AttachedFile[] };

/**
 * Put text in the chat box, ready for the user's question (e.g. a hunk of a diff, GIT-011), with any files
 * attached (e.g. a marked-up picture of the preview, AGT-013).
 */
export function askAgent(text: string, files: AttachedFile[] = []): void {
    window.dispatchEvent(
        new CustomEvent<AskAgent>(ASK_AGENT_EVENT, { detail: { text, files } }),
    );
}

/** Calls `ask` with the text and files whenever something asks to put them in the chat box. */
export function onAskAgent(
    ask: (text: string, files: AttachedFile[]) => void,
): () => void {
    const listener = (event: Event) => {
        const { text, files } = (event as CustomEvent<AskAgent>).detail;

        ask(text, files);
    };

    window.addEventListener(ASK_AGENT_EVENT, listener);

    return () => window.removeEventListener(ASK_AGENT_EVENT, listener);
}
