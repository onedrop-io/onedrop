import {
    ChevronRight,
    Copy,
    Download,
    EllipsisVertical,
    Eye,
    FilePlus,
    Folder,
    FolderPlus,
    FolderUp,
    RefreshCw,
    Search,
    Trash2,
    Upload,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { DragEvent, FormEvent } from 'react';
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
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import FileIcon from '@/components/workspace/file-icon';
import { useClipboard } from '@/hooks/use-clipboard';
import {
    downloadObject,
    droppedFiles,
    formatBytes,
    storageApi,
} from '@/lib/storage-api';
import type { DroppedFile } from '@/lib/storage-api';
import { cn } from '@/lib/utils';
import type { StorageListing, StorageObject } from '@/types';

/** Largest file the server accepts, matching WorkspaceStorage::MAX_UPLOAD_KILOBYTES. */
const MAX_UPLOAD_BYTES = 10_240 * 1024;

const PREVIEWABLE = /\.(png|jpe?g|gif|webp|avif|bmp)$/i;

/**
 * One bucket's objects: browse folders, search, upload (buttons or drag and drop), preview, download, delete.
 */
export default function ObjectsView({
    projectId,
    bucket,
    working,
    onChanged,
}: {
    projectId: number;
    bucket: string;
    /** The agent is running a task. */
    working: boolean;
    /** Objects were added or removed; refresh the bucket's totals. */
    onChanged: () => void;
}) {
    const [prefix, setPrefix] = useState('');
    const [search, setSearch] = useState('');
    const [query, setQuery] = useState('');
    const [listing, setListing] = useState<StorageListing | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const [dragging, setDragging] = useState(false);
    const [creatingFolder, setCreatingFolder] = useState(false);
    const [deleting, setDeleting] = useState<{
        path: string;
        folder: boolean;
    } | null>(null);
    const [previewing, setPreviewing] = useState<StorageObject | null>(null);
    const fileInput = useRef<HTMLInputElement>(null);
    const folderInput = useRef<HTMLInputElement>(null);
    const [, copy] = useClipboard();

    // Search after a short pause in typing.
    useEffect(() => {
        const timer = setTimeout(() => setQuery(search.trim()), 250);

        return () => clearTimeout(timer);
    }, [search]);

    const load = useCallback(() => {
        setLoading(true);

        return storageApi
            .objects(projectId, bucket, { prefix, search: query })
            .then((result) => {
                setListing(result);
                setError(null);
            })
            .catch((e: Error) => setError(e.message))
            .finally(() => setLoading(false));
    }, [projectId, bucket, prefix, query]);

    // Reload when the folder or search changes, and when a run starts or ends (the agent may store files).
    useEffect(() => {
        void load();
    }, [load, working]);

    const changed = () => {
        void load();
        onChanged();
    };

    const upload = async (files: DroppedFile[]) => {
        const tooLarge = files.filter((f) => f.file.size > MAX_UPLOAD_BYTES);
        const accepted = files.filter((f) => f.file.size <= MAX_UPLOAD_BYTES);

        if (tooLarge.length > 0) {
            toast.error(
                `Skipped ${tooLarge.map((f) => f.path).join(', ')}: files can be up to 10 MB.`,
            );
        }

        if (accepted.length === 0) {
            return;
        }

        const id = toast.loading(`Uploading 0 of ${accepted.length}…`);

        try {
            for (const [index, { file, path }] of accepted.entries()) {
                await storageApi.upload(projectId, bucket, prefix + path, file);
                toast.loading(`Uploading ${index + 1} of ${accepted.length}…`, {
                    id,
                });
            }

            toast.success(
                `Uploaded ${accepted.length} ${accepted.length === 1 ? 'file' : 'files'}.`,
                { id },
            );
        } catch (e) {
            toast.error((e as Error).message, { id });
        } finally {
            changed();
        }
    };

    const picked = (list: FileList | null) =>
        upload(
            Array.from(list ?? []).map((file) => ({
                file,
                path: file.webkitRelativePath || file.name,
            })),
        );

    const drop = (event: DragEvent) => {
        event.preventDefault();
        setDragging(false);
        void droppedFiles(event.dataTransfer).then(upload);
    };

    const download = (object: StorageObject) =>
        downloadObject(projectId, bucket, object).catch((e: Error) =>
            toast.error(e.message),
        );

    const copyPath = (path: string) =>
        copy(path).then((ok) => ok && toast.success(`Copied ${path}`));

    const searching = query !== '';
    const count =
        (listing?.folders.length ?? 0) + (listing?.objects.length ?? 0);
    const crumbs = prefix === '' ? [] : prefix.replace(/\/$/, '').split('/');

    return (
        <div className="@container space-y-3" data-test="storage-objects">
            <div className="flex flex-wrap items-center gap-2">
                <Button
                    onClick={() => fileInput.current?.click()}
                    data-test="storage-upload-files"
                >
                    <FilePlus /> Upload files
                </Button>
                <Button
                    variant="outline"
                    onClick={() => folderInput.current?.click()}
                    data-test="storage-upload-folder"
                >
                    <FolderUp /> Upload folder
                </Button>
                <Button
                    variant="outline"
                    onClick={() => setCreatingFolder(true)}
                    data-test="storage-create-folder"
                >
                    <FolderPlus /> Create folder
                </Button>
                <label className="flex h-9 min-w-48 flex-1 items-center gap-2 rounded-md border border-input px-3">
                    <Search className="size-4 text-muted-foreground" />
                    <input
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder="Search this bucket"
                        aria-label="Search this bucket"
                        className="min-w-0 flex-1 bg-transparent text-sm outline-none"
                        data-test="storage-search"
                    />
                </label>
                <Button
                    variant="ghost"
                    onClick={() => changed()}
                    data-test="storage-refresh"
                >
                    <RefreshCw className={cn(loading && 'animate-spin')} />{' '}
                    Refresh
                </Button>
            </div>

            <input
                ref={fileInput}
                type="file"
                multiple
                className="hidden"
                data-test="storage-file-input"
                onChange={(event) => {
                    void picked(event.target.files);
                    event.target.value = '';
                }}
            />
            <input
                ref={folderInput}
                type="file"
                multiple
                className="hidden"
                data-test="storage-folder-input"
                // Not in React's types; lets the picker choose a whole folder.
                {...{ webkitdirectory: '' }}
                onChange={(event) => {
                    void picked(event.target.files);
                    event.target.value = '';
                }}
            />

            <div className="flex items-center justify-between gap-2 text-sm">
                <nav
                    aria-label="Folder"
                    className="flex min-w-0 flex-wrap items-center gap-1 text-muted-foreground"
                    data-test="storage-breadcrumbs"
                >
                    {searching ? (
                        <span>Results for “{query}” in the whole bucket</span>
                    ) : (
                        <>
                            <button
                                type="button"
                                onClick={() => setPrefix('')}
                                className="flex items-center gap-1 hover:text-foreground"
                            >
                                <Folder className="size-4" /> {bucket}
                            </button>
                            {crumbs.map((part, index) => (
                                <span
                                    key={index}
                                    className="flex items-center gap-1"
                                >
                                    <ChevronRight className="size-3.5" />
                                    <button
                                        type="button"
                                        onClick={() =>
                                            setPrefix(
                                                `${crumbs.slice(0, index + 1).join('/')}/`,
                                            )
                                        }
                                        className="hover:text-foreground"
                                    >
                                        {part}
                                    </button>
                                </span>
                            ))}
                        </>
                    )}
                </nav>
                <span
                    className="shrink-0 text-xs text-muted-foreground"
                    data-test="storage-count"
                >
                    Total {count}
                </span>
            </div>

            <div
                onDragOver={(event) => {
                    event.preventDefault();
                    setDragging(true);
                }}
                onDragLeave={(event) => {
                    if (
                        !event.currentTarget.contains(
                            event.relatedTarget as Node | null,
                        )
                    ) {
                        setDragging(false);
                    }
                }}
                onDrop={drop}
                className={cn(
                    'min-h-80 rounded-xl border border-dashed border-sidebar-border transition-colors',
                    dragging && 'border-primary bg-primary/5',
                )}
                data-test="storage-drop"
            >
                {error ? (
                    <p
                        className="p-6 text-sm text-red-600"
                        data-test="storage-error"
                    >
                        {error}
                    </p>
                ) : !listing ? (
                    <p className="p-6 text-sm text-muted-foreground">
                        Loading…
                    </p>
                ) : count === 0 ? (
                    <button
                        type="button"
                        onClick={() => fileInput.current?.click()}
                        className="flex min-h-80 w-full flex-col items-center justify-center gap-1 text-center"
                        data-test="storage-objects-empty"
                    >
                        <Upload className="mb-2 size-5 text-muted-foreground" />
                        <span className="text-base font-medium">
                            {searching ? 'No matches' : 'No objects'}
                        </span>
                        <span className="text-sm text-muted-foreground">
                            {searching
                                ? 'Try another name.'
                                : 'Drag and drop files or folders here, or click to upload.'}
                        </span>
                    </button>
                ) : (
                    <table className="w-full text-sm">
                        <thead className="text-left text-xs text-muted-foreground">
                            <tr className="border-b border-sidebar-border/70 dark:border-sidebar-border">
                                <th className="px-3 py-2 font-normal">Name</th>
                                <th className="w-24 px-3 py-2 text-right font-normal">
                                    Size
                                </th>
                                <th className="hidden w-44 px-3 py-2 font-normal @2xl:table-cell">
                                    Modified
                                </th>
                                <th className="w-10" />
                            </tr>
                        </thead>
                        <tbody data-test="storage-list">
                            {listing.folders.map((folder) => (
                                <tr
                                    key={folder.path}
                                    className="border-b border-sidebar-border/40 last:border-0 hover:bg-muted/50"
                                    data-test={`storage-folder-${folder.name}`}
                                >
                                    <td className="px-3 py-1.5">
                                        <button
                                            type="button"
                                            onClick={() => {
                                                setPrefix(`${folder.path}/`);
                                                setSearch('');
                                            }}
                                            className="flex items-center gap-2 text-left hover:underline"
                                        >
                                            <FileIcon
                                                name={folder.name}
                                                isDir
                                            />
                                            {folder.name}
                                        </button>
                                    </td>
                                    <td className="px-3 py-1.5 text-right text-muted-foreground">
                                        —
                                    </td>
                                    <td className="hidden px-3 py-1.5 @2xl:table-cell" />
                                    <td className="px-1 py-1">
                                        <RowMenu
                                            name={folder.name}
                                            onCopy={() => copyPath(folder.path)}
                                            onDelete={() =>
                                                setDeleting({
                                                    path: folder.path,
                                                    folder: true,
                                                })
                                            }
                                        />
                                    </td>
                                </tr>
                            ))}
                            {listing.objects.map((object) => {
                                const previewable = PREVIEWABLE.test(
                                    object.name,
                                );

                                return (
                                    <tr
                                        key={object.path}
                                        className="border-b border-sidebar-border/40 last:border-0 hover:bg-muted/50"
                                        data-test={`storage-object-${object.name}`}
                                    >
                                        <td className="px-3 py-1.5">
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    previewable
                                                        ? setPreviewing(object)
                                                        : void download(object)
                                                }
                                                title={
                                                    previewable
                                                        ? 'Preview'
                                                        : 'Download'
                                                }
                                                className="flex min-w-0 items-center gap-2 text-left hover:underline"
                                            >
                                                <FileIcon name={object.name} />
                                                <span className="truncate">
                                                    {searching
                                                        ? object.path
                                                        : object.name}
                                                </span>
                                            </button>
                                        </td>
                                        <td className="px-3 py-1.5 text-right whitespace-nowrap text-muted-foreground tabular-nums">
                                            {formatBytes(object.size)}
                                        </td>
                                        <td className="hidden px-3 py-1.5 whitespace-nowrap text-muted-foreground @2xl:table-cell">
                                            {new Date(
                                                object.modified * 1000,
                                            ).toLocaleString(undefined, {
                                                dateStyle: 'medium',
                                                timeStyle: 'short',
                                            })}
                                        </td>
                                        <td className="px-1 py-1">
                                            <RowMenu
                                                name={object.name}
                                                onPreview={
                                                    previewable
                                                        ? () =>
                                                              setPreviewing(
                                                                  object,
                                                              )
                                                        : undefined
                                                }
                                                onDownload={() =>
                                                    void download(object)
                                                }
                                                onCopy={() =>
                                                    copyPath(object.path)
                                                }
                                                onDelete={() =>
                                                    setDeleting({
                                                        path: object.path,
                                                        folder: false,
                                                    })
                                                }
                                            />
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                )}
            </div>

            <NewFolderDialog
                open={creatingFolder}
                prefix={prefix}
                onClose={() => setCreatingFolder(false)}
                onCreate={(path) =>
                    storageApi
                        .createFolder(projectId, bucket, prefix + path)
                        .then(() => {
                            setCreatingFolder(false);
                            changed();
                        })
                }
            />
            <DeleteObjectDialog
                target={deleting}
                onClose={() => setDeleting(null)}
                onDelete={(path) =>
                    storageApi.delete(projectId, bucket, path).then(() => {
                        setDeleting(null);
                        changed();
                    })
                }
            />
            <Dialog
                open={previewing !== null}
                onOpenChange={(open) => !open && setPreviewing(null)}
            >
                <DialogContent
                    className="sm:max-w-3xl"
                    data-test="storage-preview"
                >
                    <DialogTitle className="truncate pr-6">
                        {previewing?.path}
                    </DialogTitle>
                    <DialogDescription>
                        {previewing && formatBytes(previewing.size)}
                    </DialogDescription>
                    {previewing && (
                        <img
                            src={storageApi.downloadUrl(
                                projectId,
                                bucket,
                                previewing.path,
                                true,
                            )}
                            alt={previewing.name}
                            className="max-h-[70vh] w-full rounded-md bg-muted object-contain"
                        />
                    )}
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => previewing && download(previewing)}
                        >
                            <Download /> Download
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}

function RowMenu({
    name,
    onPreview,
    onDownload,
    onCopy,
    onDelete,
}: {
    name: string;
    onPreview?: () => void;
    onDownload?: () => void;
    onCopy: () => void;
    onDelete: () => void;
}) {
    return (
        <DropdownMenu modal={false}>
            <DropdownMenuTrigger asChild>
                <Button
                    size="icon"
                    variant="ghost"
                    className="size-7"
                    aria-label={`Actions for ${name}`}
                    data-test={`storage-menu-${name}`}
                >
                    <EllipsisVertical />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                {onPreview && (
                    <DropdownMenuItem onSelect={onPreview}>
                        <Eye /> Preview
                    </DropdownMenuItem>
                )}
                {onDownload && (
                    <DropdownMenuItem
                        onSelect={onDownload}
                        data-test={`storage-download-${name}`}
                    >
                        <Download /> Download
                    </DropdownMenuItem>
                )}
                <DropdownMenuItem onSelect={onCopy}>
                    <Copy /> Copy path
                </DropdownMenuItem>
                <DropdownMenuItem
                    variant="destructive"
                    onSelect={onDelete}
                    data-test={`storage-delete-${name}`}
                >
                    <Trash2 /> Delete
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function NewFolderDialog({
    open,
    prefix,
    onClose,
    onCreate,
}: {
    open: boolean;
    prefix: string;
    onClose: () => void;
    onCreate: (path: string) => Promise<void>;
}) {
    const [name, setName] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (open) {
            setName('');
            setError(null);
        }
    }, [open]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const trimmed = name.trim().replace(/^\/+|\/+$/g, '');

        if (trimmed === '') {
            return;
        }

        setSaving(true);
        onCreate(trimmed)
            .catch((e: Error) => setError(e.message))
            .finally(() => setSaving(false));
    };

    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
            <DialogContent data-test="storage-folder-dialog">
                <form onSubmit={submit} className="space-y-4">
                    <DialogTitle>Create folder</DialogTitle>
                    <DialogDescription>
                        In {prefix === '' ? 'the top of the bucket' : prefix}.
                        Use slashes for nested folders, like{' '}
                        <code>2026/invoices</code>.
                    </DialogDescription>
                    <div className="space-y-1">
                        <Input
                            autoFocus
                            aria-label="Folder name"
                            value={name}
                            onChange={(event) => {
                                setName(event.target.value);
                                setError(null);
                            }}
                            placeholder="avatars"
                            data-test="storage-folder-name"
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
                            disabled={saving || name.trim() === ''}
                            data-test="storage-folder-submit"
                        >
                            Create folder
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function DeleteObjectDialog({
    target,
    onClose,
    onDelete,
}: {
    target: { path: string; folder: boolean } | null;
    onClose: () => void;
    onDelete: (path: string) => Promise<void>;
}) {
    const [error, setError] = useState<string | null>(null);
    const [deleting, setDeleting] = useState(false);

    useEffect(() => setError(null), [target]);

    return (
        <Dialog
            open={target !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent data-test="storage-delete-dialog">
                <DialogTitle className="truncate pr-6">
                    Delete {target?.path}?
                </DialogTitle>
                <DialogDescription>
                    {target?.folder
                        ? 'This permanently deletes the folder and everything in it.'
                        : 'This permanently deletes the object.'}{' '}
                    Your app can’t get it back.
                </DialogDescription>
                <InputError message={error ?? undefined} />
                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button type="button" variant="secondary">
                            Cancel
                        </Button>
                    </DialogClose>
                    <Button
                        variant="destructive"
                        disabled={deleting}
                        onClick={() => {
                            if (!target) {
                                return;
                            }

                            setDeleting(true);
                            onDelete(target.path)
                                .catch((e: Error) => setError(e.message))
                                .finally(() => setDeleting(false));
                        }}
                        data-test="storage-delete-submit"
                    >
                        Delete
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
