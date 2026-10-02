import {
    CalendarDays,
    Check,
    ChevronDown,
    Copy,
    Ellipsis,
    LayoutGrid,
    Lock,
    Pencil,
    Plus,
    Sheet,
    SquareKanban,
    Trash2,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useRef, useState } from 'react';
import type { KeyboardEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import { Popover, PopoverContent, PopoverTrigger } from './popover';
import { useDragReorder } from './sort-menu';
import type { TableView, ViewType } from './types';
import { useTableStore } from './use-table';

export const VIEW_ICONS: Record<ViewType, LucideIcon> = {
    grid: Sheet,
    board: SquareKanban,
    calendar: CalendarDays,
    gallery: LayoutGrid,
};

export const VIEW_LABELS: Record<ViewType, string> = {
    grid: 'Grid',
    board: 'Board',
    calendar: 'Calendar',
    gallery: 'Gallery',
};

const VIEW_COLORS: Record<ViewType, string> = {
    grid: 'text-blue-600 dark:text-blue-400',
    board: 'text-green-600 dark:text-green-400',
    calendar: 'text-red-600 dark:text-red-400',
    gallery: 'text-violet-600 dark:text-violet-400',
};

/** "Grid view", or "Grid view 2" when that's taken. */
function newViewName(type: ViewType, views: TableView[]): string {
    const base = `${VIEW_LABELS[type]} view`;
    const names = new Set(views.map((view) => view.name.toLowerCase()));

    if (!names.has(base.toLowerCase())) {
        return base;
    }

    let number = 2;

    while (names.has(`${base} ${number}`.toLowerCase())) {
        number += 1;
    }

    return `${base} ${number}`;
}

/** The view switcher: the current view, and a menu to switch, add, rename, duplicate, reorder and delete views. */
export function ViewMenu() {
    const store = useTableStore();
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [renaming, setRenaming] = useState<number | null>(null);
    const [deleting, setDeleting] = useState<TableView | null>(null);
    const [onlyMe, setOnlyMe] = useState(false);
    const { view, views, can } = store;
    const Icon = VIEW_ICONS[view.type];
    const needle = query.trim().toLowerCase();
    const matching = views.filter((candidate) =>
        candidate.name.toLowerCase().includes(needle),
    );
    const shared = matching.filter((candidate) => !candidate.personal);
    const personal = matching.filter((candidate) => candidate.personal);
    const sharedCount = views.filter((candidate) => !candidate.personal).length;
    const canReorder = can.manageViews && needle === '' && renaming === null;
    const { dragging, over, handleProps, rowProps } = useDragReorder(
        views.map((candidate) => String(candidate.id)),
        (ids) => void store.reorderViews(ids.map(Number)),
    );

    const mayChange = (candidate: TableView) =>
        candidate.personal || can.manageViews;

    const create = (type: ViewType) => {
        setOpen(false);
        void store.createView(newViewName(type, views), type, {
            personal: onlyMe || !can.manageViews,
        });
    };

    const duplicate = (candidate: TableView) => {
        setOpen(false);
        void store.createView(`${candidate.name} copy`, candidate.type, {
            personal: candidate.personal || !can.manageViews,
            duplicate: candidate.id,
        });
    };

    const onListKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') {
            return;
        }

        const items = [
            ...event.currentTarget.querySelectorAll<HTMLElement>(
                '[data-view-item]',
            ),
        ];
        const index = items.indexOf(document.activeElement as HTMLElement);
        const next =
            event.key === 'ArrowDown'
                ? items[Math.min(index + 1, items.length - 1)]
                : items[Math.max(index - 1, 0)];

        if (next) {
            event.preventDefault();
            next.focus();
        }
    };

    const renderView = (candidate: TableView) => {
        const ItemIcon = VIEW_ICONS[candidate.type];
        const id = String(candidate.id);
        const current = candidate.id === view.id;

        return (
            <div
                key={candidate.id}
                {...(canReorder ? rowProps(id) : {})}
                {...(canReorder ? handleProps(id) : {})}
                className={cn(
                    'group flex items-center gap-1 rounded-md pr-1',
                    current
                        ? 'bg-neutral-100 dark:bg-neutral-800'
                        : 'hover:bg-neutral-50 dark:hover:bg-neutral-900',
                    dragging === id && 'opacity-50',
                    over === id && dragging !== id && 'ring-2 ring-blue-500/40',
                )}
            >
                {renaming === candidate.id ? (
                    <div className="flex flex-1 items-center gap-2 px-2 py-1">
                        <ItemIcon
                            className={cn(
                                'size-4 shrink-0',
                                VIEW_COLORS[candidate.type],
                            )}
                        />
                        <RenameInput
                            name={candidate.name}
                            onDone={(name) => {
                                setRenaming(null);

                                if (name && name !== candidate.name) {
                                    void store.renameView(candidate.id, name);
                                }
                            }}
                        />
                    </div>
                ) : (
                    <button
                        type="button"
                        data-view-item
                        aria-current={current ? 'true' : undefined}
                        onClick={() => {
                            store.setViewId(candidate.id);
                            setOpen(false);
                        }}
                        onDoubleClick={() =>
                            mayChange(candidate) && setRenaming(candidate.id)
                        }
                        className="flex min-w-0 flex-1 items-center gap-2 rounded-md px-2 py-1.5 text-left text-[13px] outline-none focus-visible:ring-2 focus-visible:ring-blue-500/50"
                    >
                        <ItemIcon
                            className={cn(
                                'size-4 shrink-0',
                                VIEW_COLORS[candidate.type],
                            )}
                        />
                        <span className="truncate">{candidate.name}</span>
                        {current && (
                            <Check className="ml-auto size-3.5 shrink-0 text-neutral-500" />
                        )}
                    </button>
                )}
                {mayChange(candidate) && renaming !== candidate.id && (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <button
                                type="button"
                                aria-label={`Options for ${candidate.name}`}
                                className="flex size-6 shrink-0 items-center justify-center rounded text-neutral-400 opacity-0 group-hover:opacity-100 hover:bg-neutral-200 hover:text-neutral-700 focus-visible:opacity-100 data-[state=open]:opacity-100 dark:hover:bg-neutral-700 dark:hover:text-neutral-200"
                            >
                                <Ellipsis className="size-4" />
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent
                            align="start"
                            className="w-44"
                            onCloseAutoFocus={(event) => event.preventDefault()}
                        >
                            <DropdownMenuItem
                                onSelect={() => setRenaming(candidate.id)}
                            >
                                <Pencil />
                                Rename
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                onSelect={() => duplicate(candidate)}
                            >
                                <Copy />
                                Duplicate
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                disabled={
                                    !candidate.personal && sharedCount <= 1
                                }
                                onSelect={() => {
                                    setOpen(false);
                                    setDeleting(candidate);
                                }}
                                className="text-red-600 focus:text-red-600 dark:text-red-400 dark:focus:text-red-400"
                            >
                                <Trash2 className="text-current" />
                                Delete
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}
            </div>
        );
    };

    return (
        <>
            <Popover
                open={open}
                onOpenChange={(next) => {
                    setOpen(next);
                    setQuery('');
                    setRenaming(null);
                }}
            >
                <PopoverTrigger asChild>
                    <button
                        type="button"
                        aria-label={`View: ${view.name}`}
                        className="inline-flex h-7 max-w-64 shrink-0 items-center gap-1.5 rounded-md px-2 text-[13px] font-medium text-neutral-800 hover:bg-neutral-100 data-[state=open]:bg-neutral-100 dark:text-neutral-200 dark:hover:bg-neutral-800 dark:data-[state=open]:bg-neutral-800"
                    >
                        <Icon
                            className={cn(
                                'size-4 shrink-0',
                                VIEW_COLORS[view.type],
                            )}
                        />
                        <span className="truncate">{view.name}</span>
                        {view.personal && (
                            <Lock
                                aria-label="Only you see this view"
                                className="size-3 shrink-0 text-neutral-400"
                            />
                        )}
                        <ChevronDown className="size-3.5 shrink-0 text-neutral-500" />
                    </button>
                </PopoverTrigger>
                <PopoverContent className="w-72 p-0">
                    {views.length > 8 && (
                        <div className="border-b border-neutral-200 p-2 dark:border-neutral-800">
                            <input
                                autoFocus
                                value={query}
                                onChange={(event) =>
                                    setQuery(event.target.value)
                                }
                                placeholder="Find a view"
                                aria-label="Find a view"
                                className="h-7 w-full bg-transparent px-1 text-[13px] outline-none placeholder:text-neutral-400"
                            />
                        </div>
                    )}
                    <div
                        className="max-h-80 overflow-y-auto p-1.5"
                        onKeyDown={onListKeyDown}
                    >
                        {shared.map(renderView)}
                        {personal.length > 0 && (
                            <>
                                <p className="px-2 pt-2 pb-1 text-[11px] font-medium tracking-wide text-neutral-500 uppercase">
                                    My views
                                </p>
                                {personal.map(renderView)}
                            </>
                        )}
                        {matching.length === 0 && (
                            <p className="px-2 py-1.5 text-[13px] text-neutral-500">
                                No views match
                            </p>
                        )}
                    </div>
                    <div className="border-t border-neutral-200 p-1.5 dark:border-neutral-800">
                        <div className="flex items-center justify-between px-2 pt-1 pb-1.5">
                            <span className="text-[11px] font-medium tracking-wide text-neutral-500 uppercase">
                                Create
                            </span>
                            <label className="flex items-center gap-1.5 text-xs text-neutral-600 dark:text-neutral-400">
                                <Checkbox
                                    checked={onlyMe || !can.manageViews}
                                    disabled={!can.manageViews}
                                    onCheckedChange={(checked) =>
                                        setOnlyMe(checked === true)
                                    }
                                />
                                Only for me
                            </label>
                        </div>
                        <div className="grid grid-cols-2 gap-0.5">
                            {(Object.keys(VIEW_ICONS) as ViewType[]).map(
                                (type) => {
                                    const TypeIcon = VIEW_ICONS[type];

                                    return (
                                        <button
                                            key={type}
                                            type="button"
                                            onClick={() => create(type)}
                                            className="flex items-center gap-2 rounded-md px-2 py-1.5 text-left text-[13px] hover:bg-neutral-100 dark:hover:bg-neutral-800"
                                        >
                                            <TypeIcon
                                                className={cn(
                                                    'size-4',
                                                    VIEW_COLORS[type],
                                                )}
                                            />
                                            <span className="flex-1">
                                                {VIEW_LABELS[type]}
                                            </span>
                                            <Plus className="size-3.5 text-neutral-400" />
                                        </button>
                                    );
                                },
                            )}
                        </div>
                    </div>
                </PopoverContent>
            </Popover>
            <Dialog
                open={deleting !== null}
                onOpenChange={(next) => !next && setDeleting(null)}
            >
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Delete view</DialogTitle>
                        <DialogDescription>
                            Delete “{deleting?.name}”? Its filters, sorts and
                            settings go with it. The records stay.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setDeleting(null)}
                        >
                            Cancel
                        </Button>
                        <Button
                            variant="destructive"
                            autoFocus
                            onClick={() => {
                                if (deleting) {
                                    void store.deleteView(deleting.id);
                                }

                                setDeleting(null);
                            }}
                        >
                            Delete view
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

function RenameInput({
    name,
    onDone,
}: {
    name: string;
    onDone: (name: string | null) => void;
}) {
    const [value, setValue] = useState(name);
    const finished = useRef(false);

    const finish = (result: string | null) => {
        if (!finished.current) {
            finished.current = true;
            onDone(result);
        }
    };

    return (
        <input
            autoFocus
            value={value}
            aria-label="View name"
            onFocus={(event) => event.target.select()}
            onChange={(event) => setValue(event.target.value)}
            onBlur={() => finish(value.trim() || null)}
            onKeyDown={(event) => {
                event.stopPropagation();

                if (event.key === 'Enter') {
                    event.preventDefault();
                    finish(value.trim() || null);
                } else if (event.key === 'Escape') {
                    event.preventDefault();
                    finish(null);
                }
            }}
            className="h-6 min-w-0 flex-1 rounded border border-blue-500 bg-white px-1.5 text-[13px] outline-none dark:bg-neutral-950"
        />
    );
}
