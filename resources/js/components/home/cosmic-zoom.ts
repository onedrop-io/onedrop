/**
 * The home page's zoom levels, from Earth out to Laniakea. The galaxy is the
 * usual view; a trackpad pinch or the scroll wheel over it steps in to the
 * Solar System and Earth, or out to Laniakea (at either end the wheel goes
 * back to scrolling the page), and so does the scale control beside it.
 * Every so often, left alone, it drifts out to Laniakea by itself for a
 * while and comes back.
 *
 * The galaxy owns the drawing; this keeps track of which level is showing,
 * how far along a change is, and tells the scale control (`cosmic-zoom`
 * events on `window`) so it can highlight the current level.
 */

import { clamp, smoothstep } from '@/components/home/closeup-gl';
import {
    EASTER_EGG_FOR_ZOOM,
    findEasterEgg,
} from '@/components/home/easter-eggs';

export const ZOOM_LEVELS = ['earth', 'solar', 'galaxy', 'laniakea'] as const;

export type ZoomLevel = (typeof ZOOM_LEVELS)[number];

export const ZOOM_LEVEL_NAMES: Record<ZoomLevel, string> = {
    earth: 'Earth',
    solar: 'Solar System',
    galaxy: 'Milky Way',
    laniakea: 'Laniakea',
};

/** Tells the scale control which level is showing, and whether zooming is available at all. */
export type ZoomEvent = CustomEvent<{
    level: ZoomLevel;
    isAvailable: boolean;
}>;

/** The scale control asking for a level. */
export type ZoomRequest = CustomEvent<{ level: ZoomLevel }>;

/** How long a change of level takes (milliseconds). */
const CHANGE_MS = 1800;

/** How much wheel it takes to step a level (pixels of scrolling; a pinch sends much smaller steps), and how long before the next step. */
const WHEEL_STEP = 60;
const PINCH_STEP = 25;
const WHEEL_PAUSE_MS = 900;

/** Left alone at the galaxy, how long before drifting out to Laniakea, and how long it stays (milliseconds). */
const AUTO_TOUR_EVERY_MS = 95_000;
const AUTO_TOUR_STAYS_MS = 16_000;

export function createCosmicZoom({
    isInZoomArea,
    canChange,
    interrupt,
    prepare,
    isReady,
}: {
    /** Whether a point on screen is over the galaxy (where the wheel zooms). */
    isInZoomArea: (x: number, y: number) => boolean;
    /** Whether it can change on its own right now (nothing else is playing). */
    canChange: () => boolean;
    /** Stops whatever else is playing, when someone asks for another level: they shouldn't have to wait. */
    interrupt: () => void;
    /** Starts loading what a level needs, and whether it's in yet. */
    prepare: (level: ZoomLevel) => void;
    isReady: (level: ZoomLevel) => boolean;
}) {
    let level: ZoomLevel = 'galaxy';
    let from: ZoomLevel = 'galaxy';
    let changedAt = -Infinity;
    let pending: ZoomLevel | null = null;
    let wheel = 0;
    let wheelPausedUntil = 0;
    let lastTouchedAt = performance.now();
    let autoReturnAt: number | null = null;
    let isAvailable = false;

    const announce = () => {
        window.dispatchEvent(
            new CustomEvent('cosmic-zoom', {
                detail: { level: pending ?? level, isAvailable },
            }),
        );
    };

    const change = (next: ZoomLevel, now: number) => {
        if (next === level && !pending) {
            return;
        }

        prepare(next);

        if (!isReady(next)) {
            pending = next;
            announce();

            return;
        }

        pending = null;
        from = level;
        level = next;
        changedAt = now;
        announce();

        const easterEgg = EASTER_EGG_FOR_ZOOM[next];

        if (easterEgg) {
            findEasterEgg(easterEgg);
        }
    };

    const request = (next: ZoomLevel, now: number) => {
        lastTouchedAt = now;
        autoReturnAt = null;
        interrupt();
        change(next, now);
    };

    const onWheel = (event: WheelEvent) => {
        if (!isAvailable || !isInZoomArea(event.clientX, event.clientY)) {
            return;
        }

        const now = performance.now();
        const outward = event.deltaY > 0;
        const index = ZOOM_LEVELS.indexOf(pending ?? level);
        const next = ZOOM_LEVELS[index + (outward ? 1 : -1)];
        const isSettling =
            pending !== null ||
            now - changedAt < CHANGE_MS ||
            now < wheelPausedUntil;

        // At either end, once it's settled, let the wheel scroll the page again.
        if (!next) {
            if (isSettling) {
                event.preventDefault();
            }

            return;
        }

        event.preventDefault();
        prepare(next);

        if (now < wheelPausedUntil) {
            return;
        }

        wheel += Math.abs(event.deltaY) * (event.deltaMode === 1 ? 30 : 1);

        if (wheel < (event.ctrlKey ? PINCH_STEP : WHEEL_STEP)) {
            return;
        }

        wheel = 0;
        wheelPausedUntil = now + WHEEL_PAUSE_MS;
        request(next, now);
    };

    const onRequest = (event: Event) => {
        request((event as ZoomRequest).detail.level, performance.now());
    };

    window.addEventListener('wheel', onWheel, { passive: false });
    window.addEventListener('cosmic-zoom-request', onRequest);

    return {
        /**
         * Moves things along: starts a pending level once it's ready, and runs
         * the occasional trip out to Laniakea. `available` is whether the
         * galaxy is showing at all.
         */
        tick(now: number, available: boolean) {
            if (available !== isAvailable) {
                isAvailable = available;
                announce();
            }

            if (!available) {
                return;
            }

            if (pending && isReady(pending) && canChange()) {
                change(pending, now);
            }

            const isSettled = now - changedAt > CHANGE_MS;

            if (autoReturnAt !== null && now > autoReturnAt && isSettled) {
                autoReturnAt = null;
                lastTouchedAt = now;
                change('galaxy', now);
            } else if (
                level === 'galaxy' &&
                isSettled &&
                autoReturnAt === null &&
                now - lastTouchedAt > AUTO_TOUR_EVERY_MS - 10_000
            ) {
                prepare('laniakea');

                if (
                    now - lastTouchedAt > AUTO_TOUR_EVERY_MS &&
                    canChange() &&
                    isReady('laniakea')
                ) {
                    change('laniakea', now);
                    autoReturnAt = now + AUTO_TOUR_STAYS_MS;
                }
            }
        },

        /** How much of each level is showing right now, 0 to 1 (they add up to 1). */
        weights(now: number): Record<ZoomLevel, number> {
            const progress = smoothstep(
                0,
                1,
                clamp((now - changedAt) / CHANGE_MS),
            );
            const weights = { earth: 0, solar: 0, galaxy: 0, laniakea: 0 };
            weights[from] += 1 - progress;
            weights[level] += progress;

            return weights;
        },

        /** The level showing (or being changed to), and the one it's coming from. */
        get level() {
            return level;
        },

        get from() {
            return from;
        },

        /** Whether it's settled on the galaxy, the usual view (so planets and probes can be hovered). */
        isAtGalaxy(now: number) {
            return level === 'galaxy' && now - changedAt > CHANGE_MS;
        },

        /** Whether it's settled on the Solar System (so its planets can be hovered). */
        isAtSolar(now: number) {
            return level === 'solar' && now - changedAt > CHANGE_MS;
        },

        destroy() {
            window.removeEventListener('wheel', onWheel);
            window.removeEventListener('cosmic-zoom-request', onRequest);
        },
    };
}
