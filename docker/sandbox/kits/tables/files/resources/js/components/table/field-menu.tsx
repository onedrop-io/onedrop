import {
    ArrowDownNarrowWide,
    ArrowLeftToLine,
    ArrowRightToLine,
    ArrowUpNarrowWide,
    Copy,
    Eye,
    EyeOff,
    ListFilter,
    Pencil,
    Rows3,
    Trash2,
} from 'lucide-react';
import { useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
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
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { FieldEditorPopover } from './field-editor';
import { operatorsFor } from './filters';
import type { Field } from './types';
import { useTableStore } from './use-table';

type Editor =
    | { mode: 'edit' }
    | { mode: 'insert'; before?: string; after?: string };

function conditionId(): string {
    return `c${Date.now().toString(36)}${Math.random().toString(36).slice(2, 7)}`;
}

/**
 * A grid column header's menu: edit, duplicate, insert, sort, filter, group, hide and delete the field.
 * `children` is the trigger (the header's label and chevron). `className` styles the wrapper the field editor
 * opens from.
 */
export function FieldMenu({
    field,
    children,
    className = 'flex h-full min-w-0 flex-1 items-center',
}: {
    field: Field;
    children: ReactNode;
    className?: string;
}) {
    const store = useTableStore();
    const [editor, setEditor] = useState<Editor | null>(null);
    const [deleting, setDeleting] = useState(false);
    const [removing, setRemoving] = useState(false);
    // Opened once the menu has closed, so the menu doesn't take focus back from the editor or dialog.
    const pending = useRef<(() => void) | null>(null);

    const config = store.view.config;
    const manage = store.can.manageFields;
    const editable = manage && !field.builtIn;
    const groupedByIt = config.groups.some((rule) => rule.field === field.key);
    const canGroup =
        store.view.type === 'grid' && !groupedByIt && config.groups.length < 3;

    function later(action: () => void): void {
        pending.current = action;
    }

    function sort(direction: 'asc' | 'desc'): void {
        store.updateView({ sorts: [{ field: field.key, direction }] });
    }

    function filter(): void {
        const operator = operatorsFor(field)[0];

        store.updateView({
            filters: {
                ...config.filters,
                conditions: [
                    ...config.filters.conditions,
                    { id: conditionId(), field: field.key, operator },
                ],
            },
        });
    }

    async function remove(): Promise<void> {
        setRemoving(true);
        await store.deleteField(field.key);
        setRemoving(false);
        setDeleting(false);
    }

    const menu = (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>{children}</DropdownMenuTrigger>
            <DropdownMenuContent
                align="start"
                className="w-60"
                onCloseAutoFocus={(event) => {
                    const action = pending.current;

                    if (action) {
                        event.preventDefault();
                        pending.current = null;
                        action();
                    }
                }}
            >
                {field.description && (
                    <>
                        <p className="px-2 py-1.5 text-xs whitespace-pre-wrap text-neutral-500 dark:text-neutral-400">
                            {field.description}
                        </p>
                        <DropdownMenuSeparator />
                    </>
                )}
                {editable ? (
                    <DropdownMenuItem
                        onSelect={() =>
                            later(() => setEditor({ mode: 'edit' }))
                        }
                    >
                        <Pencil />
                        Edit field
                    </DropdownMenuItem>
                ) : (
                    <DropdownMenuItem
                        onSelect={() =>
                            later(() => setEditor({ mode: 'edit' }))
                        }
                    >
                        <Eye />
                        View field
                    </DropdownMenuItem>
                )}
                {editable && (
                    <DropdownMenuItem
                        onSelect={() => void store.duplicateField(field.key)}
                    >
                        <Copy />
                        Duplicate field
                    </DropdownMenuItem>
                )}
                {manage && (
                    <>
                        <DropdownMenuItem
                            disabled={field.primary}
                            onSelect={() =>
                                later(() =>
                                    setEditor({
                                        mode: 'insert',
                                        before: field.key,
                                    }),
                                )
                            }
                        >
                            <ArrowLeftToLine />
                            Insert left
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            onSelect={() =>
                                later(() =>
                                    setEditor({
                                        mode: 'insert',
                                        after: field.key,
                                    }),
                                )
                            }
                        >
                            <ArrowRightToLine />
                            Insert right
                        </DropdownMenuItem>
                    </>
                )}
                <DropdownMenuSeparator />
                <DropdownMenuItem onSelect={() => sort('asc')}>
                    <ArrowDownNarrowWide />
                    Sort first → last
                </DropdownMenuItem>
                <DropdownMenuItem onSelect={() => sort('desc')}>
                    <ArrowUpNarrowWide />
                    Sort last → first
                </DropdownMenuItem>
                <DropdownMenuItem onSelect={filter}>
                    <ListFilter />
                    Filter by this field
                </DropdownMenuItem>
                {store.view.type === 'grid' && (
                    <DropdownMenuItem
                        disabled={!canGroup}
                        onSelect={() =>
                            store.updateView({
                                groups: [
                                    ...config.groups,
                                    { field: field.key, direction: 'asc' },
                                ],
                            })
                        }
                    >
                        <Rows3 />
                        Group by this field
                    </DropdownMenuItem>
                )}
                {!field.primary && (
                    <>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            onSelect={() =>
                                store.updateView({
                                    hidden: [
                                        ...new Set([
                                            ...config.hidden,
                                            field.key,
                                        ]),
                                    ],
                                })
                            }
                        >
                            <EyeOff />
                            Hide field
                        </DropdownMenuItem>
                    </>
                )}
                {editable && (
                    <>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            onSelect={() => later(() => setDeleting(true))}
                            className="text-red-600 focus:text-red-700 dark:text-red-400 dark:focus:text-red-300 [&_svg]:!text-current"
                        >
                            <Trash2 />
                            Delete field
                        </DropdownMenuItem>
                    </>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );

    return (
        <>
            <FieldEditorPopover
                field={editor?.mode === 'edit' ? field : undefined}
                open={editor !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setEditor(null);
                    }
                }}
                anchor={menu}
                anchorClassName={className}
                insertBefore={
                    editor?.mode === 'insert' ? editor.before : undefined
                }
                insertAfter={
                    editor?.mode === 'insert' ? editor.after : undefined
                }
            />
            <Dialog
                open={deleting}
                onOpenChange={(open) => !removing && setDeleting(open)}
            >
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Delete “{field.name}”?</DialogTitle>
                        <DialogDescription>
                            Its values in every record will be deleted.
                            Formulas, lookups and rollups that use it will show
                            an error.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setDeleting(false)}
                            disabled={removing}
                        >
                            Cancel
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={() => void remove()}
                            disabled={removing}
                            autoFocus
                        >
                            {removing ? 'Deleting…' : 'Delete field'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
