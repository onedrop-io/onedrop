import { router } from '@inertiajs/react';

/** Tells the server a reload came from a tab the user isn't looking at, so it doesn't mark replies read (PRJ-008). */
export const UNSEEN_HEADER = 'X-Onedrop-Unseen';

/** Whether the user is looking at this tab right now. */
export function isTabSeen(): boolean {
    return document.visibilityState === 'visible' && document.hasFocus();
}

/**
 * Background reloads (polling, realtime refreshes) from an unfocused tab leave the open chat unread,
 * so a finished agent still shows as waiting when the user comes back. Visits they start always count as seen.
 */
export function markUnseenReloads(): void {
    router.on('before', (event) => {
        const visit = event.detail.visit;
        const partial = visit.only.length > 0 || visit.except.length > 0;

        if (partial && !isTabSeen()) {
            visit.headers = { ...visit.headers, [UNSEEN_HEADER]: '1' };
        }
    });
}
