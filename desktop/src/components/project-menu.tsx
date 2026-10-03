import {
    Archive,
    ArchiveRestore,
    EllipsisVertical,
    Globe,
    Pencil,
    Pin,
    PinOff,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
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
import type { SidebarProject } from '@/types';
import { api, ApiError, serverUrl } from '../lib/api';
import { openInBrowser } from '../lib/native';

/** A project's menu in the sidebar: rename, pin, archive, open on the web, delete (DESK-002). */
export default function ProjectMenu({
    project,
    onChanged,
    onDeleted,
}: {
    project: SidebarProject;
    onChanged: () => void;
    onDeleted: () => void;
}) {
    const [dialog, setDialog] = useState<'rename' | 'delete' | null>(null);
    const [name, setName] = useState(project.name);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const update = (changes: Record<string, unknown>) =>
        api(`projects/${project.id}`, changes, 'PATCH')
            .then(onChanged)
            .catch((failure: Error) => toast.error(failure.message));

    const rename = async (event: FormEvent) => {
        event.preventDefault();
        setBusy(true);

        try {
            await api(`projects/${project.id}`, { name }, 'PATCH');
            setDialog(null);
            onChanged();
        } catch (failure) {
            setError(
                failure instanceof ApiError
                    ? (failure.errors.name ?? failure.message)
                    : String(failure),
            );
        } finally {
            setBusy(false);
        }
    };

    const destroy = async () => {
        setBusy(true);

        try {
            await api(`projects/${project.id}`, undefined, 'DELETE');
            setDialog(null);
            toast.success(`Deleted “${project.name}”.`);
            onDeleted();
        } catch (failure) {
            toast.error((failure as Error).message);
        } finally {
            setBusy(false);
        }
    };

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <button
                        type="button"
                        aria-label={`${project.name} menu`}
                        className="flex size-6 shrink-0 items-center justify-center rounded-md text-muted-foreground opacity-0 group-hover:opacity-100 hover:bg-sidebar-accent hover:text-foreground focus-visible:opacity-100 data-[state=open]:opacity-100"
                        onClick={(event) => event.stopPropagation()}
                        data-test="project-menu"
                    >
                        <EllipsisVertical className="size-4" />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    align="start"
                    className="w-48"
                    onClick={(event) => event.stopPropagation()}
                >
                    <DropdownMenuItem
                        onSelect={() => {
                            setName(project.name);
                            setError(null);
                            setDialog('rename');
                        }}
                    >
                        <Pencil /> Rename
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        onSelect={() =>
                            void update({ pinned: !project.pinned })
                        }
                    >
                        {project.pinned ? <PinOff /> : <Pin />}{' '}
                        {project.pinned ? 'Unpin' : 'Pin'}
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        onSelect={() =>
                            void update({ archived: !project.archived })
                        }
                    >
                        {project.archived ? <ArchiveRestore /> : <Archive />}{' '}
                        {project.archived ? 'Restore' : 'Archive'}
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        onSelect={() =>
                            void openInBrowser(
                                serverUrl(`/projects/${project.id}`),
                            )
                        }
                    >
                        <Globe /> Open in browser
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        variant="destructive"
                        onSelect={() => setDialog('delete')}
                    >
                        <Trash2 /> Delete
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <Dialog
                open={dialog === 'rename'}
                onOpenChange={(open) => !open && setDialog(null)}
            >
                <DialogContent onClick={(event) => event.stopPropagation()}>
                    <form
                        onSubmit={(event) => void rename(event)}
                        className="space-y-4"
                    >
                        <DialogTitle>Rename project</DialogTitle>
                        <Input
                            value={name}
                            onChange={(event) => setName(event.target.value)}
                            maxLength={60}
                            autoFocus
                        />
                        <InputError message={error ?? undefined} />
                        <DialogFooter>
                            <DialogClose asChild>
                                <Button type="button" variant="ghost">
                                    Cancel
                                </Button>
                            </DialogClose>
                            <Button
                                type="submit"
                                disabled={busy || name.trim() === ''}
                            >
                                Rename
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog
                open={dialog === 'delete'}
                onOpenChange={(open) => !open && setDialog(null)}
            >
                <DialogContent onClick={(event) => event.stopPropagation()}>
                    <DialogTitle>Delete “{project.name}”?</DialogTitle>
                    <DialogDescription>
                        This takes its app offline and deletes its chat, files
                        and sandbox. It can't be undone.
                    </DialogDescription>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button variant="ghost">Cancel</Button>
                        </DialogClose>
                        <Button
                            variant="destructive"
                            disabled={busy}
                            onClick={() => void destroy()}
                        >
                            Delete project
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
