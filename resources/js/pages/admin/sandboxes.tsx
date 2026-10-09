import {
    Form,
    Head,
    router,
    useForm,
    usePage,
    usePoll,
} from '@inertiajs/react';
import { GripVertical } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { FormEvent, KeyboardEvent } from 'react';
import SandboxMoveController from '@/actions/App/Http/Controllers/Admin/SandboxMoveController';
import SandboxProviderController from '@/actions/App/Http/Controllers/Admin/SandboxProviderController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { cn } from '@/lib/utils';

type Field = {
    key: string;
    label: string;
    type: 'text' | 'number' | 'secret' | 'select';
    options?: string[];
    help?: string;
    value: string | number | null;
    set: boolean;
};

type Provider = {
    name: string;
    label: string;
    description: string;
    enabled: boolean;
    active: boolean;
    missing: string[];
    sandboxes: number;
    fields: Field[];
};

type Move = {
    id: number;
    project: { id: number; name: string | null };
    task: string | null;
    reason: string;
    from: string | null;
    to: string | null;
    phase:
        | 'starting'
        | 'snapshotting'
        | 'creating'
        | 'restoring'
        | 'finishing'
        | 'done'
        | 'failed';
    source: 'fresh' | 'snapshot' | 'copy' | 'backup' | 'none' | null;
    snapshot_at: string | null;
    old_status: 'removed' | 'waiting' | 'recovered' | 'gone' | null;
    recovered_at: string | null;
    error: string | null;
    message: string | null;
    started_at: string | null;
    finished_at: string | null;
};

const DRAG_TYPE = 'application/x-onedrop-provider';

const PHASES: Record<Move['phase'], string> = {
    starting: 'Starting',
    snapshotting: 'Taking a snapshot',
    creating: 'Making the new sandbox',
    restoring: 'Restoring files',
    finishing: 'Finishing',
    done: 'Moved',
    failed: 'Failed',
};

const formatDate = (iso: string) => new Date(iso).toLocaleString();

