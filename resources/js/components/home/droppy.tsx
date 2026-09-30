import { X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { ZoomEvent, ZoomLevel } from '@/components/home/cosmic-zoom';
import { EASTER_EGGS, foundEasterEggs } from '@/components/home/easter-eggs';
import type {
    EasterEgg,
    EasterEggEvent,
    EasterEggId,
} from '@/components/home/easter-eggs';

/** How long after the galaxy shows up before Droppy pops in (milliseconds). */
const APPEARS_AFTER_MS = 15_000;

/** How long Droppy points at a spot in the galaxy (milliseconds). */
const POINTS_FOR_MS = 6000;

const DISMISSED_KEY = 'onedrop.droppy-dismissed';

const TRACKED_EGGS = EASTER_EGGS.filter((egg) => egg.isTracked);

type Message =
    | { kind: 'intro' }
    | { kind: 'hint'; egg: EasterEgg; isPointing: boolean }
    | { kind: 'found'; egg: EasterEgg };

/**
 * Droppy, the home page's helpful droplet (with apologies to Clippy). Pops
 * up in the corner a little while after the galaxy appears and offers hints
 * for finding the easter eggs, cheering when one is found (see
 * `easter-eggs`). Closing the bubble leaves Droppy in the corner to click
 * for another hint; "Don't show Droppy again" sends it away for good.
 */
export function Droppy() {
    const [isHere, setIsHere] = useState(false);
    const [message, setMessage] = useState<Message | null>(null);
    const [found, setFound] = useState<EasterEggId[]>([]);
    const [zoomLevel, setZoomLevel] = useState<ZoomLevel>('galaxy');
    const hinted = useRef<EasterEggId[]>([]);
    const isHereRef = useRef(false);
    const pointedAt = useRef<{ spot: HTMLElement; timer: number } | null>(null);

    useEffect(() => {
        if (
            localStorage.getItem(DISMISSED_KEY) ||
            window.matchMedia('(prefers-reduced-motion: reduce)').matches
        ) {
            return;
        }

        setFound(foundEasterEggs());

        let timer = 0;
        const onZoom = (event: Event) => {
            const { level, isAvailable } = (event as ZoomEvent).detail;
            setZoomLevel(level);

            if (isAvailable && !timer) {
                timer = window.setTimeout(() => {
                    isHereRef.current = true;
                    setIsHere(true);
                    setMessage({ kind: 'intro' });
                }, APPEARS_AFTER_MS);
            }
        };
        const onFound = (event: Event) => {
            const egg = EASTER_EGGS.find(
                (candidate) =>
                    candidate.id === (event as EasterEggEvent).detail.id,
            );

            if (!egg) {
                return;
            }

            setFound((previous) => [...previous, egg.id]);

            if (isHereRef.current) {
                setMessage({ kind: 'found', egg });
            }
        };

        window.addEventListener('cosmic-zoom', onZoom);
        window.addEventListener('easter-egg', onFound);

        return () => {
            window.clearTimeout(timer);
            window.removeEventListener('cosmic-zoom', onZoom);
            window.removeEventListener('easter-egg', onFound);
        };
    }, []);

    useEffect(() => () => stopPointing(), []);

    const stopPointing = () => {
        if (pointedAt.current) {
            window.clearTimeout(pointedAt.current.timer);
            delete pointedAt.current.spot.dataset.hinted;
            pointedAt.current = null;
        }
    };

    /** The next egg to hint at: ones not found yet, then the ones that play on their own, taking turns. */
    const nextHint = (): EasterEgg | null => {
        const candidates = EASTER_EGGS.filter((egg) => !found.includes(egg.id));

        if (candidates.length === 0) {
            return null;
        }

        const fresh = candidates.find(
            (egg) => !hinted.current.includes(egg.id),
        );

        if (!fresh) {
            hinted.current = [];

            return candidates[0];
        }

        return fresh;
    };

    const giveHint = () => {
        stopPointing();
        const egg = nextHint();

        if (!egg) {
            setMessage(null);

            return;
        }

        hinted.current = [...hinted.current, egg.id];
        setMessage({ kind: 'hint', egg, isPointing: false });
    };

    const showMe = (egg: EasterEgg) => {
        const zoomTo = egg.zoomTo ?? (egg.pointAt ? 'galaxy' : null);

        if (zoomTo && zoomTo !== zoomLevel) {
            window.dispatchEvent(
                new CustomEvent('cosmic-zoom-request', {
                    detail: { level: zoomTo },
                }),
            );
        }

        const spot = egg.pointAt
            ? document.querySelector<HTMLElement>(
                  `[data-closeup="${egg.pointAt}"]`,
              )
            : null;

        if (spot) {
            stopPointing();
            spot.dataset.hinted = 'true';
            pointedAt.current = {
                spot,
                timer: window.setTimeout(stopPointing, POINTS_FOR_MS),
            };
        }

        setMessage({ kind: 'hint', egg, isPointing: true });
    };

    const close = () => {
        stopPointing();
        setMessage(null);
    };

    const dismiss = () => {
        stopPointing();
        localStorage.setItem(DISMISSED_KEY, '1');
        isHereRef.current = false;
        setIsHere(false);
    };

    if (!isHere) {
        return null;
    }

    const foundCount = TRACKED_EGGS.filter((egg) =>
        found.includes(egg.id),
    ).length;
    const hasFoundAll = foundCount === TRACKED_EGGS.length;

    return (
        <aside
            aria-label="Droppy"
            data-test="droppy"
            className="fixed right-6 bottom-6 z-40 hidden flex-col items-end gap-3 motion-reduce:hidden xl:flex"
        >
            {message && (
                <div
                    role="status"
                    data-test="droppy-bubble"
                    className="relative w-72 animate-in rounded-2xl bg-[#FFF7E0] p-4 pr-8 text-sm leading-snug text-[#2A2320] shadow-[0_20px_50px_-15px_rgba(0,0,0,0.8)] ring-1 ring-[#E8D9A8] duration-300 fade-in slide-in-from-bottom-2"
                >
                    <button
                        type="button"
                        aria-label="Close"
                        onClick={close}
                        className="absolute top-2 right-2 rounded-full p-1 text-[#8A7B5C] hover:bg-[#F0E4BF] hover:text-[#2A2320] focus-visible:ring-2 focus-visible:ring-[#FF9A5C] focus-visible:outline-none"
                    >
                        <X className="size-3.5" />
                    </button>

                    {message.kind === 'intro' && (
                        <p>
                            It looks like you're exploring the universe! It's
                            full of easter eggs. Would you like a hint?
                        </p>
                    )}
                    {message.kind === 'hint' && (
                        <p>
                            {message.isPointing && message.egg.pointAt
                                ? 'There, the glowing one! Hover over it. '
                                : ''}
                            {message.egg.hint}
                        </p>
                    )}
                    {message.kind === 'found' && (
                        <p>
                            {hasFoundAll
                                ? "You've found every one I can keep track of. You're a true space cadet! 🚀"
                                : `You found ${message.egg.name}! 🎉`}
                        </p>
                    )}

                    {message.kind !== 'intro' && (
                        <p
                            data-test="droppy-found"
                            className="mt-2 text-xs text-[#8A7B5C]"
                        >
                            {foundCount} of {TRACKED_EGGS.length} found
                        </p>
                    )}

                    <div className="mt-3 flex flex-wrap gap-2">
                        {message.kind === 'hint' &&
                            !message.isPointing &&
                            (message.egg.zoomTo || message.egg.pointAt) && (
                                <DroppyButton
                                    isPrimary
                                    onClick={() => showMe(message.egg)}
                                >
                                    Show me
                                </DroppyButton>
                            )}
                        {message.kind === 'intro' ? (
                            <>
                                <DroppyButton isPrimary onClick={giveHint}>
                                    Yes please
                                </DroppyButton>
                                <DroppyButton onClick={close}>
                                    No thanks
                                </DroppyButton>
                            </>
                        ) : (
                            <DroppyButton
                                isPrimary={message.kind === 'found'}
                                onClick={giveHint}
                            >
                                {message.kind === 'found'
                                    ? 'Next hint'
                                    : 'Another hint'}
                            </DroppyButton>
                        )}
                    </div>

                    <button
                        type="button"
                        onClick={dismiss}
                        className="mt-3 text-xs text-[#8A7B5C] underline-offset-2 hover:text-[#2A2320] hover:underline"
                    >
                        Don't show Droppy again
                    </button>

                    <span className="absolute right-7 -bottom-1.5 size-3 rotate-45 bg-[#FFF7E0] ring-1 ring-[#E8D9A8] [clip-path:polygon(100%_0,100%_100%,0_100%)]" />
                </div>
            )}

            <button
                type="button"
                aria-label="Ask Droppy for a hint"
                data-test="droppy-character"
                onClick={message ? close : giveHint}
                className="mr-2 animate-droppy-bounce rounded-full transition hover:-translate-y-1 focus-visible:ring-2 focus-visible:ring-[#FF9A5C] focus-visible:outline-none"
            >
                <DroppyFace isTalking={message !== null} />
            </button>
        </aside>
    );
}

function DroppyButton({
    isPrimary = false,
    onClick,
    children,
}: {
    isPrimary?: boolean;
    onClick: () => void;
    children: string;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={`rounded-lg px-3 py-1.5 text-xs font-semibold transition focus-visible:ring-2 focus-visible:ring-[#FF9A5C] focus-visible:outline-none ${isPrimary ? 'bg-[#FF4D1C] text-white hover:brightness-110' : 'bg-[#F0E4BF] text-[#2A2320] hover:bg-[#E8D9A8]'}`}
        >
            {children}
        </button>
    );
}

/** Droppy: the logo's droplet with eyes that follow the pointer and raise their brows while talking. */
function DroppyFace({ isTalking }: { isTalking: boolean }) {
    const faceRef = useRef<SVGSVGElement>(null);
    const [look, setLook] = useState({ x: 0, y: 0 });

    useEffect(() => {
        let frame = 0;
        const follow = (event: PointerEvent) => {
            cancelAnimationFrame(frame);
            frame = requestAnimationFrame(() => {
                const face = faceRef.current?.getBoundingClientRect();

                if (!face) {
                    return;
                }

                const angle = Math.atan2(
                    event.clientY - (face.top + face.height * 0.62),
                    event.clientX - (face.left + face.width / 2),
                );
                setLook({ x: Math.cos(angle), y: Math.sin(angle) });
            });
        };

        window.addEventListener('pointermove', follow, { passive: true });

        return () => {
            cancelAnimationFrame(frame);
            window.removeEventListener('pointermove', follow);
        };
    }, []);

    const browLift = isTalking ? -1.2 : 0;

    return (
        <svg
            ref={faceRef}
            viewBox="0 0 48 56"
            aria-hidden="true"
            className="size-16 drop-shadow-[0_0_18px_rgba(255,154,92,0.55)]"
        >
            <path
                d="M24 2c-.8 1-16 18.5-16 30a16 16 0 0 0 32 0C40 20.5 24.8 3 24 2Z"
                fill="#FF9A5C"
            />
            <path
                d="M15 34a8 8 0 0 0 6 7.5"
                stroke="white"
                strokeWidth="2.2"
                strokeLinecap="round"
                fill="none"
                opacity="0.7"
            />
            {[18, 30].map((eyeX) => (
                <g key={eyeX}>
                    <path
                        d={`M${eyeX - 4} ${22 + browLift} q4 -3 8 0`}
                        stroke="#2A2320"
                        strokeWidth="1.6"
                        strokeLinecap="round"
                        fill="none"
                        className="transition-transform duration-300"
                    />
                    <g
                        className="animate-droppy-blink"
                        style={{
                            transformOrigin: `${eyeX}px 30px`,
                        }}
                    >
                        <circle cx={eyeX} cy="30" r="4.5" fill="white" />
                        <circle
                            cx={eyeX + look.x * 1.8}
                            cy={30 + look.y * 1.8}
                            r="2.1"
                            fill="#2A2320"
                        />
                    </g>
                </g>
            ))}
            <path
                d={
                    isTalking
                        ? 'M20 40 q4 4 8 0 q-4 1.5 -8 0'
                        : 'M20.5 40 q3.5 2.5 7 0'
                }
                stroke="#2A2320"
                strokeWidth="1.6"
                strokeLinecap="round"
                fill={isTalking ? '#2A2320' : 'none'}
            />
        </svg>
    );
}
