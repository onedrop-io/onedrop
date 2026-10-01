import { ChevronDown, X } from 'lucide-react';
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

/**
 * A pane's tabs on a phone (LAYOUT-007): the tab showing, and how many are open, in one button; the rest and the
 * "+" menu in a sheet from the bottom, like a mobile browser's tab switcher.
 */
export default function MobileTabSwitcher({
    tabs,
    newTabs,
    className,
}: {
    tabs: SwitcherTab[];
    newTabs: SwitcherNewTab[];
    className?: string;
}) {
    const [open, setOpen] = useState(false);
    const active = tabs.find((tab) => tab.active) ?? tabs[0];
    const pick = (action: () => void) => {
        setOpen(false);
        action();
    };

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                aria-label={`Tabs: ${active?.label ?? ''}, ${tabs.length} open`}
                data-test="mobile-tab-switcher"
                className={cn(
                    'flex min-w-0 shrink items-center gap-1.5 rounded-md bg-muted px-2 py-1 font-medium',
                    className,
                )}
            >
                {active?.icon}
                <span className="truncate">{active?.label}</span>
                <ChevronDown className="size-3.5 shrink-0 text-muted-foreground" />
                {tabs.length > 1 && (
                    <span className="shrink-0 rounded border border-current/30 px-1 text-xs leading-4 text-muted-foreground">
                        {tabs.length}
                    </span>
                )}
            </button>
            <Sheet open={open} onOpenChange={setOpen}>
                <SheetContent
                    side="bottom"
                    className="max-h-[80svh] gap-0 overflow-y-auto rounded-t-xl pb-[max(1rem,env(safe-area-inset-bottom))]"
                    data-test="mobile-tab-sheet"
                >
                    <SheetHeader className="pb-2">
                        <SheetTitle>Tabs</SheetTitle>
                        <SheetDescription className="sr-only">
                            Switch, close or open tabs
                        </SheetDescription>
                    </SheetHeader>
                    <ul className="px-2">
                        {tabs.map((tab) => (
                            <li
                                key={tab.id}
                                className={cn(
                                    'flex items-center rounded-md',
                                    tab.active && 'bg-muted font-medium',
                                )}
                            >
                                <button
                                    type="button"
                                    onClick={() => pick(tab.onSelect)}
                                    data-test={`mobile-tab-${tab.id}`}
                                    className="flex min-w-0 flex-1 items-center gap-2 px-3 py-2.5 text-left"
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
                                        className="mr-1 rounded p-2 text-muted-foreground hover:bg-background"
                                    >
                                        <X className="size-4" />
                                    </button>
                                )}
                            </li>
                        ))}
                    </ul>
                    <p className="px-5 pt-4 pb-1 text-xs font-medium text-muted-foreground">
                        Open
                    </p>
                    <ul className="grid grid-cols-2 gap-1 px-2">
                        {newTabs.map((tab) => (
                            <li key={tab.id}>
                                <button
                                    type="button"
                                    onClick={() => pick(tab.onSelect)}
                                    data-test={`mobile-add-tab-${tab.id}`}
                                    className="flex w-full items-center gap-2 rounded-md px-3 py-2.5 text-left hover:bg-muted"
                                >
                                    {tab.icon}
                                    {tab.label}
                                </button>
                            </li>
                        ))}
                    </ul>
                </SheetContent>
            </Sheet>
        </>
    );
}
