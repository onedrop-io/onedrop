import { FolderTree, MessageSquare, MoreHorizontal, X } from 'lucide-react';
import { useRef, useState } from 'react';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { cn } from '@/lib/utils';

export type SwitcherTab = {
    id: string;
    label: string;
    icon: React.ReactNode;
    active: boolean;
    /** The open file has unsaved changes. */
    dirty?: boolean;
    onSelect: () => void;
    /** Tools and Preview can't be closed. */
    onClose?: () => void;
};

export type SwitcherNewTab = {
    id: string;
    label: string;
    icon: React.ReactNode;
    onSelect: () => void;
};

/** One of the bar's shortcuts to a workspace tab: Preview, Tools or Shell. */
export type BarItem = {
    id: string;
    label: string;
    icon: React.ReactNode;
    active: boolean;
    /** The open tabs it stands for (every Shell, for Shell). */
    tabIds: string[];
    onSelect: () => void;
};

/** How far a finger moves sideways along the bar, in pixels, for a swipe to change tabs. */
const SWIPE = 40;

/**
 * The project's tabs on a phone (LAYOUT-007): one bar along the bottom, in thumb's reach, with Chat, the workspace's
 * main tabs, and More, a sheet with every open tab (to switch to or close) and the tabs that can be opened. Swiping
 * along the bar goes to the next or previous open tab, Chat first, so tabs behind More are a swipe away (LAYOUT-008).
 */
