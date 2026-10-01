import {
    Form,
    Head,
    Link,
    router,
    setLayoutProps,
    usePage,
} from '@inertiajs/react';
import { useRef } from 'react';
import OrganizationController from '@/actions/App/Http/Controllers/OrganizationController';
import OrganizationMemberController from '@/actions/App/Http/Controllers/OrganizationMemberController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { OrganizationMark } from '@/components/organization-mark';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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

const ROLES: { value: OrganizationRole; label: string }[] = [
    { value: 'member', label: 'Member' },
    { value: 'admin', label: 'Admin' },
    { value: 'owner', label: 'Owner' },
];

/** An organization's name, address and members (ORG-004, ORG-005). */
export default function OrganizationEdit({
    details,
    members,
    can,
}: {
    details: { name: string; slug: string; logo_url: string | null };
    members: Member[];
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
            </div>
        </>
    );
}
