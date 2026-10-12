import { router } from '@inertiajs/react';

type Visit = {
    url: URL;
    method: string;
    only: string[];
    except: string[];
    async: boolean;
    prefetch: boolean;
};

/**
 * Whether a visit goes to another page, not a reload of this one: no partial reloads (the sidebar's, the
 * workspace's), prefetches or background requests, and not just a new query string (`?tool=git`).
 */
export function isPageVisit(visit: Visit): boolean {
    return (
        visit.method === 'get' &&
        !visit.prefetch &&
        !visit.async &&
        visit.only.length === 0 &&
        visit.except.length === 0 &&
        visit.url.pathname !== window.location.pathname
    );
}

/** Calls `onStart` when a visit to another page starts and `onFinish` when it's done (or failed); returns an unsubscribe. */
export function onPageVisit(
    onStart: (url: URL) => void,
    onFinish: () => void,
): () => void {
    // The page visit under way, so a background reload finishing meanwhile doesn't end it.
    let current: string | null = null;

    const stopStart = router.on('start', (event) => {
        if (isPageVisit(event.detail.visit)) {
            current = event.detail.visit.url.href;
            onStart(event.detail.visit.url);
        }
    });
    const stopFinish = router.on('finish', (event) => {
        // A visit cut short by the next one: that one's start takes over.
        if (
            event.detail.visit.url.href === current &&
            !event.detail.visit.interrupted
        ) {
            current = null;
            onFinish();
        }
    });

    return () => {
        stopStart();
        stopFinish();
    };
}
