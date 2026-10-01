import { useEffect, useState } from 'react';
import { TemplateLogo } from '@/components/app-gallery';
import { cn } from '@/lib/utils';
import type { FeaturedApp } from '@/types';

/** How long each app stays in the middle. */
const INTERVAL = 4500;

/** Cards shown on each side of the middle one. */
const SIDE = 2;

/**
 * The popular free apps as a coverflow above the full list (PRJ-012): the middle one large, its neighbours angled
 * behind it. It moves on by itself, except while hovered or focused, or when the system asks for reduced motion.
 * A side card comes to the middle when clicked; the middle one opens its details.
 */
export default function AppCoverflow({
    apps,
    onOpen,
}: {
    apps: FeaturedApp[];
    onOpen: (app: FeaturedApp) => void;
}) {
    const [failed, setFailed] = useState<Set<string>>(() => new Set());
    const shown = apps.filter((app) => !failed.has(app.value));
    const [active, setActive] = useState(0);
    const [paused, setPaused] = useState(false);
    const count = shown.length;
    const current = count > 0 ? active % count : 0;

    useEffect(() => {
        const still = window.matchMedia(
            '(prefers-reduced-motion: reduce)',
        ).matches;

        if (paused || still || count < 2) {
            return;
        }

        const timer = window.setInterval(
            () => setActive((index) => (index + 1) % count),
            INTERVAL,
        );

        return () => window.clearInterval(timer);
    }, [paused, count]);

    if (count === 0) {
        return null;
    }

    return (
        <div
            className="space-y-3"
            onMouseEnter={() => setPaused(true)}
            onMouseLeave={() => setPaused(false)}
            onFocus={() => setPaused(true)}
            onBlur={() => setPaused(false)}
            aria-roledescription="carousel"
            aria-label="Popular free apps"
            data-test="app-coverflow"
        >
            <div className="relative h-56 overflow-hidden [perspective:1200px] sm:h-72">
                {shown.map((app, index) => {
                    // The shortest way round from the middle card, so the row wraps.
                    let offset = index - current;

                    if (offset > count / 2) {
                        offset -= count;
                    } else if (offset < -count / 2) {
                        offset += count;
                    }

                    const distance = Math.abs(offset);
                    const middle = offset === 0;

                    return (
                        <button
                            key={app.value}
                            type="button"
                            tabIndex={distance > SIDE ? -1 : 0}
                            aria-hidden={distance > SIDE}
                            aria-current={middle}
                            aria-label={
                                middle
                                    ? `About ${app.label}`
                                    : `Show ${app.label}`
                            }
                            onClick={() =>
                                middle ? onOpen(app) : setActive(index)
                            }
                            style={{
                                transform: `translateX(-50%) translateX(${offset * 42}%) translateZ(${-distance * 140}px) rotateY(${offset * -32}deg)`,
                                zIndex: 10 - distance,
                                opacity: distance > SIDE ? 0 : 1,
                            }}
                            className={cn(
                                'absolute top-2 left-1/2 aspect-video h-[calc(100%-1rem)] overflow-hidden rounded-xl border bg-muted text-left shadow-xl transition-[transform,opacity] duration-500 ease-out',
                                distance > SIDE && 'pointer-events-none',
                            )}
                            data-test="coverflow-card"
                        >
                            <img
                                src={app.cover}
                                alt=""
                                referrerPolicy="no-referrer"
                                onError={() =>
                                    setFailed((all) =>
                                        new Set(all).add(app.value),
                                    )
                                }
                                className="size-full object-cover"
                            />
                            <span
                                className={cn(
                                    'absolute inset-0 bg-black/45 transition-opacity duration-500',
                                    middle && 'opacity-0',
                                )}
                            />
                            <span className="absolute inset-x-0 bottom-0 flex items-center gap-2.5 bg-gradient-to-t from-black/85 via-black/50 to-transparent px-4 pt-10 pb-3 text-white">
                                <TemplateLogo
                                    template={app}
                                    className="size-7"
                                />
                                <span className="min-w-0">
                                    <span className="block text-sm font-semibold">
                                        {app.label}
                                    </span>
                                    <span className="line-clamp-1 text-xs text-white/75">
                                        {app.description}
                                    </span>
                                </span>
                            </span>
                        </button>
                    );
                })}
            </div>
            {count > 1 && (
                <div className="flex justify-center gap-1.5">
                    {shown.map((app, index) => (
                        <button
                            key={app.value}
                            type="button"
                            onClick={() => setActive(index)}
                            aria-label={`Show ${app.label}`}
                            aria-current={index === current}
                            className={cn(
                                'h-1.5 w-1.5 rounded-full bg-muted-foreground/30 transition-all hover:bg-muted-foreground/60',
                                index === current && 'w-5 bg-emerald-500',
                            )}
                        />
                    ))}
                </div>
            )}
        </div>
    );
}
