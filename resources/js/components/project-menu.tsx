import { Link, router, useForm, usePage } from '@inertiajs/react';
import {
    Archive,
    ArchiveRestore,
    Circle,
    CircleCheck,
    EllipsisVertical,
    FolderOpen,
    Monitor,
    Kanban,
    Link2,
    Pencil,
    Pin,
    PinOff,
    Plus,
    Share,
    Sparkles,
    Trash2,
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
    DropdownMenuShortcut,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { SidebarMenuAction } from '@/components/ui/sidebar';
import { useClipboard } from '@/hooks/use-clipboard';
import { useIsMobile } from '@/hooks/use-mobile';
import { openProject } from '@/lib/open-project';
import { board, destroy, show, update } from '@/routes/projects';
import { create as createTask } from '@/routes/projects/tasks';
import { regenerate } from '@/routes/projects/name';
import type { SidebarProject } from '@/types';

type Action =
    | 'open'
    | 'board'
    | 'task'
    | 'pin'
    | 'unread'
    | 'rename'
    | 'regenerate'
    | 'share'
    | 'copy'
    | 'archive'
    | 'delete';

type DialogAction = Extract<Action, 'rename' | 'share' | 'delete'>;

/** Pressing these while the menu is open runs the action. */
const SHORTCUTS: Record<string, Action> = {
    o: 'open',
    b: 'board',
    n: 'task',
    p: 'pin',
    u: 'unread',
    r: 'rename',
    c: 'copy',
    a: 'archive',
    d: 'delete',
};

/**
 * The "⋮" menu on a project in the sidebar: open it (TASK-001), its board, a new task, pin, mark unread, rename, regenerate the title, share, copy link, open in the desktop app, archive, delete.
 */
export function ProjectMenu({ project }: { project: SidebarProject }) {
    const { desktopAppSignedIn } = usePage().props;
    const isMobile = useIsMobile();
    const [, copy] = useClipboard();
    const [open, setOpen] = useState(false);
    const [dialog, setDialog] = useState<DialogAction | null>(null);
    /** Chosen from the menu; the dialog opens once the menu has fully closed. */
    const pendingDialog = useRef<DialogAction | null>(null);
    const trigger = useRef<HTMLButtonElement>(null);

    const change = (data: Record<string, boolean>) =>
        router.patch(update(project.id).url, data, {
            preserveScroll: true,
            preserveState: true,
        });

    const run = (action: Action) => {
        switch (action) {
            case 'open':
                openProject(project.id);
                break;
            case 'board':
                router.visit(board(project.id));
                break;
            case 'task':
                router.visit(createTask(project.id));
                break;
            case 'pin':
                change({ pinned: !project.pinned });
                break;
            case 'unread':
                change({ unread: !project.unread });
                break;
            case 'archive':
                change({ archived: !project.archived });
                break;
            case 'regenerate':
                router.post(
                    regenerate(project.id).url,
                    {},
                    { preserveScroll: true, preserveState: true },
                );
                break;
            case 'copy':
                void copy(
                    new URL(show(project.id).url, window.location.origin).href,
                ).then((copied) =>
                    copied
                        ? toast.success('Link copied.')
                        : toast.error("Couldn't copy the link."),
                );
                break;
            default:
                pendingDialog.current = action;
        }
    };

    return (
        <>
            <DropdownMenu open={open} onOpenChange={setOpen} modal={false}>
                <DropdownMenuTrigger asChild>
                    <SidebarMenuAction
                        ref={trigger}
                        showOnHover
                        aria-label={`Actions for ${project.name}`}
                        title="Project actions"
                        data-test="project-menu"
                    >
                        <EllipsisVertical />
                    </SidebarMenuAction>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    className="w-52"
                    side={isMobile ? 'bottom' : 'right'}
                    align={isMobile ? 'end' : 'start'}
                    onKeyDown={(event) => {
                        const action =
                            !event.metaKey && !event.ctrlKey && !event.altKey
                                ? SHORTCUTS[event.key.toLowerCase()]
                                : undefined;

                        if (action) {
                            event.preventDefault();
                            run(action);
                            setOpen(false);
                        }
                    }}
                    // Reopened while the last one animates out, the old menu
                    // sees the click on "⋮" as outside and would close the
                    // new one.
                    onPointerDownOutside={(event) => {
                        if (
                            trigger.current?.contains(
                                event.target as Node | null,
                            )
                        ) {
                            event.preventDefault();
                        }
                    }}
                    // Open the dialog only after the closing menu lets go of
                    // focus, so the dialog's input keeps it.
                    onCloseAutoFocus={(event) => {
                        event.preventDefault();

                        if (pendingDialog.current) {
                            setDialog(pendingDialog.current);
                            pendingDialog.current = null;
                        }
                    }}
                >
                    <DropdownMenuItem
                        onSelect={() => run('open')}
                        data-test="project-menu-open"
                    >
                        <FolderOpen />
                        Open
                        <DropdownMenuShortcut>O</DropdownMenuShortcut>
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        onSelect={() => run('board')}
                        data-test="project-menu-board"
                    >
                        <Kanban />
                        Board
                        <DropdownMenuShortcut>B</DropdownMenuShortcut>
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        onSelect={() => run('task')}
                        data-test="project-menu-new-task"
                    >
                        <Plus />
                        New task
                        <DropdownMenuShortcut>N</DropdownMenuShortcut>
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        onSelect={() => run('pin')}
                        disabled={project.archived}
                        data-test="project-menu-pin"
                    >
                        {project.pinned ? <PinOff /> : <Pin />}
                        {project.pinned ? 'Unpin' : 'Pin'}
                        <DropdownMenuShortcut>P</DropdownMenuShortcut>
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        onSelect={() => run('unread')}
                        data-test="project-menu-unread"
                    >
                        {project.unread ? <CircleCheck /> : <Circle />}
                        {project.unread ? 'Mark as read' : 'Mark as unread'}
                        <DropdownMenuShortcut>U</DropdownMenuShortcut>
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        onSelect={() => run('rename')}
                        data-test="project-menu-rename"
                    >
                        <Pencil />
                        Rename
                        <DropdownMenuShortcut>R</DropdownMenuShortcut>
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        onSelect={() => run('regenerate')}
                        disabled={project.naming}
                        data-test="project-menu-regenerate"
                    >
                        <Sparkles />
                        Regenerate title
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        onSelect={() => run('share')}
                        data-test="project-menu-share"
                    >
                        <Share />
                        Share
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        onSelect={() => run('copy')}
                        data-test="project-menu-copy"
                    >
                        <Link2 />
                        Copy link
                        <DropdownMenuShortcut>C</DropdownMenuShortcut>
                    </DropdownMenuItem>
                    {/* In a browser, once the desktop app is signed in somewhere (DESK-011). */}
                    {desktopAppSignedIn && !window.onedropDesktop && (
                        <DropdownMenuItem asChild>
                            <a
                                href={`onedrop://projects/${project.id}`}
                                data-test="project-menu-desktop"
                            >
                                <Monitor />
                                Open in the desktop app
                            </a>
                        </DropdownMenuItem>
                    )}
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        onSelect={() => run('archive')}
                        data-test="project-menu-archive"
                    >
                        {project.archived ? <ArchiveRestore /> : <Archive />}
                        {project.archived ? 'Unarchive' : 'Archive'}
                        <DropdownMenuShortcut>A</DropdownMenuShortcut>
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        variant="destructive"
                        onSelect={() => run('delete')}
                        data-test="project-menu-delete"
                    >
                        <Trash2 />
                        Delete
                        <DropdownMenuShortcut>D</DropdownMenuShortcut>
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <RenameDialog
                project={project}
                open={dialog === 'rename'}
                onClose={() => setDialog(null)}
            />
            <ShareDialog
                project={project}
                open={dialog === 'share'}
                onClose={() => setDialog(null)}
            />
            <DeleteDialog
                project={project}
                open={dialog === 'delete'}
                onClose={() => setDialog(null)}
            />
        </>
    );
}

type DialogProps = {
    project: SidebarProject;
    open: boolean;
    onClose: () => void;
};

function RenameDialog({ project, open, onClose }: DialogProps) {
    const form = useForm({ name: project.name });

    const close = () => {
        form.reset();
        form.clearErrors();
        onClose();
    };

    return (
        <Dialog open={open} onOpenChange={(isOpen) => !isOpen && close()}>
            <DialogContent>
                <form
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.patch(update(project.id).url, {
                            preserveScroll: true,
                            preserveState: true,
                            onSuccess: () => {
                                form.setDefaults();
                                onClose();
                            },
                        });
                    }}
                >
                    <DialogTitle>Rename project</DialogTitle>
                    <div>
                        <Input
                            value={form.data.name}
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                            onFocus={(event) => event.target.select()}
                            maxLength={60}
                            aria-label="Project name"
                            autoFocus
                            data-test="project-rename-input"
                        />
                        <InputError message={form.errors.name} />
                    </div>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Cancel
                            </Button>
                        </DialogClose>
                        <Button
                            type="submit"
                            disabled={form.processing}
                            data-test="project-rename-save"
                        >
                            Save
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function ShareDialog({ project, open, onClose }: DialogProps) {
    const [copied, copy] = useClipboard();
    const url = project.published_url;

    return (
        <Dialog open={open} onOpenChange={(isOpen) => !isOpen && onClose()}>
            <DialogContent>
                <DialogTitle>Share {project.name}</DialogTitle>
                {url ? (
                    <>
                        <DialogDescription>
                            Send this link to the people you want using your
                            app.
                        </DialogDescription>
                        <div className="flex gap-2">
                            <Input
                                value={url}
                                readOnly
                                onFocus={(event) => event.target.select()}
                                aria-label="App link"
                                autoFocus
                                data-test="project-share-url"
                            />
                            <Button
                                type="button"
                                onClick={() => void copy(url)}
                                data-test="project-share-copy"
                            >
                                {copied === url ? 'Copied' : 'Copy'}
                            </Button>
                        </div>
                    </>
                ) : (
                    <>
                        <DialogDescription>
                            Publish the app to get a link you can share. Open
                            the project and choose Publish.
                        </DialogDescription>
                        <DialogFooter>
                            <Button asChild>
                                <Link href={show(project.id)} onClick={onClose}>
                                    Open project
                                </Link>
                            </Button>
                        </DialogFooter>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}

function DeleteDialog({ project, open, onClose }: DialogProps) {
    const [deleting, setDeleting] = useState(false);

    return (
        <Dialog open={open} onOpenChange={(isOpen) => !isOpen && onClose()}>
            <DialogContent>
                <DialogTitle>Delete {project.name}?</DialogTitle>
                <DialogDescription>
                    This deletes the chat, the sandbox and all its files, and
                    takes the app offline. It can't be undone.
                </DialogDescription>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button type="button" variant="secondary">
                            Cancel
                        </Button>
                    </DialogClose>
                    <Button
                        type="button"
                        variant="destructive"
                        disabled={deleting}
                        onClick={() =>
                            router.delete(destroy(project.id).url, {
                                preserveScroll: true,
                                onStart: () => setDeleting(true),
                                onFinish: () => setDeleting(false),
                                onSuccess: onClose,
                            })
                        }
                        data-test="project-delete-confirm"
                    >
                        Delete
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