/** Turn sandbox providers on and off, put them in order, and configure them (ADMIN-002); cap task copies (TASK-003). */
export default function Sandboxes({
    providers,
    maxTaskCopies,
    moves,
}: {
    providers: Provider[];
    maxTaskCopies: number | null;
    moves: Move[];
}) {
    const { errors } = usePage().props as { errors: Record<string, string> };
    // Reordering shows at once; the server's order replaces it when the page reloads.
    const [order, setOrder] = useState(() => providers.map((p) => p.name));
    const [selected, setSelected] = useState(providers[0]?.name);
    const [dragging, setDragging] = useState<string | null>(null);
    const saved = providers.map((p) => p.name).join(',');

    useEffect(() => setOrder(saved.split(',')), [saved]);

    const byName = Object.fromEntries(providers.map((p) => [p.name, p]));
    const provider = byName[selected ?? ''] ?? providers[0];

    const move = (name: string, to: number) => {
        const next = order.filter((other) => other !== name);
        next.splice(Math.max(0, Math.min(to, next.length)), 0, name);

        if (next.join(',') === order.join(',')) {
            return;
        }

        setOrder(next);
        router.put(
            SandboxProviderController.reorder.url(),
            { providers: next },
            { preserveScroll: true, preserveState: true },
        );
    };

    const moveWithKeys = (event: KeyboardEvent, name: string) => {
        const index = order.indexOf(name);

        if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
            event.preventDefault();
            move(name, index + (event.key === 'ArrowUp' ? -1 : 1));
        }
    };

    return (
        <>
            <Head title="Sandboxes" />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Sandbox providers"
                    description="Where each project's code runs. New projects run on the first provider that's on and set up; drag to change the order. Existing projects move to it right away, with their files; turn a provider off to move everything off it."
                />

                <InputError message={errors.enabled ?? errors.providers} />

                <div className="flex flex-col gap-6 sm:flex-row">
                    <ol
                        className="flex shrink-0 flex-col gap-1 sm:w-60"
                        aria-label="Providers, in order"
                    >
                        {order.map((name, index) => {
                            const item = byName[name];

                            if (!item) {
                                return null;
                            }

                            return (
                                <li
                                    key={name}
                                    onDragOver={(event) => {
                                        if (dragging && dragging !== name) {
                                            event.preventDefault();
                                            event.dataTransfer.dropEffect =
                                                'move';
                                        }
                                    }}
                                    onDrop={(event) => {
                                        event.preventDefault();
                                        const from =
                                            event.dataTransfer.getData(
                                                DRAG_TYPE,
                                            );
                                        setDragging(null);

                                        if (from && from !== name) {
                                            move(from, index);
                                        }
                                    }}
                                    className={cn(
                                        'flex items-center gap-1 rounded-lg border border-transparent pr-2 transition-colors',
                                        item.name === provider?.name
                                            ? 'border-border bg-muted'
                                            : 'hover:bg-muted/50',
                                        dragging === name && 'opacity-50',
                                    )}
                                    data-test={`provider-${name}-item`}
                                >
                                    <button
                                        type="button"
                                        draggable
                                        onDragStart={(event) => {
                                            event.dataTransfer.setData(
                                                DRAG_TYPE,
                                                name,
                                            );
                                            event.dataTransfer.effectAllowed =
                                                'move';
                                            setDragging(name);
                                        }}
                                        onDragEnd={() => setDragging(null)}
                                        onKeyDown={(event) =>
                                            moveWithKeys(event, name)
                                        }
                                        aria-label={`Move ${item.label} (${index + 1} of ${order.length}); use the arrow keys`}
                                        className="cursor-grab rounded p-1.5 text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none active:cursor-grabbing"
                                        data-test={`provider-${name}-handle`}
                                    >
                                        <GripVertical className="size-4" />
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setSelected(name)}
                                        aria-current={
                                            item.name === provider?.name
                                                ? 'true'
                                                : undefined
                                        }
                                        className="min-w-0 flex-1 py-2 text-left"
                                        data-test={`provider-${name}-select`}
                                    >
                                        <span className="flex items-center gap-2 text-sm font-medium">
                                            <span className="truncate">
                                                {item.label}
                                            </span>
                                            {item.active && (
                                                <Badge>Active</Badge>
                                            )}
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            {item.enabled &&
                                            item.missing.length > 0
                                                ? 'Needs settings'
                                                : `${item.sandboxes} ${item.sandboxes === 1 ? 'sandbox' : 'sandboxes'}`}
                                        </span>
                                    </button>
                                    <Switch
                                        checked={item.enabled}
                                        onChange={(checked) =>
                                            router.put(
                                                SandboxProviderController.update.url(
                                                    name,
                                                ),
                                                { enabled: checked },
                                                { preserveScroll: true },
                                            )
                                        }
                                        label={`Turn ${item.label} on`}
                                        testId={`provider-${name}-enabled`}
                                    />
                                </li>
                            );
                        })}
                    </ol>

                    {provider && (
                        <ProviderDetails
                            key={provider.name}
                            provider={provider}
                        />
                    )}
                </div>
            </div>

            {moves.length > 0 && <Moves moves={moves} />}

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Task copies"
                    description="Each task works in its own copy of its project's sandbox, and each copy is a sandbox you pay for. Organizations can set a lower limit for their own projects."
                />

                <Form
                    {...SandboxProviderController.taskCopies.form()}
                    options={{ preserveScroll: true }}
                    className="max-w-sm space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="max_task_copies">
                                    Most at once per project
                                </Label>
                                <Input
                                    id="max_task_copies"
                                    name="max_task_copies"
                                    type="number"
                                    min={1}
                                    max={1000}
                                    defaultValue={maxTaskCopies ?? ''}
                                    placeholder="No limit"
                                    data-test="max-task-copies-input"
                                />
                                <p className="text-xs text-muted-foreground">
                                    Leave empty for no limit.
                                </p>
                                <InputError message={errors.max_task_copies} />
                            </div>

                            <Button
                                disabled={processing}
                                data-test="save-task-copies-button"
                            >
                                Save
                            </Button>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

