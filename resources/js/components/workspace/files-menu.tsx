import {
    Download,
    EllipsisVertical,
    Eye,
    EyeOff,
    FilePlus,
    FolderPlus,
    FolderUp,
    PanelRightClose,
} from 'lucide-react';
import { useRef, useState } from 'react';
import { toast } from 'sonner';
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
    createWorkspaceEntry,
    downloadWorkspace,
    uploadWorkspaceFile,
} from '@/hooks/use-workspace-files';
import type { WorkspaceEntry } from '@/types';

/** Folders left out of uploads, like the server leaves them unexpanded. */
const SKIPPED = new Set(['node_modules', '.git', 'vendor', '.cache']);

/**
 * The "⋮" menu in the files panel header: create, upload, download, filter, close.
 */
export default function FilesMenu({
    projectId,
    disabled,
    hideHidden,
    onToggleHidden,
    onClose,
    onChanged,
    onCreatedFile,
}: {
    projectId: number;
    /** The sandbox isn't running, so nothing can be read or written. */
    disabled: boolean;
    hideHidden: boolean;
    onToggleHidden: () => void;
    onClose: () => void;
    /** Files changed; refresh the tree. */
    onChanged: () => void;
    onCreatedFile: (path: string) => void;
}) {
    const [creating, setCreating] = useState<WorkspaceEntry['type'] | null>(
        null,
    );
    /** Chosen from the menu; the dialog opens once the menu has fully closed. */
    const pendingCreate = useRef<WorkspaceEntry['type'] | null>(null);
    const folderInput = useRef<HTMLInputElement>(null);

    const upload = async (list: FileList) => {
        const files = Array.from(list).filter(
            (file) =>
                !file.webkitRelativePath
                    .split('/')
                    .some((part) => SKIPPED.has(part)),
        );

        if (files.length === 0) {
            return;
        }

        const id = toast.loading(`Uploading 0 of ${files.length} files…`);

        try {
            for (const [index, file] of files.entries()) {
                await uploadWorkspaceFile(
                    projectId,
                    file.webkitRelativePath || file.name,
                    file,
                );
                toast.loading(
                    `Uploading ${index + 1} of ${files.length} files…`,
                    { id },
                );
            }

            toast.success(
                `Uploaded ${files.length} ${files.length === 1 ? 'file' : 'files'}.`,
                { id },
            );
        } catch (e) {
            toast.error((e as Error).message, { id });
        } finally {
            onChanged();
        }
    };

    const download = () => {
        const id = toast.loading('Zipping your project…');

        downloadWorkspace(projectId)
            .then(() => toast.dismiss(id))
            .catch((e: Error) => toast.error(e.message, { id }));
    };

    return (
        <>
            <DropdownMenu modal={false}>
                <DropdownMenuTrigger asChild>
                    <button
                        type="button"
                        aria-label="File actions"
                        title="File actions"
                        data-test="files-menu"
                        className="rounded p-1 hover:bg-muted"
                    >
                        <EllipsisVertical className="size-3.5" />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    align="end"
                    // Open the dialog only after the closing menu lets go of
                    // focus, so the dialog's input keeps it.
                    onCloseAutoFocus={(event) => {
                        event.preventDefault();

                        if (pendingCreate.current) {
                            setCreating(pendingCreate.current);
                            pendingCreate.current = null;
                        }
                    }}
                >
                    <DropdownMenuItem
                        disabled={disabled}
                        onSelect={() => (pendingCreate.current = 'file')}
                        data-test="files-new-file"
                    >
                        <FilePlus />
                        New file
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        disabled={disabled}
                        onSelect={() => (pendingCreate.current = 'dir')}
                        data-test="files-new-folder"
                    >
                        <FolderPlus />
                        New folder
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        disabled={disabled}
                        onSelect={() => folderInput.current?.click()}
                        data-test="files-upload-folder"
                    >
                        <FolderUp />
                        Upload folder
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        disabled={disabled}
                        onSelect={download}
                        data-test="files-download"
                    >
                        <Download />
                        Download as zip
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        onSelect={onToggleHidden}
                        data-test="files-toggle-hidden"
                    >
                        {hideHidden ? <Eye /> : <EyeOff />}
                        {hideHidden ? 'Show hidden files' : 'Hide hidden files'}
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        onSelect={onClose}
                        data-test="files-close"
                    >
                        <PanelRightClose />
                        Close files
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <input
                ref={folderInput}
                type="file"
                multiple
                className="hidden"
                data-test="files-folder-input"
                // Not in React's types; lets the picker choose a whole folder.
                {...{ webkitdirectory: '' }}
                onChange={(event) => {
                    if (event.target.files) {
                        void upload(event.target.files);
                    }

                    event.target.value = '';
                }}
            />

            <NewEntryDialog
                projectId={projectId}
                type={creating}
                onDone={(path) => {
                    const type = creating;
                    setCreating(null);

                    if (path) {
                        onChanged();

                        if (type === 'file') {
                            onCreatedFile(path);
                        }
                    }
                }}
            />
        </>
    );
}

/**
 * Create an empty file or folder, at the top of the project or inside `parent`.
 */
export function NewEntryDialog({
    projectId,
    type,
    parent = '',
    onDone,
}: {
    projectId: number;
    type: WorkspaceEntry['type'] | null;
    /** The folder it goes in; empty for the top of the project. */
    parent?: string;
    /** Called with the new path, or null if cancelled. */
    onDone: (path: string | null) => void;
}) {
    const [path, setPath] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);
    const noun = type === 'dir' ? 'folder' : 'file';

    const close = (created: string | null) => {
        setPath('');
        setError(null);
        onDone(created);
    };

    const submit = async (event: React.FormEvent) => {
        event.preventDefault();

        const trimmed = path.trim().replace(/^\/+|\/+$/g, '');

        if (!type || trimmed === '') {
            return;
        }

        setSaving(true);

        const full = parent ? `${parent}/${trimmed}` : trimmed;

        try {
            await createWorkspaceEntry(projectId, full, type);
            close(full);
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setSaving(false);
        }
    };

    return (
        <Dialog
            open={type !== null}
            onOpenChange={(open) => !open && close(null)}
        >
            <DialogContent data-test="files-new-dialog">
                <form onSubmit={submit} className="space-y-4">
                    <DialogTitle>New {noun}</DialogTitle>
                    <DialogDescription>
                        A name, or a path inside{' '}
                        {parent ? <code>{parent}</code> : 'the project'} like{' '}
                        {type === 'dir' ? 'src/components' : 'src/utils.ts'}.
                        Missing folders are created too.
                    </DialogDescription>
                    <div className="space-y-1">
                        <Input
                            autoFocus
                            aria-label={`New ${noun} name`}
                            value={path}
                            onChange={(event) => {
                                setPath(event.target.value);
                                setError(null);
                            }}
                            placeholder={
                                type === 'dir' ? 'components' : 'notes.md'
                            }
                            data-test="files-new-path"
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
                            disabled={saving || path.trim() === ''}
                            data-test="files-new-submit"
                        >
                            Create {noun}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
