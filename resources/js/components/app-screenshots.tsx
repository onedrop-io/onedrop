import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useEffect, useState } from 'react';
import TemplateScreenshotController from '@/actions/App/Http/Controllers/TemplateScreenshotController';
import { jsonRequest } from '@/lib/json-request';
import { cn } from '@/lib/utils';

type Screenshot = { url: string; source: string };

/**
 * A free app's pictures (PRJ-012), asked for when its details open: its website's preview image, then app store
 * screenshots. Step through with the arrows, the dots or the arrow keys. It only appears once a picture has loaded
 * (most apps have none, and a placeholder that then vanished looked broken), and only shows pictures that have.
 */
export default function AppScreenshots({ template }: { template: string }) {
    const [screenshots, setScreenshots] = useState<Screenshot[] | null>(null);
    const [index, setIndex] = useState(0);
    const [loaded, setLoaded] = useState<Set<string>>(() => new Set());

    useEffect(() => {
        let current = true;

        jsonRequest<{ screenshots: Screenshot[] }>(
            TemplateScreenshotController.url({ query: { template } }),
        )
            .then(({ screenshots }) => {
                if (!current) {
                    return;
                }

                setScreenshots(screenshots);

                // Load every picture up front, so stepping through doesn't wait on each; drop any that won't load.
                for (const { url } of screenshots) {
                    const image = new Image();
                    image.referrerPolicy = 'no-referrer';
                    image.onload = () =>
                        current && setLoaded((done) => new Set(done).add(url));
                    image.onerror = () =>
                        current &&
                        setScreenshots((all) =>
                            (all ?? []).filter((other) => other.url !== url),
                        );
                    image.src = url;
                }
            })
            .catch(() => current && setScreenshots([]));

        return () => {
            current = false;
        };
    }, [template]);

    // In their order, but only the ones that have loaded so far.
    const ready = (screenshots ?? []).filter(({ url }) => loaded.has(url));

    if (ready.length === 0) {
        return null;
    }

    const shown = Math.min(index, ready.length - 1);
    const screenshot = ready[shown];
    const go = (next: number) => setIndex((next + ready.length) % ready.length);

    return (
        <div
            className="animate-in space-y-2 duration-300 fade-in-0 outline-none"
            tabIndex={0}
            onKeyDown={(event) => {
                if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
                    event.preventDefault();
                    go(shown + (event.key === 'ArrowLeft' ? -1 : 1));
                }
            }}
            aria-roledescription="carousel"
            aria-label="Screenshots"
            data-test="app-screenshots"
        >
            <div className="group relative aspect-video overflow-hidden rounded-xl border bg-muted">
                <img
                    key={screenshot.url}
                    src={screenshot.url}
                    alt={`Screenshot ${shown + 1} of ${ready.length}`}
                    referrerPolicy="no-referrer"
                    className="size-full object-contain"
                    data-test="app-screenshot"
                />
                {ready.length > 1 && (
                    <>
                        <CarouselButton
                            side="left"
                            onClick={() => go(shown - 1)}
                        />
                        <CarouselButton
                            side="right"
                            onClick={() => go(shown + 1)}
                        />
                    </>
                )}
            </div>
            <div className="flex items-center justify-between gap-3 text-xs text-muted-foreground">
                <span data-test="app-screenshot-source">
                    From {screenshot.source}
                </span>
                {ready.length > 1 && (
                    <div className="flex gap-1.5">
                        {ready.map((other, position) => (
                            <button
                                key={other.url}
                                type="button"
                                onClick={() => setIndex(position)}
                                aria-label={`Show screenshot ${position + 1}`}
                                aria-current={position === shown}
                                className={cn(
                                    'size-2 rounded-full bg-muted-foreground/30 hover:bg-muted-foreground/60',
                                    position === shown && 'bg-emerald-500',
                                )}
                            />
                        ))}
                    </div>
                )}
            </div>
        </div>
    );
}

function CarouselButton({
    side,
    onClick,
}: {
    side: 'left' | 'right';
    onClick: () => void;
}) {
    const Icon = side === 'left' ? ChevronLeft : ChevronRight;

    return (
        <button
            type="button"
            onClick={onClick}
            aria-label={
                side === 'left' ? 'Previous screenshot' : 'Next screenshot'
            }
            className={cn(
                'absolute top-1/2 flex size-8 -translate-y-1/2 items-center justify-center rounded-full bg-background/80 text-foreground shadow-sm backdrop-blur-sm transition-opacity hover:bg-background sm:opacity-0 sm:group-hover:opacity-100 sm:focus-visible:opacity-100',
                side === 'left' ? 'left-2' : 'right-2',
            )}
            data-test={`app-screenshot-${side === 'left' ? 'previous' : 'next'}`}
        >
            <Icon className="size-4" />
        </button>
    );
}
