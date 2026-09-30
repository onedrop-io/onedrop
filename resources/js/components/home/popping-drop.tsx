import { useRef, useState } from 'react';
import { findEasterEgg } from '@/components/home/easter-eggs';
import { universe } from '@/components/home/particle-universe';

/** How long the drop stays gone before it bounces back, and the wait before it can pop again. */
const GONE_MS = 1000;
const COOLDOWN_MS = 2500;

/**
 * The header logo's droplet. Hovering it pops it into a small burst of
 * particles like the hero's drop, then it bounces back. Only
 * once the hero's drop has landed, and never with reduced motion.
 */
export function PoppingDrop() {
    const dropRef = useRef<SVGSVGElement>(null);
    const lastPopAt = useRef(0);
    const [isGone, setIsGone] = useState(false);

    const pop = () => {
        const drop = dropRef.current;
        const now = performance.now();

        if (
            !drop ||
            universe.firstImpactAt === null ||
            now - lastPopAt.current < COOLDOWN_MS ||
            window.matchMedia('(prefers-reduced-motion: reduce)').matches
        ) {
            return;
        }

        const rect = drop.getBoundingClientRect();
        lastPopAt.current = now;
        universe.pendingBursts.push({
            x: rect.left + rect.width / 2 + window.scrollX,
            y: rect.top + rect.height / 2 + window.scrollY,
        });
        setIsGone(true);
        findEasterEgg('logo');
        window.setTimeout(() => setIsGone(false), GONE_MS);
    };

    return (
        <svg
            ref={dropRef}
            viewBox="0 0 24 24"
            aria-hidden="true"
            data-test="logo-drop"
            data-state={isGone ? 'popped' : 'idle'}
            onPointerEnter={pop}
            className="size-6 text-[#FF9A5C]"
            style={{
                transform: isGone ? 'scale(0)' : 'scale(1)',
                opacity: isGone ? 0 : 1,
                transition: isGone
                    ? 'transform 160ms ease-in, opacity 160ms ease-in'
                    : 'transform 520ms cubic-bezier(0.34, 1.7, 0.64, 1), opacity 200ms ease-out',
            }}
        >
            <path
                d="M12 2.5c-.4.5-7 8.3-7 13a7 7 0 0 0 14 0c0-4.7-6.6-12.5-7-13Z"
                fill="currentColor"
            />
            <path
                d="M9.2 15.6a3 3 0 0 0 2.6 2.9"
                stroke="white"
                strokeWidth="1.6"
                strokeLinecap="round"
                fill="none"
                opacity="0.85"
            />
        </svg>
    );
}
