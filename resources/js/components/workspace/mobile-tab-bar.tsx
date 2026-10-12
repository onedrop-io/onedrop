import { FolderTree, MessageSquare, MoreHorizontal, X } from 'lucide-react';
import { useState } from 'react';
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

/**
 * The project's tabs on a phone (LAYOUT-007): one bar along the bottom, in thumb's reach, with Chat, the workspace's
 * main tabs, and More, a sheet with every open tab (to switch to or close) and the tabs that can be opened.
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
