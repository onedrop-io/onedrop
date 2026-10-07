import {
    Form,
    Head,
    Link,
    router,
    setLayoutProps,
    useForm,
    usePage,
} from '@inertiajs/react';
import { Check, Copy } from 'lucide-react';
import { useRef, useState } from 'react';
import type { FormEvent } from 'react';
import OrganizationController from '@/actions/App/Http/Controllers/OrganizationController';
import OrganizationDomainController from '@/actions/App/Http/Controllers/OrganizationDomainController';
import OrganizationHostingController from '@/actions/App/Http/Controllers/OrganizationHostingController';
import OrganizationMemberController from '@/actions/App/Http/Controllers/OrganizationMemberController';
import Heading from '@/components/heading';
import HostingProviderLogo from '@/components/hosting-provider-logo';
import InputError from '@/components/input-error';
import { OrganizationMark } from '@/components/organization-mark';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { useClipboard } from '@/hooks/use-clipboard';
import { useOrganization } from '@/hooks/use-organization';
import { edit } from '@/routes/organizations';
import type { OrganizationRole } from '@/types';

type Member = {
    id: number;
    name: string;
    email: string;
    role: OrganizationRole;
    joined_at: string | null;
};

type EmailDomain = {
    id: number;
    domain: string;
    verified: boolean;
    txt: string;
};

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

const ROLES: { value: OrganizationRole; label: string }[] = [
    { value: 'member', label: 'Member' },
    { value: 'admin', label: 'Admin' },
    { value: 'owner', label: 'Owner' },
];

