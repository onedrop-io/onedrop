import { Head, Link, router, setLayoutProps } from '@inertiajs/react';
import {
    ChevronRight,
    Download,
    FolderInput,
    FolderPlus,
    FolderUp,
    HardDrive,
    MoreHorizontal,
    Pencil,
    RotateCcw,
    Trash2,
    Upload,
    User,
    Users,
    Building2,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { DragEvent, ReactNode } from 'react';
import FileIcon from '@/components/workspace/file-icon';
import { Button } from '@/components/ui/button';
import {
    Dialog,
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
import { Spinner } from '@/components/ui/spinner';
import { uploadFile, ensureFolders } from '@/lib/drive-upload';
import type { DriveEntry } from '@/lib/drive-upload';
import { jsonRequest } from '@/lib/json-request';
import { usePrivateChannel } from '@/lib/realtime';
import { droppedFiles, formatBytes } from '@/lib/storage-api';
import type { DroppedFile } from '@/lib/storage-api';
import { cn } from '@/lib/utils';
import { useOrganization } from '@/hooks/use-organization';
import {
    index as driveIndex,
    show as driveShow,
    folders as driveFolders,
} from '@/routes/drive';
import { store as storeFolder } from '@/routes/drive/folders';
import {
    destroy as trashItem,
    download as downloadItem,
    purge as purgeItem,
    restore as restoreItem,
    update as updateItem,
    view as viewItem,
} from '@/routes/drive/items';
import { text as itemText } from '@/routes/drive/items';
import { update as saveItemText } from '@/routes/drive/items/text';
import { destroy as emptyTrash } from '@/routes/drive/trash';

type SpaceKind = 'personal' | 'organization' | 'group';
type Space = { key: string; name: string; kind: SpaceKind };
type Crumb = { id: number; name: string };
type Upload = {
    id: number;
    name: string;
    size: number;
    sent: number;
    error?: string;
};

type Props = {
    spaces: Space[];
    space: Space;
    folder: Crumb | null;
    breadcrumbs: Crumb[];
    trash: boolean;
    items: DriveEntry[];
    usage: { used: number; quota: number | null };
    partBytes: number;
    maxFileBytes: number;
    channel: { organization: number; user: number };
    trashDays: number;
};

const SPACE_ICONS: Record<SpaceKind, typeof User> = {
    personal: User,
    organization: Building2,
    group: Users,
};

/** What the viewer shows a file as, from its type. */
function viewKind(
    item: DriveEntry,
): 'image' | 'video' | 'audio' | 'pdf' | 'text' {
    const type = item.mime_type ?? '';

    if (type.startsWith('image/')) {
        return 'image';
    }

    if (type.startsWith('video/')) {
        return 'video';
    }

    if (type.startsWith('audio/')) {
        return 'audio';
    }

    return type === 'application/pdf' ? 'pdf' : 'text';
}

function when(iso: string | null | undefined): string {
    if (!iso) {
        return '';
    }

    const date = new Date(iso);
    const seconds = (Date.now() - date.getTime()) / 1000;

    if (seconds < 60) {
        return 'just now';
    }

    if (seconds < 3600) {
        return `${Math.floor(seconds / 60)} min ago`;
    }

    if (seconds < 86400) {
        return `${Math.floor(seconds / 3600)} h ago`;
    }

    return date.toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        year:
            date.getFullYear() === new Date().getFullYear()
                ? undefined
                : 'numeric',
    });
}

/**
 * Drive (DRIVE-001, DRIVE-002): the user's My Drive, their organization's drive and their groups' drives, with
 * uploads (files and folders, by button or drop), folders, renaming, moving, downloads, a viewer and editor, and Trash.
 */
