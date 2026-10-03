import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import HostingProviderController from '@/actions/App/Http/Controllers/Admin/HostingProviderController';
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
    type: 'text' | 'number' | 'secret';
    help?: string;
    value: string | number | null;
    set: boolean;
};

type Provider = {
    name: 'fly' | 'cloudflare' | 'neon' | 'upstash';
    label: string;
    description: string;
    roles: string[];
    enabled: boolean;
    missing: string[];
    services: number;
    fields: Field[];
};

const ROLES: Record<string, string> = {
    server: 'Apps with a server',
    volume: 'Data volumes',
    static: 'Front ends',
    bucket: 'S3 file storage',
    postgres: 'Postgres',
    redis: 'Redis',
};

const usedFor = (provider: Provider) =>
    provider.roles.map((role) => ROLES[role] ?? role).join(', ');

const missingLabels = (provider: Provider) =>
    provider.missing
        .map(
            (key) =>
                provider.fields
                    .find((field) => field.key === key)
                    ?.label.toLowerCase() ?? key,
        )
        .join(' and ');

const things = (count: number) =>
    `${count} ${count === 1 ? 'thing' : 'things'}`;

/** Turn the install's hosting providers on and off and set them up (ADMIN-007). */
export default function Hosting({ providers }: { providers: Provider[] }) {
    const { errors } = usePage().props as { errors: Record<string, string> };
    const [selected, setSelected] = useState(providers[0]?.name);
    const provider =
        providers.find((item) => item.name === selected) ?? providers[0];

    return (
        <>
            <Head title="Hosting" />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Hosting providers"
                    description="Where published apps run off their sandbox. Organizations can connect their own accounts instead in Settings → Organization."
                />

                <InputError message={errors.enabled} />

                <div className="flex flex-col gap-6 sm:flex-row">
                    <ul
                        className="flex shrink-0 flex-col gap-1 sm:w-60"
                        aria-label="Providers"
                    >
                        {providers.map((item) => (
                            <li
                                key={item.name}
                                className={cn(
                                    'flex items-center gap-1 rounded-lg border border-transparent pr-2 pl-3 transition-colors',
                                    item.name === provider?.name
                                        ? 'border-border bg-muted'
                                        : 'hover:bg-muted/50',
                                )}
                                data-test={`hosting-${item.name}-item`}
                            >
                                <button
                                    type="button"
                                    onClick={() => setSelected(item.name)}
                                    aria-current={
                                        item.name === provider?.name
                                            ? 'true'
                                            : undefined
                                    }
                                    className="min-w-0 flex-1 py-2 text-left"
                                    data-test={`hosting-${item.name}-select`}
                                >
                                    <span className="block truncate text-sm font-medium">
                                        {item.label}
                                    </span>
                                    <span className="block text-xs text-muted-foreground">
                                        {usedFor(item)}
                                    </span>
                                    <span className="block text-xs text-muted-foreground">
                                        {item.enabled && item.missing.length > 0
                                            ? `Needs ${missingLabels(item)}`
                                            : `${things(item.services)} in its account`}
                                    </span>
                                </button>
                                <Switch
                                    checked={item.enabled}
                                    onChange={(checked) =>
                                        router.put(
                                            HostingProviderController.update.url(
                                                item.name,
                                            ),
                                            { enabled: checked },
                                            { preserveScroll: true },
                                        )
                                    }
                                    label={`Turn ${item.label} on`}
                                    testId={`hosting-${item.name}-enabled`}
                                />
                            </li>
                        ))}
                    </ul>

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
        form.put(HostingProviderController.update.url(provider.name), {
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
            data-test={`hosting-${provider.name}`}
        >
            <div className="space-y-1">
                <div className="flex flex-wrap items-center gap-2">
                    <h3 className="font-medium">{provider.label}</h3>
                    {!provider.enabled && <Badge variant="outline">Off</Badge>}
                    <Badge variant="outline">
                        {things(provider.services)} in its account
                    </Badge>
                </div>
                <p className="text-sm text-muted-foreground">
                    {provider.description}
                </p>
                <p className="text-sm text-muted-foreground">
                    Used for: {usedFor(provider)}
                </p>
            </div>

            {provider.missing.length > 0 && (
                <p className="text-sm text-amber-600 dark:text-amber-400">
                    Needs {missingLabels(provider)} before apps can be hosted on
                    it.
                </p>
            )}

            <div className="grid gap-4 sm:grid-cols-2">
                {provider.fields.map((field) => (
                    <div key={field.key} className="grid content-start gap-1.5">
                        <Label htmlFor={`${provider.name}-${field.key}`}>
                            {field.label}
                        </Label>
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
                data-test={`hosting-${provider.name}-save`}
            >
                Save
            </Button>
        </form>
    );
}
