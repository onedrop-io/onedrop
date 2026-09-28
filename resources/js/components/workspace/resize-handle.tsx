import { useRef, useState } from 'react';
import type { KeyboardEvent, PointerEvent } from 'react';
import type { WidthLimits } from '@/hooks/use-resizable-width';
import { cn } from '@/lib/utils';

/** Pixels moved per arrow key press. */
const KEYBOARD_STEP = 16;

/**
 * A vertical divider that resizes the pane beside it. `side` is where that
 * pane sits: dragging away from it makes it wider. Double-click resets.
 */
export default function ResizeHandle({
    label,
    side,
    width,
    limits,
    onResize,
    className,
    ...props
}: {
    label: string;
    side: 'left' | 'right';
    width: number;
    limits: WidthLimits;
    onResize: (width: number) => void;
    className?: string;
    'data-test'?: string;
}) {
    const start = useRef<{ x: number; width: number } | null>(null);
    const [dragging, setDragging] = useState(false);
    const direction = side === 'left' ? 1 : -1;

    const onPointerDown = (event: PointerEvent<HTMLDivElement>) => {
        event.preventDefault();
        event.currentTarget.setPointerCapture(event.pointerId);
        start.current = { x: event.clientX, width };
        setDragging(true);
    };

    const onPointerMove = (event: PointerEvent<HTMLDivElement>) => {
        if (start.current) {
            onResize(
                start.current.width +
                    (event.clientX - start.current.x) * direction,
            );
        }
    };

    const onPointerUp = () => {
        start.current = null;
        setDragging(false);
    };

    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        const step = { ArrowRight: 1, ArrowLeft: -1 }[event.key];

        if (step) {
            event.preventDefault();
            onResize(width + step * KEYBOARD_STEP * direction);
        }
    };

    return (
        <div
            role="separator"
            aria-label={label}
            aria-orientation="vertical"
            aria-valuenow={width}
            aria-valuemin={limits.min}
            aria-valuemax={limits.max}
            tabIndex={0}
            data-dragging={dragging || undefined}
            onPointerDown={onPointerDown}
            onPointerMove={onPointerMove}
            onPointerUp={onPointerUp}
            onPointerCancel={onPointerUp}
            onDoubleClick={() => onResize(limits.initial)}
            onKeyDown={onKeyDown}
            className={cn(
                'group relative z-10 w-px shrink-0 cursor-col-resize touch-none bg-sidebar-border/70 outline-none dark:bg-sidebar-border',
                className,
            )}
            {...props}
        >
            <span
                className={cn(
                    'absolute inset-y-0 -right-1 -left-1 transition-colors group-hover:bg-primary/20 group-focus-visible:bg-primary/40',
                    dragging && 'bg-primary/40',
                )}
            />
            {/* Keeps previews (iframes) from swallowing the drag. */}
            {dragging && (
                <span className="fixed inset-0 cursor-col-resize select-none" />
            )}
        </div>
    );
}
