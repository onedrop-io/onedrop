import {
    ChevronsDownUp,
    ClipboardCopy,
    Download,
    EllipsisVertical,
    FilePlus,
    FolderPlus,
    Link,
    Pencil,
    Search,
    SquareTerminal,
    Trash2,
} from 'lucide-react';
import { useRef, useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import {
    deleteWorkspaceEntry,
    moveWorkspaceEntry,
} from '@/hooks/use-workspace-files';
import { cn } from '@/lib/utils';
import type { WorkspaceEntry } from '@/types';

export type FileEntryAction =
    | 'rename'
    | 'search'
    | 'new-file'
    | 'new-folder'
    | 'collapse'
    | 'shell'
    | 'copy-path'
    | 'copy-link'
    | 'download'
    | 'delete';

/** Actions that open a dialog, an input or the Shell: run once the menu has let go of focus. */
const AFTER_CLOSE = new Set<FileEntryAction>([
    'rename',
    'search',
    'new-file',
    'new-folder',
    'shell',
    'delete',
]);

/**
 * The "⋮" menu on a row of the file tree (FILE-005). Also opens on right-click, through `open`.
 */
export default function FileEntryMenu({
    entry,
    open,
    onOpenChange,
    canOpenShell,
    onAction,
}: {
    entry: WorkspaceEntry;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    canOpenShell: boolean;
    onAction: (action: FileEntryAction) => void;
}) {
    const pending = useRef<FileEntryAction | null>(null);
    const isDir = entry.type === 'dir';

    const item = (
        action: FileEntryAction,
        icon: React.ReactNode,
        label: string,
        variant?: 'destructive',
    ) => (
        <DropdownMenuItem
            variant={variant}
            onSelect={() =>
                AFTER_CLOSE.has(action)
                    ? (pending.current = action)
                    : onAction(action)
            }
            data-test={`file-action-${action}`}
        >
            {icon}
            {label}
        </DropdownMenuItem>
    );

    return (
        <DropdownMenu modal={false} open={open} onOpenChange={onOpenChange}>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    aria-label={`Actions for ${entry.path}`}
                    title="Actions"
                    data-test={`file-menu-${entry.path}`}
                    className={cn(
                        'absolute top-1/2 right-1 -translate-y-1/2 rounded p-0.5 text-muted-foreground opacity-0 group-hover:opacity-100 hover:bg-background hover:text-foreground focus-visible:opacity-100',
                        open && 'opacity-100',
                    )}
                >
                    <EllipsisVertical className="size-3.5" />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="start"
                className="min-w-52"
                data-test="file-entry-menu"
                // Open a dialog or input only after the closing menu lets go of focus, so it keeps it.
                onCloseAutoFocus={(event) => {
                    if (pending.current) {
                        event.preventDefault();
                        onAction(pending.current);
                        pending.current = null;
                    }
                }}
            >
                {item('rename', <Pencil />, 'Rename')}
                {isDir && item('search', <Search />, 'Search this folder')}
                <DropdownMenuSeparator />
                {isDir && (
                    <>
                        {item('new-file', <FilePlus />, 'Add file')}
                        {item('new-folder', <FolderPlus />, 'Add folder')}
                        {item(
                            'collapse',
                            <ChevronsDownUp />,
                            'Collapse child folders',
                        )}
                        <DropdownMenuSeparator />
                    </>
                )}
                {canOpenShell && (
                    <>
                        {item('shell', <SquareTerminal />, 'Open shell here')}
                        <DropdownMenuSeparator />
                    </>
                )}
                {item('copy-path', <ClipboardCopy />, 'Copy path')}
                {!isDir && item('copy-link', <Link />, 'Copy link')}
                <DropdownMenuSeparator />
                {item(
                    'download',
                    <Download />,
                    isDir ? 'Download folder' : 'Download file',
                )}
                <DropdownMenuSeparator />
                {item('delete', <Trash2 />, 'Delete', 'destructive')}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/**
 * Rename a file or folder; a name with slashes moves it into (new) folders under the same parent.
 */
export function RenameEntryDialog({
    projectId,
    entry,
    onDone,
}: {
    projectId: number;
    entry: WorkspaceEntry | null;
    /** Called with the new path, or null if cancelled. */
    onDone: (path: string | null) => void;
}) {
    return (
        <Dialog
            open={entry !== null}
            onOpenChange={(open) => !open && onDone(null)}
        >
            {entry && (
                <RenameForm
                    projectId={projectId}
                    entry={entry}
                    onDone={onDone}
                />
            )}
        </Dialog>
    );
}

function RenameForm({
    projectId,
    entry,
    onDone,
}: {
    projectId: number;
    entry: WorkspaceEntry;
    onDone: (path: string | null) => void;
}) {
    const slash = entry.path.lastIndexOf('/');
    const parent = slash === -1 ? '' : entry.path.slice(0, slash);
    const original = entry.path.slice(slash + 1);
    const [name, setName] = useState(original);
    const [error, setError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);
    const trimmed = name.trim().replace(/^\/+|\/+$/g, '');

    const submit = async (event: React.FormEvent) => {
        event.preventDefault();

        if (trimmed === '' || trimmed === original) {
            return;
        }

        const to = parent ? `${parent}/${trimmed}` : trimmed;
        setSaving(true);

        try {
            await moveWorkspaceEntry(projectId, entry.path, to);
            onDone(to);
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setSaving(false);
        }
    };

    return (
        <DialogContent data-test="file-rename-dialog">
            <form onSubmit={submit} className="space-y-4">
                <DialogTitle>
                    Rename {entry.type === 'dir' ? 'folder' : 'file'}
                </DialogTitle>
                <DialogDescription>
                    {parent ? (
                        <>
                            Inside <code>{parent}</code>.
                        </>
                    ) : (
                        'At the top of the project.'
                    )}{' '}
                    Use slashes to move it into a folder.
                </DialogDescription>
                <div className="space-y-1">
                    <Input
                        autoFocus
                        aria-label="New name"
                        value={name}
                        // Select the name without its extension, like most editors.
                        onFocus={(event) => {
                            const dot = original.lastIndexOf('.');
                            event.target.setSelectionRange(
                                0,
                                entry.type === 'file' && dot > 0
                                    ? dot
                                    : original.length,
                            );
                        }}
                        onChange={(event) => {
                            setName(event.target.value);
                            setError(null);
                        }}
                        data-test="file-rename-name"
                    />
                    <InputError message={error ?? undefined} />
                </div>
                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button type="button" variant="secondary">
                            Cancel
                        </Button>
                    </DialogClose>
                    <Button
                        type="submit"
                        disabled={
                            saving || trimmed === '' || trimmed === original
                        }
                        data-test="file-rename-submit"
                    >
                        Rename
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    );
}

/**
 * Confirm deleting a file, or a folder and everything in it.
 */
export function DeleteEntryDialog({
    projectId,
    entry,
    onDone,
}: {
    projectId: number;
    entry: WorkspaceEntry | null;
    /** Called with the deleted path, or null if cancelled. */
    onDone: (path: string | null) => void;
}) {
    const [error, setError] = useState<string | null>(null);
    const [deleting, setDeleting] = useState(false);

    const close = (deleted: string | null) => {
        setError(null);
        onDone(deleted);
    };

    const remove = async () => {
        if (!entry) {
            return;
        }

        setDeleting(true);

        try {
            await deleteWorkspaceEntry(projectId, entry.path);
            close(entry.path);
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setDeleting(false);
        }
    };

    return (
        <Dialog
            open={entry !== null}
            onOpenChange={(open) => !open && close(null)}
        >
            <DialogContent data-test="file-delete-dialog">
                <DialogTitle>
                    Delete {entry?.type === 'dir' ? 'folder' : 'file'}?
                </DialogTitle>
                <DialogDescription>
                    <code>{entry?.path}</code>
                    {entry?.type === 'dir' && ' and everything in it'} will be
                    deleted from the sandbox. Committed files can be brought
                    back from git; anything else is gone.
                </DialogDescription>
                <InputError message={error ?? undefined} />
                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button type="button" variant="secondary" autoFocus>
                            Cancel
                        </Button>
                    </DialogClose>
                    <Button
                        type="button"
                        variant="destructive"
                        disabled={deleting}
                        onClick={() => void remove()}
                        data-test="file-delete-submit"
                    >
                        Delete
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