export default function DriveShow({
    spaces,
    space,
    folder,
    breadcrumbs,
    trash,
    items,
    usage,
    partBytes,
    maxFileBytes,
    channel,
    trashDays,
}: Props) {
    const organization = useOrganization();
    const slug = organization.slug;
    const parentId = folder?.id ?? null;
    const [uploads, setUploads] = useState<Upload[]>([]);
    const [dropping, setDropping] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [naming, setNaming] = useState<
        { mode: 'folder' } | { mode: 'rename'; item: DriveEntry } | null
    >(null);
    const [moving, setMoving] = useState<DriveEntry | null>(null);
    const [viewing, setViewing] = useState<DriveEntry | null>(null);
    const [dragged, setDragged] = useState<DriveEntry | null>(null);
    const fileInput = useRef<HTMLInputElement>(null);
    const folderInput = useRef<HTMLInputElement>(null);
    const nextUpload = useRef(1);

    setLayoutProps({
        breadcrumbs: [{ title: 'Drive', href: driveIndex(slug) }],
    });

    const reload = () =>
        router.reload({ only: ['items', 'usage', 'breadcrumbs', 'folder'] });

    // Changes made anywhere (another person, a computer, a project's agent) show up here (DRIVE-001).
    const onChange = (payload: { space: string }) => {
        if (payload.space === space.key) {
            reload();
        }
    };
    const shared = usePrivateChannel(`drive.${channel.organization}`, {
        DriveChanged: onChange,
    });
    const own = usePrivateChannel(
        `drive.${channel.organization}.user.${channel.user}`,
        { DriveChanged: onChange },
    );

    // Without live updates, look every 15 seconds.
    useEffect(() => {
        if (shared && own) {
            return;
        }

        const timer = window.setInterval(reload, 15000);

        return () => window.clearInterval(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [shared, own, space.key, parentId]);

    const go = (key: string, folderId: number | null = null, inTrash = false) =>
        router.visit(
            driveShow.url(
                {
                    organization: slug,
                    space: key,
                    folder: folderId ?? undefined,
                },
                { query: inTrash ? { trash: 1 } : {} },
            ),
        );

    const upload = async (files: DroppedFile[]) => {
        setError(null);
        const folders = new Map<string, number | null>();
        const queued = files.map(({ file, path }) => ({
            id: nextUpload.current++,
            file,
            path,
        }));

        setUploads((current) => [
            ...current,
            ...queued.map(({ id, file }) => ({
                id,
                name: file.name,
                size: file.size,
                sent: 0,
            })),
        ]);

        for (const { id, file, path } of queued) {
            const update = (change: Partial<Upload>) =>
                setUploads((current) =>
                    current.map((entry) =>
                        entry.id === id ? { ...entry, ...change } : entry,
                    ),
                );

            try {
                if (file.size > maxFileBytes) {
                    throw new Error(
                        `Files can be up to ${formatBytes(maxFileBytes)}.`,
                    );
                }

                const dir = path.includes('/')
                    ? path.slice(0, path.lastIndexOf('/'))
                    : '';
                const parent = dir
                    ? await ensureFolders(
                          slug,
                          space.key,
                          parentId,
                          dir,
                          folders,
                      )
                    : parentId;

                await uploadFile(
                    slug,
                    space.key,
                    parent,
                    file,
                    partBytes,
                    (sent) => update({ sent }),
                );
                update({ sent: file.size });
            } catch (failure) {
                update({ error: (failure as Error).message });
            }
        }

        reload();
        // Finished uploads leave the list after a moment; failed ones stay until dismissed.
        window.setTimeout(
            () =>
                setUploads((current) =>
                    current.filter(
                        (entry) =>
                            entry.error ||
                            !queued.some(
                                (queuedFile) => queuedFile.id === entry.id,
                            ),
                    ),
                ),
            2500,
        );
    };

    const onDrop = async (event: DragEvent) => {
        event.preventDefault();
        setDropping(false);

        if (dragged || trash) {
            return;
        }

        void upload(await droppedFiles(event.dataTransfer));
    };

    const moveInto = (item: DriveEntry, target: DriveEntry) => {
        if (item.id === target.id || !target.folder) {
            return;
        }

        router.patch(
            updateItem.url({ organization: slug, item: item.id }),
            { space: space.key, parent_id: target.id },
            {
                preserveScroll: true,
                onError: (errors) => setError(errors.drive ?? null),
            },
        );
    };

    const open = (item: DriveEntry) =>
        item.folder ? go(space.key, item.id) : setViewing(item);

    const SpaceIcon = SPACE_ICONS[space.kind];

    return (
        <>
            <Head title={trash ? `Trash · ${space.name}` : space.name} />
            <div className="flex min-h-0 flex-1 flex-col gap-4 p-4 md:flex-row">
                <nav
                    className="flex shrink-0 flex-col gap-1 text-sm md:w-56"
                    aria-label="Drives"
                    data-test="drive-spaces"
                >
                    {spaces.map((candidate) => {
                        const Icon = SPACE_ICONS[candidate.kind];

                        return (
                            <Link
                                key={candidate.key}
                                href={driveShow({
                                    organization: slug,
                                    space: candidate.key,
                                })}
                                className={cn(
                                    'flex items-center gap-2 rounded-md px-2 py-1.5 hover:bg-muted',
                                    candidate.key === space.key &&
                                        !trash &&
                                        'bg-muted font-medium',
                                )}
                                data-test={`drive-space-${candidate.key}`}
                            >
                                <Icon className="size-4 text-muted-foreground" />
                                <span className="truncate">
                                    {candidate.name}
                                </span>
                            </Link>
                        );
                    })}
                    <Link
                        href={driveShow.url(
                            { organization: slug, space: space.key },
                            { query: { trash: 1 } },
                        )}
                        className={cn(
                            'mt-2 flex items-center gap-2 rounded-md px-2 py-1.5 hover:bg-muted',
                            trash && 'bg-muted font-medium',
                        )}
                        data-test="drive-trash"
                    >
                        <Trash2 className="size-4 text-muted-foreground" />
                        Trash
                    </Link>
                    <div
                        className="mt-4 px-2 text-xs text-muted-foreground"
                        data-test="drive-usage"
                    >
                        <HardDrive className="mr-1 inline size-3" />
                        {usage.quota
                            ? `${formatBytes(usage.used)} of ${formatBytes(usage.quota)} used`
                            : `${formatBytes(usage.used)} used`}
                        {usage.quota && (
                            <div className="mt-1 h-1 overflow-hidden rounded-full bg-muted">
                                <div
                                    className="h-full bg-primary"
                                    style={{
                                        width: `${Math.min(100, (usage.used / usage.quota) * 100)}%`,
                                    }}
                                />
                            </div>
                        )}
                    </div>
                </nav>

                <section
                    className={cn(
                        'relative flex min-h-0 min-w-0 flex-1 flex-col rounded-xl border border-sidebar-border/70 dark:border-sidebar-border',
                        dropping && 'ring-2 ring-primary',
                    )}
                    onDragOver={(event) => {
                        if (!dragged && !trash) {
                            event.preventDefault();
                            setDropping(true);
                        }
                    }}
                    onDragLeave={(event) => {
                        if (
                            !event.currentTarget.contains(
                                event.relatedTarget as Node,
                            )
                        ) {
                            setDropping(false);
                        }
                    }}
                    onDrop={onDrop}
                    data-test="drive-list"
                >
                    <div className="flex flex-wrap items-center gap-2 border-b border-sidebar-border/70 px-3 py-2 dark:border-sidebar-border">
                        <div className="flex min-w-0 flex-1 items-center gap-1 text-sm">
                            <SpaceIcon className="size-4 shrink-0 text-muted-foreground" />
                            <button
                                type="button"
                                className="truncate font-medium hover:underline"
                                onClick={() => go(space.key)}
                            >
                                {space.name}
                            </button>
                            {trash && (
                                <>
                                    <ChevronRight className="size-4 shrink-0 text-muted-foreground" />
                                    <span className="font-medium">Trash</span>
                                </>
                            )}
                            {[...breadcrumbs, ...(folder ? [folder] : [])].map(
                                (crumb) => (
                                    <span
                                        key={crumb.id}
                                        className="flex min-w-0 items-center gap-1"
                                    >
                                        <ChevronRight className="size-4 shrink-0 text-muted-foreground" />
                                        <button
                                            type="button"
                                            className="truncate hover:underline"
                                            onClick={() =>
                                                go(space.key, crumb.id)
                                            }
                                            onDragOver={(event) =>
                                                dragged &&
                                                event.preventDefault()
                                            }
                                            onDrop={(event) => {
                                                event.preventDefault();

                                                if (dragged) {
                                                    moveInto(dragged, {
                                                        ...dragged,
                                                        id: crumb.id,
                                                        folder: true,
                                                    });
                                                }
                                            }}
                                        >
                                            {crumb.name}
                                        </button>
                                    </span>
                                ),
                            )}
                        </div>
                        {trash ? (
                            items.length > 0 && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    data-test="drive-empty-trash"
                                    onClick={() => {
                                        if (
                                            window.confirm(
                                                'Delete everything in Trash for good?',
                                            )
                                        ) {
                                            router.delete(
                                                emptyTrash.url({
                                                    organization: slug,
                                                    space: space.key,
                                                }),
                                            );
                                        }
                                    }}
                                >
                                    Empty Trash
                                </Button>
                            )
                        ) : (
                            <>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        setNaming({ mode: 'folder' })
                                    }
                                    data-test="drive-new-folder"
                                >
                                    <FolderPlus className="size-4" />
                                    New folder
                                </Button>
                                <DropdownMenu modal={false}>
                                    <DropdownMenuTrigger asChild>
                                        <Button
                                            size="sm"
                                            data-test="drive-upload"
                                        >
                                            <Upload className="size-4" />
                                            Upload
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end">
                                        <DropdownMenuItem
                                            onSelect={() =>
                                                fileInput.current?.click()
                                            }
                                        >
                                            <Upload />
                                            Files
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            onSelect={() =>
                                                folderInput.current?.click()
                                            }
                                        >
                                            <FolderUp />
                                            Folder
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                                <input
                                    ref={fileInput}
                                    type="file"
                                    multiple
                                    hidden
                                    data-test="drive-file-input"
                                    onChange={(event) => {
                                        const files = Array.from(
                                            event.target.files ?? [],
                                        );
                                        event.target.value = '';
                                        void upload(
                                            files.map((file) => ({
                                                file,
                                                path: file.name,
                                            })),
                                        );
                                    }}
                                />
                                <input
                                    ref={folderInput}
                                    type="file"
                                    hidden
                                    {...({ webkitdirectory: '' } as Record<
                                        string,
                                        string
                                    >)}
                                    onChange={(event) => {
                                        const files = Array.from(
                                            event.target.files ?? [],
                                        );
                                        event.target.value = '';
                                        void upload(
                                            files.map((file) => ({
                                                file,
                                                path:
                                                    file.webkitRelativePath ||
                                                    file.name,
                                            })),
                                        );
                                    }}
                                />
                            </>
                        )}
                    </div>

                    {error && (
                        <p
                            className="border-b border-red-500/20 bg-red-500/10 px-3 py-2 text-sm text-red-600"
                            data-test="drive-error"
                        >
                            {error}
                        </p>
                    )}
                    {trash && (
                        <p className="border-b border-sidebar-border/70 px-3 py-2 text-xs text-muted-foreground dark:border-sidebar-border">
                            Deleted files and folders stay here for {trashDays}{' '}
                            days, then they're deleted for good.
                        </p>
                    )}

                    <div className="min-h-0 flex-1 overflow-auto">
                        {items.length === 0 ? (
                            <div
                                className="flex h-full min-h-48 flex-col items-center justify-center gap-2 p-8 text-center text-sm text-muted-foreground"
                                data-test="drive-empty"
                            >
                                {trash ? (
                                    <p>Trash is empty.</p>
                                ) : (
                                    <>
                                        <Upload className="size-6" />
                                        <p>
                                            Drop files or folders here, or use
                                            Upload.
                                        </p>
                                        <p className="text-xs">
                                            {space.kind === 'personal'
                                                ? 'Only you see My Drive. Your computer has it in its Drive folder.'
                                                : space.kind === 'organization'
                                                  ? 'Everyone in the organization sees this, and its projects can use it at /drive.'
                                                  : "This group's members see this, and their projects can use it at /drive."}
                                        </p>
                                    </>
                                )}
                            </div>
                        ) : (
                            <table className="w-full text-sm">
                                <thead className="sticky top-0 bg-background text-left text-xs text-muted-foreground">
                                    <tr>
                                        <th className="px-3 py-2 font-normal">
                                            Name
                                        </th>
                                        <th className="hidden px-3 py-2 font-normal sm:table-cell">
                                            Size
                                        </th>
                                        <th className="hidden px-3 py-2 font-normal md:table-cell">
                                            {trash ? 'Deleted' : 'Changed'}
                                        </th>
                                        <th className="w-10" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {items.map((item) => (
                                        <tr
                                            key={item.id}
                                            className="group border-t border-sidebar-border/50 hover:bg-muted/50"
                                            draggable={!trash}
                                            onDragStart={() => setDragged(item)}
                                            onDragEnd={() => setDragged(null)}
                                            onDragOver={(event) => {
                                                if (
                                                    dragged &&
                                                    item.folder &&
                                                    dragged.id !== item.id
                                                ) {
                                                    event.preventDefault();
                                                    event.stopPropagation();
                                                }
                                            }}
                                            onDrop={(event) => {
                                                if (dragged && item.folder) {
                                                    event.preventDefault();
                                                    event.stopPropagation();
                                                    moveInto(dragged, item);
                                                }
                                            }}
                                            data-test="drive-item"
                                        >
                                            <td className="max-w-0 px-3 py-1.5">
                                                <button
                                                    type="button"
                                                    className="flex w-full min-w-0 items-center gap-2 text-left"
                                                    onClick={() =>
                                                        !trash && open(item)
                                                    }
                                                    disabled={trash}
                                                >
                                                    <FileIcon
                                                        name={item.name}
                                                        isDir={item.folder}
                                                    />
                                                    <span
                                                        className="truncate"
                                                        data-test="drive-item-name"
                                                    >
                                                        {item.name}
                                                    </span>
                                                </button>
                                                {trash && item.location && (
                                                    <div className="truncate pl-6 text-xs text-muted-foreground">
                                                        in {item.location}
                                                    </div>
                                                )}
                                            </td>
                                            <td className="hidden px-3 py-1.5 whitespace-nowrap text-muted-foreground sm:table-cell">
                                                {item.folder
                                                    ? '—'
                                                    : formatBytes(item.size)}
                                            </td>
                                            <td className="hidden px-3 py-1.5 whitespace-nowrap text-muted-foreground md:table-cell">
                                                {trash
                                                    ? `${when(item.trashed_at)}${item.trashed_by ? ` by ${item.trashed_by}` : ''}`
                                                    : `${when(item.updated_at)}${item.updated_by ? ` by ${item.updated_by}` : ''}`}
                                            </td>
                                            <td className="px-1 py-1">
                                                <ItemMenu
                                                    trash={trash}
                                                    onOpen={() => open(item)}
                                                    download={downloadItem.url({
                                                        organization: slug,
                                                        item: item.id,
                                                    })}
                                                    onRename={() =>
                                                        setNaming({
                                                            mode: 'rename',
                                                            item,
                                                        })
                                                    }
                                                    onMove={() =>
                                                        setMoving(item)
                                                    }
                                                    onTrash={() =>
                                                        router.delete(
                                                            trashItem.url({
                                                                organization:
                                                                    slug,
                                                                item: item.id,
                                                            }),
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                    onRestore={() =>
                                                        router.post(
                                                            restoreItem.url({
                                                                organization:
                                                                    slug,
                                                                item: item.id,
                                                            }),
                                                            {},
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                    onPurge={() => {
                                                        if (
                                                            window.confirm(
                                                                `Delete “${item.name}” for good?`,
                                                            )
                                                        ) {
                                                            router.delete(
                                                                purgeItem.url({
                                                                    organization:
                                                                        slug,
                                                                    item: item.id,
                                                                }),
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            );
                                                        }
                                                    }}
                                                />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </div>

                    {uploads.length > 0 && (
                        <div
                            className="absolute right-3 bottom-3 w-72 space-y-2 rounded-lg border border-sidebar-border/70 bg-background p-3 text-xs shadow-lg dark:border-sidebar-border"
                            data-test="drive-uploads"
                        >
                            {uploads.map((entry) => (
                                <div key={entry.id}>
                                    <div className="flex items-center justify-between gap-2">
                                        <span className="truncate">
                                            {entry.name}
                                        </span>
                                        {entry.error ? (
                                            <button
                                                type="button"
                                                className="text-muted-foreground hover:text-foreground"
                                                onClick={() =>
                                                    setUploads((current) =>
                                                        current.filter(
                                                            (other) =>
                                                                other.id !==
                                                                entry.id,
                                                        ),
                                                    )
                                                }
                                            >
                                                Dismiss
                                            </button>
                                        ) : (
                                            <span className="text-muted-foreground">
                                                {entry.size
                                                    ? Math.round(
                                                          (entry.sent /
                                                              entry.size) *
                                                              100,
                                                      )
                                                    : 100}
                                                %
                                            </span>
                                        )}
                                    </div>
                                    {entry.error ? (
                                        <p className="text-red-600">
                                            {entry.error}
                                        </p>
                                    ) : (
                                        <div className="mt-1 h-1 overflow-hidden rounded-full bg-muted">
                                            <div
                                                className="h-full bg-primary transition-all"
                                                style={{
                                                    width: `${entry.size ? (entry.sent / entry.size) * 100 : 100}%`,
                                                }}
                                            />
                                        </div>
                                    )}
                                </div>
                            ))}
                        </div>
                    )}
                </section>
            </div>

            <NameDialog
                key={
                    naming
                        ? naming.mode === 'rename'
                            ? `rename-${naming.item.id}`
                            : 'folder'
                        : 'closed'
                }
                open={naming !== null}
                title={naming?.mode === 'rename' ? 'Rename' : 'New folder'}
                initial={naming?.mode === 'rename' ? naming.item.name : ''}
                onClose={() => setNaming(null)}
                onSubmit={async (name) => {
                    if (naming?.mode === 'rename') {
                        router.patch(
                            updateItem.url({
                                organization: slug,
                                item: naming.item.id,
                            }),
                            { name },
                            {
                                preserveScroll: true,
                                onError: (errors) =>
                                    setError(
                                        errors.drive ?? errors.name ?? null,
                                    ),
                            },
                        );
                    } else {
                        try {
                            await jsonRequest(
                                storeFolder.url({
                                    organization: slug,
                                    space: space.key,
                                }),
                                {
                                    name,
                                    parent_id: parentId,
                                },
                            );
                            reload();
                        } catch (failure) {
                            setError((failure as Error).message);
                        }
                    }

                    setNaming(null);
                }}
            />
            {moving && (
                <MoveDialog
                    item={moving}
                    spaces={spaces}
                    start={space}
                    organization={slug}
                    onClose={() => setMoving(null)}
                    onMove={(target, parent) => {
                        router.patch(
                            updateItem.url({
                                organization: slug,
                                item: moving.id,
                            }),
                            { space: target, parent_id: parent },
                            {
                                preserveScroll: true,
                                onError: (errors) =>
                                    setError(errors.drive ?? null),
                            },
                        );
                        setMoving(null);
                    }}
                />
            )}
            {viewing && (
                <ViewerDialog
                    item={viewing}
                    organization={slug}
                    onClose={() => setViewing(null)}
                    onSaved={reload}
                />
            )}
        </>
    );
}

function ItemMenu({
    trash,
    onOpen,
    download,
    onRename,
    onMove,
    onTrash,
    onRestore,
    onPurge,
}: {
    trash: boolean;
    onOpen: () => void;
    download: string;
    onRename: () => void;
    onMove: () => void;
    onTrash: () => void;
    onRestore: () => void;
    onPurge: () => void;
}) {
    return (
        <DropdownMenu modal={false}>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    aria-label="Actions"
                    className="rounded p-1 text-muted-foreground opacity-60 group-hover:opacity-100 hover:bg-muted"
                    data-test="drive-item-menu"
                >
                    <MoreHorizontal className="size-4" />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                {trash ? (
                    <>
                        <DropdownMenuItem
                            onSelect={onRestore}
                            data-test="drive-restore"
                        >
                            <RotateCcw />
                            Restore
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            variant="destructive"
                            onSelect={onPurge}
                            data-test="drive-purge"
                        >
                            <Trash2 />
                            Delete for good
                        </DropdownMenuItem>
                    </>
                ) : (
                    <>
                        <DropdownMenuItem onSelect={onOpen}>
                            <FolderInput />
                            Open
                        </DropdownMenuItem>
                        <DropdownMenuItem asChild>
                            <a href={download} data-test="drive-download">
                                <Download />
                                Download
                            </a>
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            onSelect={onRename}
                            data-test="drive-rename"
                        >
                            <Pencil />
                            Rename
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            onSelect={onMove}
                            data-test="drive-move"
                        >
                            <FolderInput />
                            Move to…
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            variant="destructive"
                            onSelect={onTrash}
                            data-test="drive-delete"
                        >
                            <Trash2 />
                            Delete
                        </DropdownMenuItem>
                    </>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function NameDialog({
    open,
    title,
    initial,
    onClose,
    onSubmit,
}: {
    open: boolean;
    title: string;
    initial: string;
    onClose: () => void;
    onSubmit: (name: string) => void;
}) {
    const [name, setName] = useState(initial);
    const input = useRef<HTMLInputElement>(null);

    // Opened on a name, the part before its extension is selected, ready to type over.
    useEffect(() => {
        if (!open) {
            return;
        }

        const frame = window.requestAnimationFrame(() => {
            const dot = initial.lastIndexOf('.');
            input.current?.focus();
            input.current?.setSelectionRange(0, dot > 0 ? dot : initial.length);
        });

        return () => window.cancelAnimationFrame(frame);
    }, [open, initial]);

    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
            <DialogContent>
                <DialogTitle>{title}</DialogTitle>
                <form
                    onSubmit={(event) => {
                        event.preventDefault();

                        if (name.trim()) {
                            onSubmit(name.trim());
                        }
                    }}
                    className="space-y-4"
                >
                    <Input
                        value={name}
                        onChange={(event) => setName(event.target.value)}
                        ref={input}
                        data-test="drive-name-input"
                    />
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" data-test="drive-name-save">
                            {title === 'Rename' ? 'Rename' : 'Create'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** Pick a folder in any of the user's places to move an item to. */
function MoveDialog({
    item,
    spaces,
    start,
    organization,
    onClose,
    onMove,
}: {
    item: DriveEntry;
    spaces: Space[];
    start: Space;
    organization: string;
    onClose: () => void;
    onMove: (space: string, parentId: number | null) => void;
}) {
    const [space, setSpace] = useState(start.key);
    const [path, setPath] = useState<Crumb[]>([]);
    const [folders, setFolders] = useState<Crumb[] | null>(null);
    const parent = path.at(-1)?.id ?? null;

    useEffect(() => {
        setFolders(null);
        void jsonRequest<{ folders: Crumb[] }>(
            driveFolders.url(
                { organization, space },
                { query: parent ? { parent } : {} },
            ),
        ).then((result) =>
            setFolders(
                result.folders.filter((folder) => folder.id !== item.id),
            ),
        );
    }, [organization, space, parent, item.id]);

    return (
        <Dialog open onOpenChange={(next) => !next && onClose()}>
            <DialogContent>
                <DialogTitle>Move “{item.name}”</DialogTitle>
                <DialogDescription>Pick where it goes.</DialogDescription>
                <div className="flex flex-wrap gap-1">
                    {spaces.map((candidate) => (
                        <Button
                            key={candidate.key}
                            size="sm"
                            variant={
                                candidate.key === space ? 'default' : 'outline'
                            }
                            onClick={() => {
                                setSpace(candidate.key);
                                setPath([]);
                            }}
                        >
                            {candidate.name}
                        </Button>
                    ))}
                </div>
                <div className="flex flex-wrap items-center gap-1 text-sm">
                    <button
                        type="button"
                        className="hover:underline"
                        onClick={() => setPath([])}
                    >
                        {
                            spaces.find((candidate) => candidate.key === space)
                                ?.name
                        }
                    </button>
                    {path.map((crumb, index) => (
                        <span
                            key={crumb.id}
                            className="flex items-center gap-1"
                        >
                            <ChevronRight className="size-4 text-muted-foreground" />
                            <button
                                type="button"
                                className="hover:underline"
                                onClick={() =>
                                    setPath(path.slice(0, index + 1))
                                }
                            >
                                {crumb.name}
                            </button>
                        </span>
                    ))}
                </div>
                <div
                    className="h-56 overflow-auto rounded-md border border-sidebar-border/70 dark:border-sidebar-border"
                    data-test="drive-move-folders"
                >
                    {folders === null ? (
                        <div className="flex h-full items-center justify-center">
                            <Spinner />
                        </div>
                    ) : folders.length === 0 ? (
                        <p className="p-3 text-sm text-muted-foreground">
                            No folders here.
                        </p>
                    ) : (
                        folders.map((folder) => (
                            <button
                                key={folder.id}
                                type="button"
                                className="flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm hover:bg-muted"
                                onClick={() => setPath([...path, folder])}
                            >
                                <FileIcon name={folder.name} isDir />
                                {folder.name}
                            </button>
                        ))
                    )}
                </div>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        onClick={() => onMove(space, parent)}
                        data-test="drive-move-here"
                    >
                        Move here
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/** A file shown in the page: images, video, audio and PDFs as they are, text in an editor. */
function ViewerDialog({
    item,
    organization,
    onClose,
    onSaved,
}: {
    item: DriveEntry;
    organization: string;
    onClose: () => void;
    onSaved: () => void;
}) {
    const kind = viewKind(item);
    const src = viewItem.url({ organization, item: item.id });
    const [text, setText] = useState<{ text: string; revision: number } | null>(
        null,
    );
    const [draft, setDraft] = useState('');
    const [problem, setProblem] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (kind !== 'text') {
            return;
        }

        jsonRequest<{ text: string; revision: number }>(
            itemText.url({ organization, item: item.id }),
        )
            .then((result) => {
                setText(result);
                setDraft(result.text);
            })
            .catch((failure: Error) => setProblem(failure.message));
    }, [kind, organization, item.id]);

    const save = async () => {
        if (!text) {
            return;
        }

        setSaving(true);

        try {
            const saved = await jsonRequest<DriveEntry>(
                saveItemText.url({ organization, item: item.id }),
                { text: draft, revision: text.revision },
                'PUT',
            );
            setText({ text: draft, revision: saved.revision });
            onSaved();
        } catch (failure) {
            setProblem((failure as Error).message);
        } finally {
            setSaving(false);
        }
    };

    let body: ReactNode;

    if (kind === 'image') {
        body = (
            <img
                src={src}
                alt={item.name}
                className="mx-auto max-h-[70vh] max-w-full object-contain"
            />
        );
    } else if (kind === 'video') {
        body = <video src={src} controls className="max-h-[70vh] w-full" />;
    } else if (kind === 'audio') {
        body = <audio src={src} controls className="w-full" />;
    } else if (kind === 'pdf') {
        body = (
            <iframe
                src={src}
                title={item.name}
                className="h-[70vh] w-full rounded border-0"
            />
        );
    } else if (problem && !text) {
        body = (
            <div className="space-y-3 text-sm">
                <p className="text-muted-foreground">{problem}</p>
            </div>
        );
    } else if (!text) {
        body = (
            <div className="flex h-40 items-center justify-center">
                <Spinner />
            </div>
        );
    } else {
        body = (
            <textarea
                value={draft}
                onChange={(event) => setDraft(event.target.value)}
                onKeyDown={(event) => {
                    if ((event.metaKey || event.ctrlKey) && event.key === 's') {
                        event.preventDefault();
                        void save();
                    }
                }}
                spellCheck={false}
                className="h-[60vh] w-full resize-none rounded-md border border-sidebar-border/70 bg-neutral-950 p-3 font-mono text-xs text-neutral-100 outline-none dark:border-sidebar-border"
                data-test="drive-editor"
            />
        );
    }

    return (
        <Dialog open onOpenChange={(next) => !next && onClose()}>
            <DialogContent className="sm:max-w-4xl">
                <DialogTitle className="truncate pr-6">{item.name}</DialogTitle>
                <DialogDescription>
                    {formatBytes(item.size)}
                    {item.updated_by
                        ? ` · changed ${when(item.updated_at)} by ${item.updated_by}`
                        : ''}
                </DialogDescription>
                {body}
                {problem && text && (
                    <p className="text-sm text-red-600">{problem}</p>
                )}
                <DialogFooter>
                    <Button variant="outline" asChild>
                        <a
                            href={downloadItem.url({
                                organization,
                                item: item.id,
                            })}
                        >
                            <Download className="size-4" />
                            Download
                        </a>
                    </Button>
                    {text && (
                        <Button
                            onClick={save}
                            disabled={saving || draft === text.text}
                            data-test="drive-save"
                        >
                            {saving ? 'Saving…' : 'Save'}
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
