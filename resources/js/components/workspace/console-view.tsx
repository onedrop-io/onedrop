import { useEffect, useRef, useState } from 'react';
import ProjectLogController from '@/actions/App/Http/Controllers/ProjectLogController';

// eslint-disable-next-line no-control-regex
const ANSI = /\x1b\[[0-9;?]*[ -/]*[@-~]/g;

/** Keep the view light: only the most recent output is kept on screen. */
const MAX_CHARS = 200_000;

/**
 * Follows the app dev server's output while visible.
 */
export default function ConsoleView({
    projectId,
    active,
    running,
    clearSignal,
}: {
    projectId: number;
    active: boolean;
    running: boolean;
    /** Increment to clear the view. */
    clearSignal: number;
}) {
    const [output, setOutput] = useState('');
    const [error, setError] = useState<string | null>(null);
    const offset = useRef<number | null>(null);
    const scroller = useRef<HTMLPreElement>(null);

    const [lastClear, setLastClear] = useState(clearSignal);

    if (lastClear !== clearSignal) {
        setLastClear(clearSignal);
        setOutput('');
    }

    useEffect(() => {
        if (!active || !running) {
            return;
        }

        let cancelled = false;

        const poll = async () => {
            const query =
                offset.current === null ? {} : { offset: offset.current };
            const response = await fetch(
                ProjectLogController.index.url(projectId, { query }),
                {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                },
            ).catch(() => null);

            if (cancelled || !response) {
                return;
            }

            const body = await response.json().catch(() => ({}));

            if (!response.ok) {
                setError(body.message ?? 'Could not read the log.');

                return;
            }

            setError(null);
            offset.current = body.offset;

            if (body.content) {
                const el = scroller.current;
                const atBottom =
                    !el ||
                    el.scrollHeight - el.scrollTop - el.clientHeight < 40;

                setOutput((current) =>
                    (current + body.content.replace(ANSI, '')).slice(
                        -MAX_CHARS,
                    ),
                );

                if (atBottom) {
                    requestAnimationFrame(() => {
                        if (scroller.current) {
                            scroller.current.scrollTop =
                                scroller.current.scrollHeight;
                        }
                    });
                }
            }
        };

        void poll();
        const timer = window.setInterval(() => void poll(), 1000);

        return () => {
            cancelled = true;
            window.clearInterval(timer);
        };
    }, [active, running, projectId]);

    if (!running) {
        return <Notice>The console starts when the sandbox is running.</Notice>;
    }

    return (
        <pre
            ref={scroller}
            className="flex-1 overflow-auto bg-neutral-950 p-3 font-mono text-xs leading-5 whitespace-pre-wrap text-neutral-200"
            data-test="console-output"
        >
            {error ? (
                <span className="text-red-400">{error}</span>
            ) : (
                output || (
                    <span className="text-neutral-500">No output yet.</span>
                )
            )}
        </pre>
    );
}

export function Notice({ children }: { children: React.ReactNode }) {
    return (
        <p className="p-4 text-sm text-muted-foreground" data-test="tab-notice">
            {children}
        </p>
    );
}
