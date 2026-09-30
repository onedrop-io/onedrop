import { Head, router, useForm, usePage } from '@inertiajs/react';
import { GripVertical } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { FormEvent, KeyboardEvent } from 'react';
import SandboxProviderController from '@/actions/App/Http/Controllers/Admin/SandboxProviderController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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

const DRAG_TYPE = 'application/x-onedrop-provider';

/** Turn sandbox providers on and off, put them in order, and configure them (ADMIN-002). */
export default function Sandboxes({ providers }: { providers: Provider[] }) {
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
                    description="Where each project's code runs. New projects run on the first provider that's on and set up; drag to change the order. Existing projects move to it (files kept) the next time they're opened."
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
        </>
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
