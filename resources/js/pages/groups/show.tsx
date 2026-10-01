import { Form, Head, Link, setLayoutProps, usePage } from '@inertiajs/react';
import GroupController from '@/actions/App/Http/Controllers/GroupController';
import GroupMemberController from '@/actions/App/Http/Controllers/GroupMemberController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useOrganization } from '@/hooks/use-organization';
import { index } from '@/routes/groups';
import type { GroupMember } from '@/types';

type Group = { id: number; name: string; description: string | null };

export default function GroupShow({
    group,
    members,
    can,
}: {
    group: Group;
    members: GroupMember[];
    can: { update: boolean; delete: boolean };
}) {
    const { auth, errors } = usePage<{ errors: Record<string, string> }>()
        .props;
    const organization = useOrganization();
    const isMember = members.some((member) => member.id === auth.user.id);

    setLayoutProps({
        breadcrumbs: [{ title: 'Groups', href: index(organization.slug) }],
    });

    return (
        <>
            <Head title={group.name} />

            <div className="flex max-w-3xl flex-1 flex-col gap-10">
                <Heading
                    title={group.name}
                    description={group.description ?? undefined}
                />

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
                                data-test={`member-${member.id}`}
                            >
                                <div className="min-w-0">
                                    <p className="font-medium">{member.name}</p>
                                    <p className="truncate text-sm text-muted-foreground">
                                        {member.email}
                                    </p>
                                </div>
                                <div className="flex items-center gap-2">
                                    <Badge
                                        variant={
                                            member.role === 'owner'
                                                ? 'default'
                                                : 'secondary'
                                        }
                                    >
                                        {member.role}
                                    </Badge>
                                    {can.update && (
                                        <Link
                                            href={GroupMemberController.update({
                                                organization: organization.slug,
                                                group: group.id,
                                                user: member.id,
                                            })}
                                            data={{
                                                role:
                                                    member.role === 'owner'
                                                        ? 'member'
                                                        : 'owner',
                                            }}
                                            as="button"
                                            preserveScroll
                                            className="text-sm text-muted-foreground underline-offset-4 hover:underline"
                                        >
                                            {member.role === 'owner'
                                                ? 'Make member'
                                                : 'Make owner'}
                                        </Link>
                                    )}
                                    {(can.update ||
                                        member.id === auth.user.id) && (
                                        <Link
                                            href={GroupMemberController.destroy(
                                                {
                                                    organization:
                                                        organization.slug,
                                                    group: group.id,
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

                {can.update && (
                    <section className="space-y-4">
                        <Heading
                            variant="small"
                            title="Add member"
                            description="Add someone who already has an account"
                        />
                        <Form
                            {...GroupMemberController.store.form([
                                organization.slug,
                                group.id,
                            ])}
                            options={{ preserveScroll: true }}
                            resetOnSuccess
                            className="flex flex-wrap items-start gap-3"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid min-w-64 flex-1 gap-2">
                                        <Label
                                            htmlFor="email"
                                            className="sr-only"
                                        >
                                            Email
                                        </Label>
                                        <Input
                                            id="email"
                                            name="email"
                                            type="email"
                                            required
                                            placeholder="person@example.com"
                                        />
                                        <InputError message={errors.email} />
                                    </div>
                                    <Label htmlFor="role" className="sr-only">
                                        Role
                                    </Label>
                                    <select
                                        id="role"
                                        name="role"
                                        defaultValue="member"
                                        className="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                                    >
                                        <option value="member">Member</option>
                                        <option value="owner">Owner</option>
                                    </select>
                                    <Button
                                        disabled={processing}
                                        data-test="add-member-button"
                                    >
                                        Add
                                    </Button>
                                </>
                            )}
                        </Form>
                    </section>
                )}

                {can.update && (
                    <section className="space-y-4">
                        <Heading variant="small" title="Group details" />
                        <Form
                            {...GroupController.update.form([
                                organization.slug,
                                group.id,
                            ])}
                            options={{ preserveScroll: true }}
                            className="grid gap-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="group-name">Name</Label>
                                        <Input
                                            id="group-name"
                                            name="name"
                                            defaultValue={group.name}
                                            required
                                        />
                                        <InputError message={errors.name} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="group-description">
                                            Description
                                        </Label>
                                        <Input
                                            id="group-description"
                                            name="description"
                                            defaultValue={
                                                group.description ?? ''
                                            }
                                        />
                                        <InputError
                                            message={errors.description}
                                        />
                                    </div>
                                    <div>
                                        <Button disabled={processing}>
                                            Save
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    </section>
                )}

                {can.delete && (
                    <Dialog>
                        <DialogTrigger asChild>
                            <Button
                                variant="destructive"
                                className="self-start"
                            >
                                Delete group
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogTitle>Delete {group.name}?</DialogTitle>
                            <DialogDescription>
                                Members will lose access. This cannot be undone.
                            </DialogDescription>
                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary">Cancel</Button>
                                </DialogClose>
                                <Button variant="destructive" asChild>
                                    <Link
                                        href={GroupController.destroy([
                                            organization.slug,
                                            group.id,
                                        ])}
                                        as="button"
                                    >
                                        Delete group
                                    </Link>
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                )}

                {!isMember && (
                    <p className="text-sm text-muted-foreground">
                        You're viewing this group as an admin.
                    </p>
                )}
            </div>
        </>
    );
}
