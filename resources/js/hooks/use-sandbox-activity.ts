import { useEffect, useRef } from 'react';
import SandboxActivityController from '@/actions/App/Http/Controllers/SandboxActivityController';
import { jsonRequest } from '@/lib/json-request';

// Well within the minute an unused sandbox is allowed.
const EVERY_MS = 20_000;

// Clicking, typing or scrolling pings at most this often.
const ACTIVITY_MS = 5_000;

// Using the page. Clicks inside the preview or shell frame never reach it, but focus moving into the frame does
// (the window blurs with the frame as the active element).
const ACTIVITY_EVENTS = ['pointerdown', 'keydown', 'wheel'] as const;

/**
 * While the workspace is visible, tell the app its sandbox is in use, so it isn't suspended for sitting idle, and
 * wake it straight away when the tab comes back or the page is used (SBX-007). `task` is a task whose own copy of
 * the app is shown.
 *
 * @param onReturn Called when the tab comes back, or when using the page woke the sandbox; `woke` says it had been
 * asleep (its preview is stale).
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

        const ping = async (): Promise<boolean> => {
            lastPing = Date.now();

            const { woke } = await jsonRequest<{ woke: boolean }>(
                SandboxActivityController.store.url(projectId),
                task ? { task } : {},
            );

            return woke;
        };

        const onTimer = () => {
            if (document.visibilityState === 'visible') {
                void ping().catch(() => {});
            }
        };

        const onVisible = () => {
            if (document.visibilityState === 'visible') {
                ping()
                    .then((woke) => latest.current(woke))
                    .catch(() => latest.current(false));
            }
        };

        const onActivity = () => {
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
        window.addEventListener('focus', onActivity);
        window.addEventListener('blur', onBlur);

        return () => {
            window.clearInterval(timer);
            document.removeEventListener('visibilitychange', onVisible);
            ACTIVITY_EVENTS.forEach((event) =>
                document.removeEventListener(event, onActivity),
            );
            window.removeEventListener('focus', onActivity);
            window.removeEventListener('blur', onBlur);
        };
    }, [projectId, task]);
}
