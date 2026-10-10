import { useEffect, useRef } from 'react';
import SandboxActivityController from '@/actions/App/Http/Controllers/SandboxActivityController';
import { jsonRequest } from '@/lib/json-request';

// Well within the minute an unused sandbox is allowed.
const EVERY_MS = 20_000;

// Clicking, typing or scrolling pings at most this often.
const ACTIVITY_MS = 5_000;

// A visible page nobody has touched for this long (a window left open) stops keeping the sandbox awake.
const UNUSED_MS = 15 * 60_000;

// Using the page. Clicks inside the preview or shell frame never reach it, but focus moving into the frame does
// (the window blurs with the frame as the active element).
const ACTIVITY_EVENTS = ['pointerdown', 'keydown', 'wheel'] as const;

/**
 * While the workspace is visible and used, tell the app its sandbox is in use, so it isn't suspended for sitting
 * idle, and wake it straight away when the tab comes back or the page is used (SBX-007). A visible page left
 * untouched for 15 minutes stops counting. When the page is hidden, closed or left untouched, it says so, so the
 * sandbox can pause within a minute (SBX-014). `task` is a task whose own copy of the app is shown.
 *
 * @param onReturn Called when the tab comes back, or when using the page or the open workspace's own ping woke the
 * sandbox; `woke` says it had been asleep (its preview is stale).
 */
export function useSandboxActivity(
    projectId: number,
    task: number | null,
    onReturn: (woke: boolean) => void,
): void {
    const latest = useRef(onReturn);

    useEffect(() => {
        latest.current = onReturn;
    });

    useEffect(() => {
        let lastPing = 0;
        let lastUsed = Date.now();
        let gone = false;

        // Typing in the preview or shell frame never reaches the page, so a focused frame counts as use.
        const inUse = () =>
            Date.now() - lastUsed < UNUSED_MS ||
            (document.hasFocus() &&
                document.activeElement instanceof HTMLIFrameElement);

        const ping = async (): Promise<boolean> => {
            lastPing = Date.now();
            gone = false;

            const { woke } = await jsonRequest<{ woke: boolean }>(
                SandboxActivityController.store.url(projectId),
                task ? { task } : {},
            );

            return woke;
        };

        // Nobody's watching from here any more (once, until the next ping).
        const leave = () => {
            if (gone) {
                return;
            }

            gone = true;
            void jsonRequest(
                SandboxActivityController.store.url(projectId),
                { ...(task ? { task } : {}), left: true },
                'POST',
                { keepalive: true },
            ).catch(() => {});
        };

        // A sandbox can fall asleep with the tab still visible (the computer slept): the ping that wakes it reloads the
        // preview too.
        const onTimer = () => {
            if (document.visibilityState !== 'visible') {
                return;
            }

            if (!inUse()) {
                leave();

                return;
            }

            ping()
                .then((woke) => {
                    if (woke) {
                        latest.current(true);
                    }
                })
                .catch(() => {});
        };

        const onVisible = () => {
            if (document.visibilityState === 'hidden') {
                leave();
            }

            if (document.visibilityState === 'visible') {
                lastUsed = Date.now();
                ping()
                    .then((woke) => latest.current(woke))
                    .catch(() => latest.current(false));
            }
        };

        const onActivity = () => {
            lastUsed = Date.now();

            if (Date.now() - lastPing < ACTIVITY_MS) {
                return;
            }

            ping()
                .then((woke) => {
                    if (woke) {
                        latest.current(true);
                    }
                })
                .catch(() => {});
        };

        // Moving the mouse over the page counts as use, without a ping of its own.
        const onMove = () => {
            lastUsed = Date.now();
        };

        const onBlur = () => {
            // The window blurs a moment before the frame becomes the active element.
            window.setTimeout(() => {
                if (document.activeElement instanceof HTMLIFrameElement) {
                    onActivity();
                }
            });
        };

        const timer = window.setInterval(onTimer, EVERY_MS);
        document.addEventListener('visibilitychange', onVisible);
        ACTIVITY_EVENTS.forEach((event) =>
            document.addEventListener(event, onActivity, { passive: true }),
        );
        document.addEventListener('pointermove', onMove, { passive: true });
        window.addEventListener('focus', onActivity);
        window.addEventListener('blur', onBlur);
        window.addEventListener('pagehide', leave);

        return () => {
            window.clearInterval(timer);
            document.removeEventListener('visibilitychange', onVisible);
            ACTIVITY_EVENTS.forEach((event) =>
                document.removeEventListener(event, onActivity),
            );
            document.removeEventListener('pointermove', onMove);
            window.removeEventListener('focus', onActivity);
            window.removeEventListener('blur', onBlur);
            window.removeEventListener('pagehide', leave);
            leave();
        };
    }, [projectId, task]);
}