export default function MobileTabBar({
    chatActive,
    working,
    onChat,
    items,
    tabs,
    newTabs,
    filesOpen,
    onToggleFiles,
}: {
    chatActive: boolean;
    /** The agent is working: Chat shows it while the workspace is on screen. */
    working: boolean;
    onChat: () => void;
    items: BarItem[];
    /** Every open tab, for the More sheet. */
    tabs: SwitcherTab[];
    newTabs: SwitcherNewTab[];
    /** The files panel is showing over the workspace. */
    filesOpen: boolean;
    onToggleFiles: () => void;
}) {
    const [open, setOpen] = useState(false);
    // A tab without a shortcut that's showing takes More's place in the bar, so it's clear where you are.
    const others = tabs.filter(
        (tab) => !items.some((item) => item.tabIds.includes(tab.id)),
    );
    // The sheet leaves out what can't close and is already in the bar (Tools, Preview).
    const listed = tabs.filter(
        (tab) =>
            tab.onClose || !items.some((item) => item.tabIds.includes(tab.id)),
    );
    const otherShowing = chatActive
        ? undefined
        : others.find((tab) => tab.active);
    const touch = useRef<{ x: number; y: number } | null>(null);
    // What a swipe steps through, in the order the bar shows it: Chat, the bar's tabs, then the tabs behind More.
    const ordered = [
        ...items.flatMap((item) => item.tabIds),
        ...others.map((tab) => tab.id),
    ].flatMap((id) => tabs.filter((tab) => tab.id === id));
    const order = [
        { id: 'chat', onSelect: onChat },
        ...ordered.map((tab) => ({ id: tab.id, onSelect: tab.onSelect })),
    ];
    const current = chatActive
        ? 0
        : order.findIndex(
              (entry) => tabs.find((tab) => tab.active)?.id === entry.id,
          );
    const onTouchStart = (event: React.TouchEvent) => {
        touch.current =
            event.touches.length === 1
                ? { x: event.touches[0].clientX, y: event.touches[0].clientY }
                : null;
    };
    const onTouchEnd = (event: React.TouchEvent) => {
        const start = touch.current;
        const end = event.changedTouches[0];
        touch.current = null;

        if (!start || !end) {
            return;
        }

        const dx = end.clientX - start.x;
        const dy = end.clientY - start.y;

        if (Math.abs(dx) < SWIPE || Math.abs(dx) < Math.abs(dy) * 1.5) {
            return;
        }

        // A swipe, not a tap: the button it started on isn't pressed too.
        event.preventDefault();
        // Swiping left moves on, like turning a page.
        const next = order[Math.max(0, current) + (dx < 0 ? 1 : -1)];

        next?.onSelect();
    };
    const pick = (action: () => void) => {
        setOpen(false);
        action();
    };

    const button = (
        id: string,
        label: string,
        icon: React.ReactNode,
        active: boolean,
        onClick: () => void,
        extra?: React.ReactNode,
    ) => (
        <button
            key={id}
            type="button"
            role="tab"
            aria-selected={active}
            onClick={onClick}
            data-test={`mobile-bar-${id}`}
            className={cn(
                'relative flex min-w-0 flex-1 flex-col items-center gap-0.5 px-1 pt-2 pb-1.5 text-[11px] font-medium text-muted-foreground transition-colors [&_svg]:size-5',
                active && 'text-foreground',
            )}
        >
            <span
                className={cn(
                    'flex h-7 w-12 items-center justify-center rounded-full',
                    active && 'bg-muted',
                )}
            >
                {icon}
            </span>
            <span className="max-w-full truncate">{label}</span>
            {extra}
        </button>
    );

    return (
        <>
            <nav
                role="tablist"
                aria-label="Chat and workspace tabs"
                className="flex shrink-0 border-t border-sidebar-border/70 bg-toolbar pb-[env(safe-area-inset-bottom)] md:hidden dark:border-sidebar-border"
                data-test="mobile-tab-bar"
                onTouchStart={onTouchStart}
                onTouchEnd={onTouchEnd}
                onTouchCancel={() => (touch.current = null)}
            >
                {button(
                    'chat',
                    'Chat',
                    <MessageSquare />,
                    chatActive,
                    onChat,
                    working && !chatActive && (
                        <span
                            className="absolute top-2 left-1/2 ml-3 size-2 animate-pulse rounded-full bg-primary"
                            aria-label="Agent is working"
                            data-test="mobile-chat-working"
                        />
                    ),
                )}
                {items.map((item) =>
                    button(
                        item.id,
                        item.label,
                        item.icon,
                        !chatActive && item.active,
                        item.onSelect,
                    ),
                )}
                {button(
                    'more',
                    otherShowing?.label ?? 'More',
                    otherShowing?.icon ?? <MoreHorizontal />,
                    !!otherShowing,
                    () => setOpen(true),
                    others.length > 0 && !otherShowing && (
                        <span className="absolute top-1.5 left-1/2 ml-2.5 min-w-4 rounded-full bg-muted px-1 text-[10px] leading-4 text-foreground">
                            {others.length}
                        </span>
                    ),
                )}
            </nav>
            <Sheet open={open} onOpenChange={setOpen}>
                <SheetContent
                    side="bottom"
                    className="max-h-[80svh] gap-0 overflow-y-auto rounded-t-xl pb-[max(1rem,env(safe-area-inset-bottom))]"
                    data-test="mobile-tab-sheet"
                >
                    <SheetHeader className="pb-1">
                        <SheetTitle>Open tabs</SheetTitle>
                        <SheetDescription className="sr-only">
                            Switch to, close or add tabs
                        </SheetDescription>
                    </SheetHeader>
                    {listed.length > 0 ? (
                        <ul className="mx-3 divide-y divide-border overflow-hidden rounded-lg border border-border">
                            {listed.map((tab) => (
                                <li
                                    key={tab.id}
                                    className={cn(
                                        'flex items-center',
                                        tab.active &&
                                            !chatActive &&
                                            'bg-muted font-medium',
                                    )}
                                >
                                    <button
                                        type="button"
                                        onClick={() => pick(tab.onSelect)}
                                        data-test={`mobile-tab-${tab.id}`}
                                        className="flex min-w-0 flex-1 items-center gap-3 px-3 py-3 text-left"
                                    >
                                        {tab.icon}
                                        <span className="truncate">
                                            {tab.label}
                                        </span>
                                        {tab.dirty && (
                                            <span
                                                className="size-1.5 shrink-0 rounded-full bg-current"
                                                aria-label="Unsaved changes"
                                            />
                                        )}
                                    </button>
                                    {tab.onClose && (
                                        <button
                                            type="button"
                                            onClick={tab.onClose}
                                            aria-label={`Close ${tab.label}`}
                                            data-test={`mobile-close-${tab.id}`}
                                            className="mr-1 rounded p-2.5 text-muted-foreground hover:bg-background"
                                        >
                                            <X className="size-4" />
                                        </button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p className="mx-4 text-sm text-muted-foreground">
                            Only the tabs in the bar are open.
                        </p>
                    )}
                    <button
                        type="button"
                        onClick={() => pick(onToggleFiles)}
                        className="mx-3 mt-3 flex items-center gap-3 rounded-lg border border-border px-3 py-3 text-left"
                        data-test="mobile-files"
                    >
                        <FolderTree className="size-4" />
                        {filesOpen ? 'Hide files' : 'Files'}
                    </button>
                    {newTabs.length > 0 && (
                        <>
                            <p className="px-4 pt-5 pb-2 text-sm font-semibold">
                                Add a tab
                            </p>
                            <ul className="grid grid-cols-3 gap-2 px-3">
                                {newTabs.map((tab) => (
                                    <li key={tab.id}>
                                        <button
                                            type="button"
                                            onClick={() => pick(tab.onSelect)}
                                            data-test={`mobile-add-tab-${tab.id}`}
                                            className="flex w-full flex-col items-center gap-1.5 rounded-lg bg-muted/60 px-1 py-3 text-xs hover:bg-muted [&_svg]:size-5"
                                        >
                                            {tab.icon}
                                            <span className="max-w-full truncate">
                                                {tab.label}
                                            </span>
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        </>
                    )}
                </SheetContent>
            </Sheet>
        </>
    );
}
