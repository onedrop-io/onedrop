import { Head, router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import SandboxProviderController from '@/actions/App/Http/Controllers/Admin/SandboxProviderController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';

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

/** Turn sandbox providers on and off, configure them, and choose the active one (ADMIN-002). */
export default function Sandboxes({ providers }: { providers: Provider[] }) {
    const { errors } = usePage().props as { errors: Record<string, string> };

    return (
        <>
            <Head title="Sandboxes" />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Sandbox providers"
                    description="Where each project's code runs. New projects run on the active provider; existing ones move to it (files kept) the next time they're opened."
                />

                <InputError message={errors.provider} />

                {providers.map((provider) => (
                    <ProviderCard key={provider.name} provider={provider} />
                ))}
            </div>
        </>
    );
}

function ProviderCard({ provider }: { provider: Provider }) {
    const form = useForm<Record<string, string | number | boolean>>({
        enabled: provider.enabled,
        ...Object.fromEntries(
            provider.fields.map((field) => [
                field.key,
                field.type === 'secret' ? '' : (field.value ?? ''),
            ]),
        ),
    });

    const save = (event: FormEvent) => {
        event.preventDefault();
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
            className="space-y-5 rounded-xl border p-5"
            data-test={`provider-${provider.name}`}
        >
            <div className="flex items-start justify-between gap-4">
                <div className="space-y-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <h3 className="font-medium">{provider.label}</h3>
                        {provider.active && <Badge>Active</Badge>}
                        <Badge variant="outline">
                            {provider.sandboxes}{' '}
                            {provider.sandboxes === 1 ? 'sandbox' : 'sandboxes'}
                        </Badge>
                    </div>
                    <p className="text-sm text-muted-foreground">
                        {provider.description}
                    </p>
                </div>
                <Switch
                    checked={Boolean(form.data.enabled)}
                    onChange={(checked) => form.setData('enabled', checked)}
                    label={`Turn ${provider.label} on`}
                    disabled={provider.active}
                    testId={`provider-${provider.name}-enabled`}
                />
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
                    before it can be active.
                </p>
            )}

            <InputError message={form.errors.enabled} />

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

            <div className="flex items-center gap-3">
                <Button
                    type="submit"
                    disabled={form.processing}
                    data-test={`provider-${provider.name}-save`}
                >
                    Save
                </Button>
                {!provider.active && (
                    <Button
                        type="button"
                        variant="outline"
                        disabled={
                            !provider.enabled || provider.missing.length > 0
                        }
                        data-test={`provider-${provider.name}-activate`}
                        onClick={() =>
                            router.post(
                                SandboxProviderController.activate.url(
                                    provider.name,
                                ),
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        Make active
                    </Button>
                )}
            </div>
        </form>
    );
}
