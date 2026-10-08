import {
    Form,
    Head,
    Link,
    router,
    setLayoutProps,
    usePage,
} from '@inertiajs/react';
import { Check, Copy } from 'lucide-react';
import OrganizationDomainController from '@/actions/App/Http/Controllers/OrganizationDomainController';
import OrganizationMemberController from '@/actions/App/Http/Controllers/OrganizationMemberController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useClipboard } from '@/hooks/use-clipboard';
import { useOrganization } from '@/hooks/use-organization';
import { index } from '@/routes/organizations/members';
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

const ROLES: { value: OrganizationRole; label: string }[] = [
    { value: 'member', label: 'Member' },
    { value: 'admin', label: 'Admin' },
    { value: 'owner', label: 'Owner' },
];

/** An organization's name, address, computers, members, email domains, task copy limit, secrets and own hosting accounts (ORG-004, ORG-005, ORG-008, CMP-003, TASK-003, SECRET-003, HOST-003). */

/** Who's in an organization and their roles, and its email domains (ORG-004, ORG-008). */
export default function OrganizationMembers({
    members,
    domains,
    can,
}: {
    members: Member[];
    domains: EmailDomain[] | null;
    can: { update: boolean; manage_owners: boolean; remove: boolean };
}) {
    const { auth, errors } = usePage<{ errors: Record<string, string> }>()
        .props;
    const organization = useOrganization();

    setLayoutProps({
        breadcrumbs: [{ title: 'Members', href: index(organization.slug) }],
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
            <Head title="Members" />

            <div className="flex max-w-3xl flex-1 flex-col gap-10">
                <section className="space-y-4">
                    <Heading
                        title="Members"
                        description={`${members.length} ${members.length === 1 ? 'person' : 'people'} in ${organization.name}`}
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
            </div>
        </>
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
