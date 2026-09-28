import {
    Binary,
    Check,
    ChevronDown,
    Code,
    HardDrive,
    Plus,
    RefreshCw,
    Trash2,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
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
import CommandsView from '@/components/workspace/storage/commands-view';
import ObjectsView from '@/components/workspace/storage/objects-view';
import { formatBytes, storageApi, suggestBucketName } from '@/lib/storage-api';
import { cn } from '@/lib/utils';
import type { StorageBucket } from '@/types';

type View = 'objects' | 'commands';

const BUCKET_PATTERN = /^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/;

/**
 * App Storage: the app's buckets (folders on the sandbox's disk, outside its code).
 * Browse and manage objects, or see how the app uses a bucket and ask the agent to wire it up.
 */
export default function StoragePanel({
    projectId,
    running,
    working,
}: {
    projectId: number;
    running: boolean;
    /** The agent is running a task. */
    working: boolean;
}) {
    const remembered = `zap.storage.bucket.${projectId}`;
    const [buckets, setBuckets] = useState<StorageBucket[] | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [selected, setSelected] = useState<string | null>(() =>
        localStorage.getItem(remembered),
    );
    const [view, setView] = useState<View>('objects');
    const [creating, setCreating] = useState(false);
    const [deleting, setDeleting] = useState(false);
    /** Chosen from the bucket menu; the dialog opens once the menu has fully closed. */
    const pending = useRef<'create' | 'delete' | null>(null);

    const load = useCallback(() => {
        storageApi
            .buckets(projectId)
            .then((list) => {
                setBuckets(list);
                setError(null);
            })
            .catch((e: Error) => setError(e.message));
    }, [projectId]);

    // Load when the sandbox is running, even mid-run, and again when a run starts or ends (the agent may make a bucket).
    useEffect(() => {
        if (running) {
            load();
        }
    }, [running, working, load]);

    const bucket =
        buckets?.find((b) => b.name === selected) ?? buckets?.[0] ?? null;

    const select = (name: string) => {
        setSelected(name);
        localStorage.setItem(remembered, name);
    };

    if (!running) {
        return <Empty>App Storage works when the sandbox is running.</Empty>;
    }

    if (error && !buckets) {
        return (
            <Empty tone="error">
                {error}
                <Button
                    size="sm"
                    variant="outline"
                    className="mx-auto mt-4 flex"
                    onClick={load}
                >
                    <RefreshCw /> Try again
                </Button>
            </Empty>
        );
    }

    if (!buckets) {
        return <Empty>Loading buckets…</Empty>;
    }

    const createDialog = (
        <CreateBucketDialog
            projectId={projectId}
            open={creating}
            taken={buckets.map((b) => b.name)}
            onClose={() => setCreating(false)}
            onCreated={(list, name) => {
                setCreating(false);
                setBuckets(list);
                select(name);
                setView('objects');
            }}
        />
    );

    if (!bucket) {
        return (
            <div
                className="flex flex-col items-center rounded-xl border border-dashed border-sidebar-border px-6 py-16 text-center"
                data-test="storage-empty"
            >
                <span className="flex size-12 items-center justify-center rounded-full bg-muted">
                    <HardDrive className="size-5 text-muted-foreground" />
                </span>
                <p className="mt-4 text-lg font-medium">
                    Store files for your app
                </p>
                <p className="mt-1 max-w-md text-sm text-muted-foreground">
                    Keep your app’s photos, videos, and documents safe and
                    organized in storage buckets, outside its code.
                </p>
                <Button
                    className="mt-6"
                    onClick={() => setCreating(true)}
                    data-test="storage-create-bucket"
                >
                    <Plus /> Create bucket
                </Button>
                {createDialog}
            </div>
        );
    }

    return (
        <div className="space-y-4" data-test="storage-panel">
            <div className="flex flex-wrap items-center gap-1 border-b border-sidebar-border/70 pb-3 dark:border-sidebar-border">
                <DropdownMenu modal={false}>
                    <DropdownMenuTrigger asChild>
                        <Button
                            variant="ghost"
                            className="gap-1.5 font-medium"
                            data-test="storage-bucket-menu"
                        >
                            {bucket.name}
                            <ChevronDown className="text-muted-foreground" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        align="start"
                        className="min-w-56"
                        // Open dialogs only after the closing menu lets go of focus.
                        onCloseAutoFocus={(event) => {
                            event.preventDefault();

                            if (pending.current === 'create') {
                                setCreating(true);
                            } else if (pending.current === 'delete') {
                                setDeleting(true);
                            }

                            pending.current = null;
                        }}
                    >
                        {buckets.map((b) => (
                            <DropdownMenuItem
                                key={b.name}
                                onSelect={() => select(b.name)}
                                data-test={`storage-bucket-${b.name}`}
                            >
                                <Check
                                    className={cn(
                                        b.name !== bucket.name && 'invisible',
                                    )}
                                />
                                {b.name}
                            </DropdownMenuItem>
                        ))}
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            onSelect={() => (pending.current = 'create')}
                            data-test="storage-new-bucket"
                        >
                            <Plus /> Create new bucket
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            variant="destructive"
                            onSelect={() => (pending.current = 'delete')}
                            data-test="storage-delete-bucket"
                        >
                            <Trash2 /> Delete {bucket.name}…
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>

                <span className="text-muted-foreground/50">/</span>

                <DropdownMenu modal={false}>
                    <DropdownMenuTrigger asChild>
                        <Button
                            variant="ghost"
                            className="gap-1.5"
                            data-test="storage-view-menu"
                        >
                            {view === 'objects' ? <Binary /> : <Code />}
                            {view === 'objects' ? 'Objects' : 'Commands'}
                            <ChevronDown className="text-muted-foreground" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="start">
                        <DropdownMenuItem
                            onSelect={() => setView('objects')}
                            data-test="storage-view-objects"
                        >
                            <Binary /> Objects
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            onSelect={() => setView('commands')}
                            data-test="storage-view-commands"
                        >
                            <Code /> Commands
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>

                <span
                    className="ml-auto text-xs text-muted-foreground"
                    data-test="storage-usage"
                >
                    {bucket.objects}{' '}
                    {bucket.objects === 1 ? 'object' : 'objects'} ·{' '}
                    {formatBytes(bucket.bytes)}
                </span>
            </div>

            {view === 'objects' ? (
                <ObjectsView
                    key={bucket.name}
                    projectId={projectId}
                    bucket={bucket.name}
                    working={working}
                    onChanged={load}
                />
            ) : (
                <CommandsView projectId={projectId} bucket={bucket.name} />
            )}

            {createDialog}
            <DeleteBucketDialog
                projectId={projectId}
                bucket={deleting ? bucket : null}
                onClose={() => setDeleting(false)}
                onDeleted={(list) => {
                    setDeleting(false);
                    setBuckets(list);
                }}
            />
        </div>
    );
}

function CreateBucketDialog({
    projectId,
    open,
    taken,
    onClose,
    onCreated,
}: {
    projectId: number;
    open: boolean;
    taken: string[];
    onClose: () => void;
    onCreated: (buckets: StorageBucket[], name: string) => void;
}) {
    const [name, setName] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    // A fresh suggestion each time it opens.
    useEffect(() => {
        if (open) {
            setName(suggestBucketName());
            setError(null);
        }
    }, [open]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const trimmed = name.trim();

        if (!BUCKET_PATTERN.test(trimmed)) {
            setError(
                'Use 3–63 lowercase letters, digits and dashes, starting and ending with a letter or digit.',
            );

            return;
        }

        if (taken.includes(trimmed)) {
            setError(`There's already a bucket called ${trimmed}.`);

            return;
        }

        setSaving(true);
        storageApi
            .createBucket(projectId, trimmed)
            .then((buckets) => onCreated(buckets, trimmed))
            .catch((e: Error) => setError(e.message))
            .finally(() => setSaving(false));
    };

    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
            <DialogContent data-test="storage-create-dialog">
                <form onSubmit={submit} className="space-y-4">
                    <DialogTitle>Create a bucket</DialogTitle>
                    <DialogDescription>
                        A bucket holds a group of files, like{' '}
                        <code>photos</code> or <code>invoices</code>. Your app
                        refers to it by this name.
                    </DialogDescription>
                    <div className="space-y-1">
                        <Input
                            autoFocus
                            aria-label="Bucket name"
                            value={name}
                            onChange={(event) => {
                                setName(event.target.value.toLowerCase());
                                setError(null);
                            }}
                            onFocus={(event) => event.target.select()}
                            maxLength={63}
                            data-test="storage-bucket-name"
                        />
                        <InputError message={error ?? undefined} />
                    </div>
                    <Button
                        type="submit"
                        className="w-full"
                        disabled={saving || name.trim() === ''}
                        data-test="storage-bucket-submit"
                    >
                        {saving ? (
                            <>
                                <RefreshCw className="animate-spin" /> Creating
                                bucket…
                            </>
                        ) : (
                            'Create bucket'
                        )}
                    </Button>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function DeleteBucketDialog({
    projectId,
    bucket,
    onClose,
    onDeleted,
}: {
    projectId: number;
    bucket: StorageBucket | null;
    onClose: () => void;
    onDeleted: (buckets: StorageBucket[]) => void;
}) {
    const [confirm, setConfirm] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [deleting, setDeleting] = useState(false);

    useEffect(() => {
        setConfirm('');
        setError(null);
    }, [bucket]);

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (!bucket) {
            return;
        }

        setDeleting(true);
        storageApi
            .deleteBucket(projectId, bucket.name)
            .then(onDeleted)
            .catch((e: Error) => setError(e.message))
            .finally(() => setDeleting(false));
    };

    return (
        <Dialog
            open={bucket !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent data-test="storage-delete-bucket-dialog">
                <form onSubmit={submit} className="space-y-4">
                    <DialogTitle>Delete {bucket?.name}?</DialogTitle>
                    <DialogDescription>
                        This permanently deletes the bucket and its{' '}
                        {bucket?.objects ?? 0}{' '}
                        {bucket?.objects === 1 ? 'object' : 'objects'} (
                        {formatBytes(bucket?.bytes ?? 0)}). Parts of your app
                        that use it will stop working. Type the bucket’s name to
                        confirm.
                    </DialogDescription>
                    <div className="space-y-1">
                        <Input
                            autoFocus
                            aria-label="Bucket name to confirm"
                            value={confirm}
                            onChange={(event) => setConfirm(event.target.value)}
                            placeholder={bucket?.name}
                            data-test="storage-delete-bucket-confirm"
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
                            variant="destructive"
                            disabled={deleting || confirm !== bucket?.name}
                            data-test="storage-delete-bucket-submit"
                        >
                            Delete bucket
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function Empty({ children, tone }: { children: ReactNode; tone?: 'error' }) {
    return (
        <div
            className={cn(
                'rounded-xl border border-dashed border-sidebar-border p-6 text-sm',
                tone === 'error' ? 'text-red-600' : 'text-muted-foreground',
            )}
            data-test="storage-message"
        >
            {children}
        </div>
    );
}
