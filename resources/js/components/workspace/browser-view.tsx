import { AppWindow, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import ProjectBrowserController from '@/actions/App/Http/Controllers/ProjectBrowserController';
import { Notice } from '@/components/workspace/console-view';
import { jsonRequest } from '@/lib/json-request';

/** A browser the user took over from a test (TEST-005): where its viewer is, and where it was stopped. */
export type BrowserSession = {
    url: string;
    test: string;
    step: string | null;
};

/** A taken-over test: paused at a step, playing on, or at its end (TEST-006). */
type Playback = {
    paused: boolean;
    playing: boolean;
    step: number;
    ended: boolean;
    error: string | null;
};

type BrowserStatus = {
    open: boolean;
    starting: boolean;
    error: string | null;
    url: string | null;
    title: string | null;
};

/**
 * The Browser tab: a live page reached by running a test up to a step, streamed from the sandbox. The user clicks
 * and types in it while chatting with the agent, whose changes arrive through hot reload.
 */
export default function BrowserView({
    projectId,
    session,
    onClose,
}: {
    projectId: number;
    session: BrowserSession | null;
    onClose: () => void;
}) {
    const [status, setStatus] = useState<BrowserStatus | null>(null);
    const [page, setPage] = useState<{ url: string; title: string } | null>(
        null,
    );
    /** Where the test is since the user took over (TEST-006), from the viewer. */
    const [playback, setPlayback] = useState<Playback | null>(null);
    const ready = status?.open ?? false;
    const frame = useRef<HTMLIFrameElement>(null);

    // Check on it until the test has reached its step (or couldn't).
    useEffect(() => {
        if (!session) {
            return;
        }

        let cancelled = false;
        let timer = 0;

        const check = async () => {
            const next = await jsonRequest<BrowserStatus>(
                ProjectBrowserController.show.url(projectId),
            ).catch((e: Error): BrowserStatus => ({
                open: false,
                starting: false,
                error: e.message,
                url: null,
                title: null,
            }));

            if (cancelled) {
                return;
            }

            setStatus(next);

            if (next.starting) {
                timer = window.setTimeout(() => void check(), 1000);
            }
        };

        setStatus(null);
        setPlayback(null);
        void check();

        return () => {
            cancelled = true;
            window.clearTimeout(timer);
        };
    }, [projectId, session]);

    // The viewer says which page it's on.
    useEffect(() => {
        const listener = (event: MessageEvent) => {
            if (event.source !== frame.current?.contentWindow) {
                return;
            }

            if (event.data?.type === 'onedrop-browser') {
                setPage({ url: event.data.url, title: event.data.title });
            }

            if (event.data?.type === 'onedrop-browser-playback') {
                setPlayback(event.data as Playback);
            }
        };

        window.addEventListener('message', listener);

        return () => window.removeEventListener('message', listener);
    }, []);

    if (!session) {
        return (
            <Notice>
                Open the Tests tab, pick a test, and choose "Take over" on one
                of its steps: the test runs up to there and its page opens here
                for you to use.
            </Notice>
        );
    }

    return (
        <div className="flex min-h-0 flex-1 flex-col" data-test="browser-view">
            <div className="flex items-center gap-3 border-b border-sidebar-border/70 px-4 py-1.5 text-xs text-muted-foreground dark:border-sidebar-border">
                <p
                    className="min-w-0 flex-1 truncate"
                    data-test="browser-where"
                >
                    {`"${session.test}"`}
                    {' · '}
                    {playback === null
                        ? session.step
                            ? `stopped after "${session.step}"`
                            : 'stopped before its first step'
                        : playback.error
                          ? 'failed'
                          : playback.playing
                            ? 'playing'
                            : playback.ended
                              ? 'finished'
                              : `paused after step ${playback.step}`}
                    {page?.title ? ` · ${page.title}` : ''}
                </p>
                {ready && (
                    <button
                        type="button"
                        onClick={() =>
                            window.open(
                                session.url,
                                `onedrop-browser-${projectId}`,
                                `popup,width=${Math.round(screen.availWidth * 0.9)},height=${Math.round(screen.availHeight * 0.9)}`,
                            )
                        }
                        title="Open this page in a bigger window (it stays the same page: use one or the other)"
                        className="inline-flex items-center gap-1 rounded px-1.5 py-0.5 hover:bg-muted hover:text-foreground"
                    >
                        <AppWindow className="size-3.5" />
                        Bigger window
                    </button>
                )}
                <button
                    type="button"
                    onClick={onClose}
                    data-test="browser-close"
                    className="inline-flex items-center gap-1 rounded px-1.5 py-0.5 hover:bg-muted hover:text-foreground"
                >
                    <X className="size-3.5" />
                    Close browser
                </button>
            </div>
            {status?.error ? (
                <pre
                    className="m-4 overflow-auto rounded-md bg-red-500/10 p-3 font-mono text-xs whitespace-pre-wrap text-red-700 dark:text-red-300"
                    data-test="browser-error"
                >
                    {status.error}
                </pre>
            ) : ready ? (
                <iframe
                    ref={frame}
                    src={session.url}
                    title="Browser"
                    className="flex-1 bg-neutral-950"
                    data-test="browser-frame"
                />
            ) : status && !status.starting ? (
                <Notice>
                    The browser has closed. Take over a test's step again from
                    the Tests tab.
                </Notice>
            ) : (
                <div
                    className="flex flex-1 items-center justify-center text-sm text-muted-foreground"
                    data-test="browser-starting"
                >
                    Running the test up to that step…
                </div>
            )}
        </div>
    );
}

/** Take over a test after one of its steps (0: before the first), then show it with `open`. */
export async function takeOver(
    projectId: number,
    target: string,
    step: number,
    test: string,
    stepTitle: string | null,
): Promise<BrowserSession> {
    const { url } = await jsonRequest<{ url: string }>(
        ProjectBrowserController.store.url(projectId),
        { target, step, test, step_title: stepTitle },
        'POST',
    );

    return { url, test, step: stepTitle };
}

/** Close the browser (the sandbox's side; the tab closes itself). */
export function closeBrowser(projectId: number): Promise<unknown> {
    return jsonRequest(
        ProjectBrowserController.destroy.url(projectId),
        {},
        'DELETE',
    ).catch(() => null);
}
