import { ChevronDown } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';

/** A tab as the strip's "every tab" menu lists it. */
export type StripTab = {
    id: string;
    label: string;
    icon: React.ReactNode;
    active: boolean;
    /** The open file has unsaved changes. */
    dirty?: boolean;
    onSelect: () => void;
};

/** How far a fade reaches in from an edge the tabs go past, in pixels. */
const FADE = 24;

/**
 * A pane's tabs (LAYOUT-008). They scroll sideways when they don't fit: by swiping, a trackpad, or a mouse wheel turned
 * the usual way. An edge the tabs go past fades out, and a menu at the end lists every tab, so none is ever out of
 * reach. The showing tab is kept in view as tabs open and the pane resizes.
 */
export default function TabStrip({
    paneId,
    tabs,
    onMenuCloseAutoFocus,
    after,
    children,
}: {
    paneId: number;
    /** Every tab in the pane, for the menu. */
    tabs: StripTab[];
    /** Where focus goes as the menu closes (a Shell keeps it). */
    onMenuCloseAutoFocus?: (event: Event) => void;
    /** Shown right after the tabs, before the menu (the "+"), so it doesn't move as the menu comes and goes. */
    after?: React.ReactNode;
    /** The tab buttons. */
    children: React.ReactNode;
}) {
    const strip = useRef<HTMLDivElement>(null);
    const row = useRef<HTMLDivElement>(null);
    const [edges, setEdges] = useState({ start: false, end: false });
    const active = tabs.find((tab) => tab.active)?.id;

    useEffect(() => {
        const element = strip.current;

        if (!element) {
            return;
        }

        const measure = () => {
            const start = element.scrollLeft > 1;
            const end =
                element.scrollLeft + element.clientWidth <
                element.scrollWidth - 1;

            setEdges((current) =>
                current.start === start && current.end === end
                    ? current
                    : { start, end },
            );
        };
        // A mouse wheel turns up and down: move the tabs sideways instead, while they overflow.
        const onWheel = (event: WheelEvent) => {
            if (
                element.scrollWidth <= element.clientWidth ||
                Math.abs(event.deltaX) >= Math.abs(event.deltaY)
            ) {
                return;
            }

            event.preventDefault();
            element.scrollLeft += event.deltaY;
        };
        const resized = new ResizeObserver(() => {
            measure();
            showActive(element, 'instant');
        });

        resized.observe(element);

        if (row.current) {
            resized.observe(row.current);
        }

        element.addEventListener('scroll', measure, { passive: true });
        element.addEventListener('wheel', onWheel, { passive: false });
        measure();

        return () => {
            resized.disconnect();
            element.removeEventListener('scroll', measure);
            element.removeEventListener('wheel', onWheel);
        };
    }, []);

    // The tab that's shown, however it was shown (clicked, opened from elsewhere, swiped to), is scrolled into view.
    useEffect(() => {
        if (strip.current) {
            showActive(strip.current, 'smooth');
        }
    }, [active, tabs.length]);

    const overflowing = edges.start || edges.end;
    const mask = `linear-gradient(to right, ${edges.start ? 'transparent' : 'black'}, black ${FADE}px, black calc(100% - ${FADE}px), ${edges.end ? 'transparent' : 'black'})`;

    return (
        <>
            <div
                ref={strip}
                className="flex min-w-12 shrink [scrollbar-width:none] overflow-x-auto overscroll-x-contain [&::-webkit-scrollbar]:hidden"
                style={
                    overflowing
                        ? { maskImage: mask, WebkitMaskImage: mask }
                        : undefined
                }
                data-tabs={paneId}
                data-overflow-start={edges.start || undefined}
                data-overflow-end={edges.end || undefined}
            >
                <div ref={row} className="flex w-max items-center gap-1">
                    {children}
                </div>
            </div>
            {after}
            {overflowing && (
                <DropdownMenu modal={false}>
                    <DropdownMenuTrigger asChild>
                        <button
                            type="button"
                            aria-label="Show all tabs"
                            title="Show all tabs"
                            data-test="all-tabs"
                            className="shrink-0 rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                        >
                            <ChevronDown className="size-4" />
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        align="start"
                        className="max-h-[60svh] overflow-y-auto"
                        onFocusOutside={(event) => event.preventDefault()}
                        onCloseAutoFocus={onMenuCloseAutoFocus}
                    >
                        {tabs.map((tab) => (
                            <DropdownMenuItem
                                key={tab.id}
                                onSelect={() => {
                                    tab.onSelect();
                                    // Already showing but scrolled away: bring it back.
                                    requestAnimationFrame(
                                        () =>
                                            strip.current &&
                                            showActive(strip.current, 'smooth'),
                                    );
                                }}
                                data-test={`all-tabs-${tab.id}`}
                                className={cn(tab.active && 'font-medium')}
                            >
                                {tab.icon}
                                <span className="flex-1 truncate">
                                    {tab.label}
                                </span>
                                {tab.dirty && (
                                    <span
                                        className="size-1.5 shrink-0 rounded-full bg-current"
                                        aria-label="Unsaved changes"
                                    />
                                )}
                                {tab.active && (
                                    <span
                                        className="size-1.5 shrink-0 rounded-full bg-primary"
                                        aria-label="Showing"
                                    />
                                )}
                            </DropdownMenuItem>
                        ))}
                    </DropdownMenuContent>
                </DropdownMenu>
            )}
        </>
    );
}

/**
 * Scroll the strip just enough to show its active tab clear of the fades. Only the strip moves: `scrollIntoView` would
 * scroll the pane's bar and the page around it too.
 */
function showActive(strip: HTMLElement, behavior: ScrollBehavior): void {
    const tab = strip.querySelector('[data-active="true"]');

    if (!tab) {
        return;
    }

    const bounds = strip.getBoundingClientRect();
    const rect = tab.getBoundingClientRect();
    let by = 0;

    if (rect.right > bounds.right - FADE) {
        by = rect.right - bounds.right + FADE;
    }

    // A tab wider than the strip shows its start.
    if (rect.left - by < bounds.left + FADE) {
        by = rect.left - bounds.left - FADE;
    }

    if (Math.abs(by) > 1) {
        strip.scrollBy({ left: by, behavior });
    }
}