/** Sandboxes moving to new ones, failed moves, and files recovered from a provider that stopped answering (SBX-005, SBX-013). */
function Moves({ moves }: { moves: Move[] }) {
    const active = moves.some(
        (move) => move.phase !== 'done' && move.phase !== 'failed',
    );
    const { start, stop } = usePoll(
        3000,
        { only: ['moves', 'providers'] },
        { autoStart: false },
    );

    useEffect(() => {
        if (active) {
            start();
        } else {
            stop();
        }

        return stop;
    }, [active, start, stop]);

    const [restoring, setRestoring] = useState<Move | null>(null);

    return (
        <div className="space-y-4">
            <Dialog
                open={restoring !== null}
                onOpenChange={(open) => !open && setRestoring(null)}
            >
                <DialogContent data-test="restore-recovered-dialog">
                    <DialogHeader>
                        <DialogTitle>Restore the recovered files?</DialogTitle>
                        <DialogDescription>
                            {restoring?.project.name ?? 'The project'} gets a
                            new sandbox with the files saved from its old one on{' '}
                            {restoring?.from}. Anything changed in the project
                            since it moved will be replaced.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="ghost"
                            onClick={() => setRestoring(null)}
                            autoFocus
                        >
                            Cancel
                        </Button>
                        <Button
                            onClick={() => {
                                if (restoring) {
                                    router.post(
                                        SandboxMoveController.restore.url(
                                            restoring.id,
                                        ),
                                        {},
                                        { preserveScroll: true },
                                    );
                                }

                                setRestoring(null);
                            }}
                            data-test="restore-recovered-confirm"
                        >
                            Restore files
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Heading
                variant="small"
                title="Moves"
                description="Sandboxes moving to the provider their project should run on, with their files. A sandbox that doesn't answer is moved from its latest snapshot, and kept until it answers again."
            />

            <ul
                className="divide-y rounded-xl border"
                data-test="sandbox-moves"
            >
                {moves.map((move) => (
                    <li
                        key={move.id}
                        className="flex flex-col gap-2 p-4 sm:flex-row sm:items-start sm:justify-between"
                        data-test={`sandbox-move-${move.id}`}
                    >
                        <div className="min-w-0 space-y-1">
                            <p className="flex flex-wrap items-center gap-2 text-sm font-medium">
                                <span className="truncate">
                                    {move.project.name ??
                                        `Project ${move.project.id}`}
                                    {move.task && ` · ${move.task}`}
                                </span>
                                <Badge
                                    variant={
                                        move.phase === 'failed'
                                            ? 'destructive'
                                            : 'outline'
                                    }
                                >
                                    {PHASES[move.phase]}
                                </Badge>
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {move.from ?? 'No sandbox'} → {move.to}
                                {move.started_at &&
                                    ` · started ${formatDate(move.started_at)}`}
                            </p>
                            {move.phase === 'failed' && move.error && (
                                <p className="text-sm text-destructive">
                                    {move.error}
                                </p>
                            )}
                            {move.phase !== 'failed' && move.message && (
                                <p className="text-sm text-muted-foreground">
                                    {move.message}
                                </p>
                            )}
                            {move.old_status === 'waiting' && (
                                <p className="text-sm text-amber-600 dark:text-amber-400">
                                    {move.from} didn't answer
                                    {move.source === 'snapshot' &&
                                        move.snapshot_at &&
                                        `, so the project got its snapshot from ${formatDate(move.snapshot_at)}`}
                                    . The old sandbox is kept and checked on
                                    every 15 minutes; newer files in it are
                                    saved when it answers.
                                </p>
                            )}
                            {move.old_status === 'recovered' &&
                                move.recovered_at && (
                                    <p className="text-sm">
                                        {move.from} answered again: its newer
                                        files were saved on{' '}
                                        {formatDate(move.recovered_at)}.
                                    </p>
                                )}
                        </div>

                        <div className="flex shrink-0 gap-2">
                            {move.phase === 'failed' && (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        router.post(
                                            SandboxMoveController.retry.url(
                                                move.id,
                                            ),
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                    data-test={`sandbox-move-${move.id}-retry`}
                                >
                                    Try again
                                </Button>
                            )}
                            {move.old_status === 'recovered' && (
                                <>
                                    <Button
                                        size="sm"
                                        onClick={() => setRestoring(move)}
                                        data-test={`sandbox-move-${move.id}-restore`}
                                    >
                                        Restore
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() =>
                                            router.delete(
                                                SandboxMoveController.dismiss.url(
                                                    move.id,
                                                ),
                                                { preserveScroll: true },
                                            )
                                        }
                                        data-test={`sandbox-move-${move.id}-dismiss`}
                                    >
                                        Dismiss
                                    </Button>
                                </>
                            )}
                        </div>
                    </li>
                ))}
            </ul>
        </div>
    );
}