/** An organization's name, address, computers, members, email domains, own hosting accounts and task copy limit (ORG-004, ORG-005, ORG-008, CMP-003, HOST-003, TASK-003). */
export default function OrganizationEdit({
    details,
    computers,
    members,
    domains,
    hosting,
    taskCopies,
    can,
}: {
    details: { name: string; slug: string; logo_url: string | null };
    computers: { enabled: boolean } | null;
    members: Member[];
    domains: EmailDomain[] | null;
    hosting: HostingAccount[] | null;
    taskCopies: { limit: number | null; install_limit: number | null };
    can: { update: boolean; manage_owners: boolean; remove: boolean };
}) {
    const { auth, errors } = usePage<{ errors: Record<string, string> }>()
        .props;
    const organization = useOrganization();
    const fileInput = useRef<HTMLInputElement>(null);

    const uploadLogo = (file: File | undefined) => {
        if (!file) {
            return;
        }

        router.post(
            OrganizationController.storeLogo.url(organization.slug),
            { logo: file },
            {
                forceFormData: true,
                preserveScroll: true,
                onFinish: () => {
                    if (fileInput.current) {
                        fileInput.current.value = '';
                    }
                },
            },
        );
    };

    setLayoutProps({
        breadcrumbs: [{ title: 'Organization', href: edit(organization.slug) }],
    });

    /** Owners' rows, and making someone an owner, are for owners only. */
    const canChange = (member: Member) =>
        can.update && (can.manage_owners || member.role !== 'owner');

    const changeRole = (member: Member, role: OrganizationRole) =>
        router.patch(
            OrganizationMemberController.update.url({
                organization: organization.slug,
                user: member.id,
            }),
            { role },
            { preserveScroll: true },
        );

    return (
        <>
            <Head title="Organization" />

            <div className="flex max-w-3xl flex-1 flex-col gap-10">
                <Heading
                    title={details.name}
                    description="Its projects, groups, invites and shared skills are only seen by the people in it"
                />

                {can.update && (
                    <section className="space-y-4">
                        <Heading variant="small" title="Details" />
                        <Form
                            {...OrganizationController.update.form(
                                organization.slug,
                            )}
                            options={{ preserveScroll: true }}
                            className="grid max-w-xl gap-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="organization-name">
                                            Name
                                        </Label>
                                        <Input
                                            id="organization-name"
                                            name="name"
                                            defaultValue={details.name}
                                            required
                                        />
                                        <InputError message={errors.name} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="organization-slug">
                                            Address
                                        </Label>
                                        <div className="flex items-center gap-1 text-sm text-muted-foreground">
                                            <span>/o/</span>
                                            <Input
                                                id="organization-slug"
                                                name="slug"
                                                defaultValue={details.slug}
                                                required
                                            />
                                        </div>
                                        <p className="text-sm text-muted-foreground">
                                            Links to the old address stop
                                            working.
                                        </p>
                                        <InputError message={errors.slug} />
                                    </div>
                                    <div>
                                        <Button
                                            disabled={processing}
                                            data-test="save-organization"
                                        >
                                            Save
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    </section>
                )}

                {can.update && (
                    <section className="space-y-4">
                        <Heading
                            variant="small"
                            title="Logo"
                            description="Shown in the organization menu at the top of the sidebar. PNG, JPEG, WebP or SVG, up to 1 MB; square works best."
                        />
                        <div className="flex items-center gap-4">
                            <OrganizationMark
                                organization={{
                                    name: details.name,
                                    logo_url: details.logo_url,
                                }}
                                className="size-16 text-2xl"
                            />
                            <input
                                ref={fileInput}
                                type="file"
                                accept="image/png,image/jpeg,image/webp,image/svg+xml"
                                className="hidden"
                                data-test="organization-logo-input"
                                onChange={(event) =>
                                    uploadLogo(event.target.files?.[0])
                                }
                            />
                            <Button
                                variant="outline"
                                onClick={() => fileInput.current?.click()}
                            >
                                {details.logo_url
                                    ? 'Replace logo'
                                    : 'Upload logo'}
                            </Button>
                            {details.logo_url && (
                                <Button
                                    variant="ghost"
                                    data-test="remove-organization-logo"
                                    onClick={() =>
                                        router.delete(
                                            OrganizationController.destroyLogo.url(
                                                organization.slug,
                                            ),
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Remove
                                </Button>
                            )}
                        </div>
                        <InputError message={errors.logo} />
                    </section>
                )}

                {can.update && computers && (
                    <section className="space-y-4">
                        <Heading
                            variant="small"
                            title="Computers"
                            description="Each person gets their own cloud computer: a desktop with a browser, which they and the AI use. Turning computers off puts running ones to sleep; turning them on again gives everyone theirs back."
                        />
                        <div className="flex items-center gap-3 text-sm">
                            <Switch
                                checked={computers.enabled}
                                label={`Give everyone in ${details.name} a computer`}
                                testId="organization-computers"
                                onChange={(enabled) =>
                                    router.put(
                                        OrganizationController.updateComputers.url(
                                            organization.slug,
                                        ),
                                        { enabled },
                                        { preserveScroll: true },
                                    )
                                }
                            />
                            <span>
                                Give everyone in {details.name} a computer
                            </span>
                        </div>
                    </section>
                )}

                <section className="space-y-4">
                    <Heading
                        variant="small"
                        title="Members"
                        description={`${members.length} ${members.length === 1 ? 'person' : 'people'}`}
                    />

                    <InputError message={errors.member} />

                    <ul className="divide-y rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                        {members.map((member) => (
                            <li
                                key={member.id}
                                className="flex flex-wrap items-center justify-between gap-3 p-4"
                                data-test={`organization-member-${member.id}`}
                            >
                                <div className="min-w-0">
                                    <p className="font-medium">{member.name}</p>
                                    <p className="truncate text-sm text-muted-foreground">
                                        {member.email}
                                        {member.joined_at &&
                                            ` · joined ${new Date(member.joined_at).toLocaleDateString()}`}
                                    </p>
                                </div>
                                <div className="flex items-center gap-3">
                                    {canChange(member) ? (
                                        <select
                                            aria-label={`Role for ${member.name}`}
                                            value={member.role}
                                            onChange={(event) =>
                                                changeRole(
                                                    member,
                                                    event.target
                                                        .value as OrganizationRole,
                                                )
                                            }
                                            className="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                                            data-test={`role-${member.id}`}
                                        >
                                            {ROLES.filter(
                                                (role) =>
                                                    can.manage_owners ||
                                                    role.value !== 'owner',
                                            ).map((role) => (
                                                <option
                                                    key={role.value}
                                                    value={role.value}
                                                >
                                                    {role.label}
                                                </option>
                                            ))}
                                        </select>
                                    ) : (
                                        <span className="text-sm text-muted-foreground capitalize">
                                            {member.role}
                                        </span>
                                    )}
                                    {can.remove &&
                                        (member.id === auth.user.id ||
                                            canChange(member)) && (
                                            <Link
                                                href={OrganizationMemberController.destroy(
                                                    {
                                                        organization:
                                                            organization.slug,
                                                        user: member.id,
                                                    },
                                                )}
                                                as="button"
                                                preserveScroll
                                                className="text-sm text-red-600 underline-offset-4 hover:underline"
                                            >
                                                {member.id === auth.user.id
                                                    ? 'Leave'
                                                    : 'Remove'}
                                            </Link>
                                        )}
                                </div>
                            </li>
                        ))}
                    </ul>
                </section>

                {domains && <EmailDomains domains={domains} />}

                {can.update && (
                    <section className="space-y-4">
                        <Heading
                            variant="small"
                            title="Task copies"
                            description="Each task works in its own copy of its project's sandbox, and each copy is a sandbox that costs money to run. Limit how many one project runs at once."
                        />
                        <Form
                            {...OrganizationController.updateTaskCopies.form(
                                organization.slug,
                            )}
                            options={{ preserveScroll: true }}
                            className="grid max-w-sm gap-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="organization-max-task-copies">
                                            Most at once per project
                                        </Label>
                                        <Input
                                            id="organization-max-task-copies"
                                            name="max_task_copies"
                                            type="number"
                                            min={1}
                                            max={1000}
                                            defaultValue={
                                                taskCopies.limit ?? ''
                                            }
                                            placeholder="No limit"
                                            data-test="organization-max-task-copies"
                                        />
                                        <p className="text-sm text-muted-foreground">
                                            {taskCopies.install_limit === null
                                                ? 'Leave empty for no limit.'
                                                : `Leave empty for this install's limit of ${taskCopies.install_limit}, which also caps a higher one.`}
                                        </p>
                                        <InputError
                                            message={errors.max_task_copies}
                                        />
                                    </div>
                                    <div>
                                        <Button
                                            disabled={processing}
                                            data-test="save-organization-task-copies"
                                        >
                                            Save
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    </section>
                )}

                {hosting && (
                    <section className="space-y-4">
                        <Heading
                            variant="small"
                            title="Hosting"
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
                )}
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
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex min-w-0 items-start gap-3">
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
                <div className="flex items-center gap-2">
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

/**
 * Email domains (ORG-008): anyone whose verified email is at a verified one joins the organization. Each waits for a
 * TXT record that proves the organization owns the domain.
 */
function EmailDomains({ domains }: { domains: EmailDomain[] }) {
    const organization = useOrganization();
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [copied, copy] = useClipboard();

    return (
        <section className="space-y-4">
            <Heading
                variant="small"
                title="Email domains"
                description="People who sign up with a verified email at one of these domains join this organization as members, and people already signed up can join from the organization menu."
            />
            {domains.length > 0 && (
                <ul className="divide-y rounded-lg border">
                    {domains.map((domain) => (
                        <li
                            key={domain.id}
                            className="space-y-3 p-3 text-sm"
                            data-test={`email-domain-${domain.domain}`}
                        >
                            <div className="flex items-center gap-2">
                                <span className="font-medium">
                                    {domain.domain}
                                </span>
                                {domain.verified ? (
                                    <Badge>Verified</Badge>
                                ) : (
                                    <Badge variant="secondary">
                                        Waiting for DNS
                                    </Badge>
                                )}
                                <div className="ml-auto flex gap-2">
                                    {!domain.verified && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            data-test={`verify-email-domain-${domain.domain}`}
                                            onClick={() =>
                                                router.post(
                                                    OrganizationDomainController.verify.url(
                                                        {
                                                            organization:
                                                                organization.slug,
                                                            domain: domain.id,
                                                        },
                                                    ),
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            Check
                                        </Button>
                                    )}
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        data-test={`remove-email-domain-${domain.domain}`}
                                        onClick={() =>
                                            router.delete(
                                                OrganizationDomainController.destroy.url(
                                                    {
                                                        organization:
                                                            organization.slug,
                                                        domain: domain.id,
                                                    },
                                                ),
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Remove
                                    </Button>
                                </div>
                            </div>
                            {!domain.verified && (
                                <div className="space-y-1 text-xs text-muted-foreground">
                                    <p>
                                        Add this TXT record to {domain.domain}{' '}
                                        where its DNS is managed, then check it:
                                    </p>
                                    <p className="flex items-center gap-1 font-mono text-foreground">
                                        <span className="break-all">
                                            {domain.txt}
                                        </span>
                                        <button
                                            type="button"
                                            onClick={() => copy(domain.txt)}
                                            aria-label={`Copy ${domain.txt}`}
                                            className="shrink-0 rounded p-1 text-muted-foreground hover:bg-muted"
                                        >
                                            {copied === domain.txt ? (
                                                <Check className="size-3.5" />
                                            ) : (
                                                <Copy className="size-3.5" />
                                            )}
                                        </button>
                                    </p>
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
            )}
            <Form
                {...OrganizationDomainController.store.form(organization.slug)}
                options={{ preserveScroll: true }}
                resetOnSuccess
                className="flex gap-2"
            >
                {({ processing }) => (
                    <>
                        <Input
                            name="domain"
                            placeholder="acme.com"
                            aria-label="Email domain"
                            className="max-w-xs"
                            data-test="email-domain-input"
                        />
                        <Button
                            variant="outline"
                            disabled={processing}
                            data-test="add-email-domain"
                        >
                            Add domain
                        </Button>
                    </>
                )}
            </Form>
            <InputError message={errors.domain} />
        </section>
    );
}
