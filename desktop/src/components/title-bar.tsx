import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';
import { isMac } from '../lib/native';

/**
 * The top strip of a pane: drags the window, and on macOS leaves room for the window's buttons
 * (the window's own title bar is hidden behind the app).
 */
export default function TitleBar({
    children,
    className,
    leading = false,
}: {
    children?: ReactNode;
    className?: string;
    /** It's the leftmost strip, under the macOS window buttons. */
    leading?: boolean;
}) {
    return (
        <div
            data-tauri-drag-region
            className={cn(
                'flex h-12 shrink-0 items-center gap-2 px-3',
                leading && isMac() && 'pl-20',
                className,
            )}
        >
            {children}
        </div>
    );
}
