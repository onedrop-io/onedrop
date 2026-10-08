import {
    Head,
    router,
    setLayoutProps,
    useForm,
    usePage,
} from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import OrganizationHostingController from '@/actions/App/Http/Controllers/OrganizationHostingController';
import Heading from '@/components/heading';
import HostingProviderLogo from '@/components/hosting-provider-logo';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useOrganization } from '@/hooks/use-organization';
import { index } from '@/routes/organizations/hosting';

type HostingField = {
    key: string;
    label: string;
    type: 'text' | 'number' | 'secret';
    help?: string;
    value: string | number | null;
    set: boolean;
};

type HostingAccount = {
    name: string;
    label: string;
    description: string;
    roles: string[];
    connected: boolean;
    missing: string[];
    platform: boolean;
    services: number;
    fields: HostingField[];
};

/** The organization's own hosting accounts (HOST-003): its apps are hosted there instead of the install's. */
export default function OrganizationHosting({
    hosting,
}: {
    hosting: HostingAccount[];
}) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const organization = useOrganization();

    setLayoutProps({
        breadcrumbs: [
            { title: 'Hosting accounts', href: index(organization.slug) },
        ],
    });

    return (
        <>
            <Head title="Hosting accounts" />

            <div className="flex max-w-3xl flex-1 flex-col gap-10">
                <section className="space-y-4">
                    <Heading
                        title="Hosting accounts"
                        description="Connect your own accounts and your apps are hosted there, billed to you by the provider. Without one, apps use this install's account when an admin set it up."
                    />

                    <InputError message={errors.hosting} />

                    <ul className="divide-y rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                        {hosting.map((account) => (
                            <HostingAccountRow
                                key={account.name}
                                account={account}
                                organization={organization.slug}
                            />
                        ))}
                    </ul>
                </section>
            </div>
        </>
    );
}

/** One provider: its status, and a form to connect or change the organization's own account (HOST-003). */
function HostingAccountRow({
    account,
    organization,
}: {
    account: HostingAccount;
    organization: string;
}) {
    const [open, setOpen] = useState(false);
    const route = { organization, provider: account.name };
    const form = useForm<Record<string, string | number>>(
        Object.fromEntries(
            account.fields.map((field) => [
                field.key,
                field.type === 'secret' ? '' : (field.value ?? ''),
            ]),
        ),
    );

    const save = (event: FormEvent) => {
        event.preventDefault();
        form.put(OrganizationHostingController.update.url(route), {
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);
                account.fields
                    .filter((field) => field.type === 'secret')
                    .forEach((field) => form.setData(field.key, ''));
            },
        });
    };

    const disconnect = () => {
        if (
            confirm(
                `Disconnect ${account.label}? New apps will use this install's account, if it has one.`,
            )
        ) {
            router.delete(OrganizationHostingController.destroy.url(route), {
                preserveScroll: true,
            });
        }
    };

    return (
        <li
            className="space-y-4 p-4"
            data-test={`organization-hosting-${account.name}`}
        >
            <div className="flex items-start justify-between gap-3">
                <div className="flex min-w-0 flex-1 items-start gap-3">
                    <HostingProviderLogo provider={account.name} />
                    <div className="min-w-0 space-y-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <p className="font-medium">{account.label}</p>
                            {account.connected ? (
                                <Badge>Connected</Badge>
                            ) : (
                                <span className="text-sm text-muted-foreground">
                                    {account.platform
                                        ? "Using OneDrop's account"
                                        : 'Not set up'}
                                </span>
                            )}
                        </div>
                        <p className="text-sm text-muted-foreground">
                            {account.description}
                            {account.services > 0 &&
                                ` · ${account.services} ${account.services === 1 ? 'thing' : 'things'} in this account`}
                        </p>
                        {account.connected && account.missing.length > 0 && (
                            <p className="text-sm text-amber-600 dark:text-amber-400">
                                Needs{' '}
                                {account.missing
                                    .map(
                                        (key) =>
                                            account.fields
                                                .find(
                                                    (field) =>
                                                        field.key === key,
                                                )
                                                ?.label.toLowerCase() ?? key,
                                    )
                                    .join(' and ')}
                                .
                            </p>
                        )}
                    </div>
                </div>
                <div className="flex shrink-0 items-center gap-2">
                    {!open && (
                        <Button
                            variant="outline"
                            onClick={() => setOpen(true)}
                            data-test={`organization-hosting-${account.name}-open`}
                        >
                            {account.connected ? 'Change' : 'Connect'}
                        </Button>
                    )}
                    {account.connected && (
                        <Button
                            variant="ghost"
                            onClick={disconnect}
                            data-test={`organization-hosting-${account.name}-disconnect`}
                        >
                            Disconnect
                        </Button>
                    )}
                </div>
            </div>

            {open && (
                <form onSubmit={save} className="grid max-w-xl gap-4">
                    {account.fields.map((field, index) => (
                        <div key={field.key} className="grid gap-2">
                            <Label
                                htmlFor={`hosting-${account.name}-${field.key}`}
                            >
                                {field.label}
                            </Label>
                            <Input
                                id={`hosting-${account.name}-${field.key}`}
                                type={
                                    field.type === 'secret'
                                        ? 'password'
                                        : field.type === 'number'
                                          ? 'number'
                                          : 'text'
                                }
                                autoComplete="off"
                                autoFocus={index === 0}
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
                                <p className="text-sm text-muted-foreground">
                                    {field.help}
                                </p>
                            )}
                            <InputError message={form.errors[field.key]} />
                        </div>
                    ))}
                    <div className="flex gap-2">
                        <Button
                            disabled={form.processing}
                            data-test={`organization-hosting-${account.name}-save`}
                        >
                            Save
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => {
                                form.reset();
                                form.clearErrors();
                                setOpen(false);
                            }}
                        >
                            Cancel
                        </Button>
                    </div>
                </form>
            )}
        </li>
    );
}
