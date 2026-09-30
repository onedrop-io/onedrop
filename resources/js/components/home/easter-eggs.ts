/**
 * The home page's easter eggs, and Droppy's hints for finding them. The
 * galaxy, the zoom and the header logo call `findEasterEgg` when a visitor
 * sets one off; it's remembered in this browser (`localStorage`) and
 * announced with an `easter-egg` event on `window` so Droppy can cheer and
 * move on to the next hint.
 */

import type { ZoomLevel } from '@/components/home/cosmic-zoom';

export type EasterEggId =
    | 'earth'
    | 'moonwalk'
    | 'mars'
    | 'voyager'
    | 'pioneer'
    | 'solar'
    | 'starship'
    | 'laniakea'
    | 'logo'
    | 'endurance'
    | 'enterprise';

export type EasterEgg = {
    id: EasterEggId;
    /** What Droppy calls it once it's found. */
    name: string;
    hint: string;
    /** A zoom level Droppy can take the visitor to. */
    zoomTo?: ZoomLevel;
    /** A hover target in the galaxy (a `data-closeup` spot) Droppy can point at. */
    pointAt?: string;
    /** Whether the page can tell it's been found. The rest just play on their own, so Droppy only mentions them. */
    isTracked: boolean;
};

export const EASTER_EGGS: EasterEgg[] = [
    {
        id: 'earth',
        name: 'the Earth up close',
        hint: 'See the little star labeled Sol? Hover over its blue-green planet. Home is closer than it looks.',
        pointAt: 'Earth',
        isTracked: true,
    },
    {
        id: 'moonwalk',
        name: 'the Apollo 11 moonwalk',
        hint: "While the Earth fills the view, hover over the Moon. That's one small step…",
        pointAt: 'Earth',
        isTracked: true,
    },
    {
        id: 'mars',
        name: 'a drive with Curiosity',
        hint: 'The red one in Sol has a rover on it. Hover over Mars to hitch a ride.',
        pointAt: 'Mars',
        isTracked: true,
    },
    {
        id: 'voyager',
        name: 'the Golden Record',
        hint: 'Two Voyagers are drifting out of Sol with a record for whoever finds them. Hover over one to give it a spin.',
        pointAt: 'Voyager 1',
        isTracked: true,
    },
    {
        id: 'pioneer',
        name: 'the Pioneer plaque',
        hint: 'The Pioneers carry a note for aliens. Hover over one to read it.',
        pointAt: 'Pioneer 10',
        isTracked: true,
    },
    {
        id: 'solar',
        name: 'the Solar System',
        hint: 'Pinch or scroll in over the galaxy to visit the Solar System. Watch for a comet!',
        zoomTo: 'solar',
        isTracked: true,
    },
    {
        id: 'starship',
        name: 'a Starship launch',
        hint: 'Zoom all the way in to Earth and wait for Florida to come round. Something is fueling up…',
        zoomTo: 'earth',
        isTracked: true,
    },
    {
        id: 'laniakea',
        name: 'Laniakea',
        hint: 'Zoom out past the Milky Way to see where it sits among 41,000 other galaxies.',
        zoomTo: 'laniakea',
        isTracked: true,
    },
    {
        id: 'logo',
        name: 'my cousin in the logo',
        hint: 'Psst. My cousin lives in the logo up top. Give it a poke.',
        isTracked: true,
    },
    {
        id: 'endurance',
        name: 'the Endurance',
        hint: 'Watch the edge of the black hole for a ship spinning its ring. Do not go gentle…',
        isTracked: false,
    },
    {
        id: 'enterprise',
        name: 'the Enterprise',
        hint: 'Something is cruising the outer galaxy, and every so often it jumps to warp. Engage!',
        isTracked: false,
    },
];

/** Reaching these zoom levels counts as finding their egg. */
export const EASTER_EGG_FOR_ZOOM: Partial<Record<ZoomLevel, EasterEggId>> = {
    solar: 'solar',
    earth: 'starship',
    laniakea: 'laniakea',
};

export type EasterEggEvent = CustomEvent<{ id: EasterEggId }>;

const STORAGE_KEY = 'onedrop.easter-eggs';

export function foundEasterEggs(): EasterEggId[] {
    try {
        const stored = JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '[]');

        return Array.isArray(stored) ? stored : [];
    } catch {
        return [];
    }
}

/** Marks an easter egg found (once) and tells Droppy. */
export function findEasterEgg(id: EasterEggId) {
    const found = foundEasterEggs();

    if (found.includes(id)) {
        return;
    }

    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify([...found, id]));
    } catch {
        // Private browsing: it's still announced, just not remembered.
    }

    window.dispatchEvent(new CustomEvent('easter-egg', { detail: { id } }));
}
