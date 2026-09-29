import { useEffect, useState } from 'react';
import { ZOOM_LEVEL_NAMES, ZOOM_LEVELS } from '@/components/home/cosmic-zoom';
import type { ZoomEvent, ZoomLevel } from '@/components/home/cosmic-zoom';

/**
 * The scale beside the galaxy: Earth, Solar System, Milky Way, Laniakea.
 * Shows which level is on screen, and jumps to one when it's clicked. Only
 * shows up once the galaxy does (see `cosmic-zoom`).
 */
export function CosmicZoomControl({ className = '' }: { className?: string }) {
    const [state, setState] = useState<{
        level: ZoomLevel;
        isAvailable: boolean;
    }>({ level: 'galaxy', isAvailable: false });

    useEffect(() => {
        const update = (event: Event) => setState((event as ZoomEvent).detail);
        window.addEventListener('cosmic-zoom', update);

        return () => window.removeEventListener('cosmic-zoom', update);
    }, []);

    const choose = (level: ZoomLevel) =>
        window.dispatchEvent(
            new CustomEvent('cosmic-zoom-request', { detail: { level } }),
        );

    return (
        <nav
            aria-label="Zoom"
            data-test="cosmic-zoom"
            data-level={state.level}
            className={`flex-col items-center gap-1.5 transition-opacity duration-700 motion-reduce:hidden ${state.isAvailable ? 'opacity-100' : 'pointer-events-none opacity-0'} ${className}`}
        >
            <div className="flex items-center gap-1 rounded-full bg-[#151110]/70 p-1 text-xs ring-1 ring-[#3A302B] backdrop-blur">
                {ZOOM_LEVELS.map((level) => (
                    <button
                        key={level}
                        type="button"
                        aria-pressed={state.level === level}
                        onClick={() => choose(level)}
                        className={`rounded-full px-3 py-1 font-medium whitespace-nowrap transition focus-visible:ring-2 focus-visible:ring-[#FF9A5C] focus-visible:outline-none ${state.level === level ? 'bg-[#FF4D1C] text-white' : 'text-[#B3A69C] hover:text-white'}`}
                    >
                        {ZOOM_LEVEL_NAMES[level]}
                    </button>
                ))}
            </div>
            <span className="text-[11px] text-[#7D7068]">
                Pinch or scroll over the galaxy to zoom
            </span>
        </nav>
    );
}