function ProviderDetails({ provider }: { provider: Provider }) {
    const form = useForm<Record<string, string | number | boolean>>(
        Object.fromEntries(
            provider.fields.map((field) => [
                field.key,
                field.type === 'secret' ? '' : (field.value ?? ''),
            ]),
        ),
    );

    const save = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, enabled: provider.enabled }));
        form.put(SandboxProviderController.update.url(provider.name), {
            preserveScroll: true,
            onSuccess: () => {
                provider.fields
                    .filter((field) => field.type === 'secret')
                    .forEach((field) => form.setData(field.key, ''));
            },
        });
    };

    return (
        <form
            onSubmit={save}
            className="min-w-0 flex-1 space-y-5 rounded-xl border p-5"
            data-test={`provider-${provider.name}`}
        >
            <div className="space-y-1">
                <div className="flex flex-wrap items-center gap-2">
                    <h3 className="font-medium">{provider.label}</h3>
                    {provider.active && <Badge>Active</Badge>}
                    {!provider.enabled && <Badge variant="outline">Off</Badge>}
                    <Badge variant="outline">
                        {provider.sandboxes}{' '}
                        {provider.sandboxes === 1 ? 'sandbox' : 'sandboxes'}
                    </Badge>
                </div>
                <p className="text-sm text-muted-foreground">
                    {provider.description}
                </p>
            </div>

            {provider.missing.length > 0 && (
                <p className="text-sm text-amber-600 dark:text-amber-400">
                    Needs{' '}
                    {provider.missing
                        .map(
                            (key) =>
                                provider.fields
                                    .find((field) => field.key === key)
                                    ?.label.toLowerCase() ?? key,
                        )
                        .join(' and ')}{' '}
                    before new projects can run on it.
                </p>
            )}

            <div className="grid gap-4 sm:grid-cols-2">
                {provider.fields.map((field) => (
                    <div key={field.key} className="grid content-start gap-1.5">
                        <Label htmlFor={`${provider.name}-${field.key}`}>
                            {field.label}
                        </Label>
                        {field.type === 'select' ? (
                            <select
                                id={`${provider.name}-${field.key}`}
                                value={String(form.data[field.key] ?? '')}
                                onChange={(event) =>
                                    form.setData(field.key, event.target.value)
                                }
                                className="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
                            >
                                {field.options?.map((option) => (
                                    <option key={option} value={option}>
                                        {option}
                                    </option>
                                ))}
                            </select>
                        ) : (
                            <Input
                                id={`${provider.name}-${field.key}`}
                                type={
                                    field.type === 'secret'
                                        ? 'password'
                                        : field.type === 'number'
                                          ? 'number'
                                          : 'text'
                                }
                                autoComplete="off"
                                value={String(form.data[field.key] ?? '')}
                                placeholder={
                                    field.type === 'secret' && field.set
                                        ? 'Saved (leave empty to keep)'
                                        : undefined
                                }
                                onChange={(event) =>
                                    form.setData(field.key, event.target.value)
                                }
                            />
                        )}
                        {field.help && (
                            <p className="text-xs text-muted-foreground">
                                {field.help}
                            </p>
                        )}
                        <InputError message={form.errors[field.key]} />
                    </div>
                ))}
            </div>

            <Button
                type="submit"
                disabled={form.processing}
                data-test={`provider-${provider.name}-save`}
            >
                Save
            </Button>
        </form>
    );
}
