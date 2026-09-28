import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { CSSProperties, ReactNode } from 'react';
import { SidebarProvider, useSidebar } from '@/components/ui/sidebar';
import ResizeHandle from '@/components/workspace/resize-handle';
import type { AppVariant } from '@/types';

type Props = {
    children: ReactNode;
    variant?: AppVariant;
};

/** Limits for the sidebar's width, in px. Kept in a cookie so the server renders it. */
const SIDEBAR_WIDTH = { initial: 256, min: 200, max: 480 };
const SIDEBAR_WIDTH_COOKIE = 'sidebar_width';

export function AppShell({ children, variant = 'sidebar' }: Props) {
    const { sidebarOpen, sidebarWidth } = usePage().props;
    const [width, setWidth] = useState(
        clampSidebarWidth(sidebarWidth ?? SIDEBAR_WIDTH.initial),
    );

    if (variant === 'header') {
        return (
            <div className="flex min-h-screen w-full flex-col">{children}</div>
        );
    }

    const resize = (value: number) => {
        const next = clampSidebarWidth(value);
        document.cookie = `${SIDEBAR_WIDTH_COOKIE}=${next}; path=/; max-age=${60 * 60 * 24 * 365}`;
        setWidth(next);
    };

    return (
        <SidebarProvider
            defaultOpen={sidebarOpen}
            style={{ '--sidebar-width': `${width}px` } as CSSProperties}
        >
            {children}
            <SidebarResizeHandle width={width} onResize={resize} />
        </SidebarProvider>
    );
}

/** Drag handle on the sidebar's right edge, shown only while it's expanded on desktop. */
function SidebarResizeHandle({
    width,
    onResize,
}: {
    width: number;
    onResize: (width: number) => void;
}) {
    const { state, isMobile } = useSidebar();

    if (isMobile || state === 'collapsed') {
        return null;
    }

    return (
        <ResizeHandle
            label="Resize sidebar"
            side="left"
            width={width}
            limits={SIDEBAR_WIDTH}
            onResize={onResize}
            className="fixed inset-y-0 left-(--sidebar-width) z-20 hidden -translate-x-1/2 bg-transparent md:block dark:bg-transparent"
            data-test="sidebar-resize"
        />
    );
}

function clampSidebarWidth(value: number): number {
    return Math.round(
        Math.min(SIDEBAR_WIDTH.max, Math.max(SIDEBAR_WIDTH.min, value)),
    );
}
