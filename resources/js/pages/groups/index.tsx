import { Form, Head, Link, setLayoutProps } from '@inertiajs/react';
import GroupController from '@/actions/App/Http/Controllers/GroupController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useOrganization } from '@/hooks/use-organization';
import { index, show } from '@/routes/groups';
import type { GroupSummary } from '@/types';

export default function GroupsIndex({ groups }: { groups: GroupSummary[] }) {
    const organization = useOrganization();

    setLayoutProps({
        breadcrumbs: [{ title: 'Groups', href: index(organization.slug) }],
    });

    return (
        <>
            <Head title="Groups" />

            <div className="flex flex-1 flex-col gap-8">
                <Heading
                    title="Groups"
                    description="Organize people into groups"
                />

                <Form
                    {...GroupController.store.form(organization.slug)}
                    resetOnSuccess
                    className="grid max-w-xl gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">New group name</Label>
                                <Input
                                    id="name"
                                    name="name"
                                    required
                                    placeholder="e.g. Engineering"
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="description">
                                    Description (optional)
                                </Label>
                                <Input id="description" name="description" />
                                <InputError message={errors.description} />
                            </div>

                            <div>
                                <Button
                                    disabled={processing}
                                    data-test="create-group-button"
                                >
                                    Create group
                                </Button>
                            </div>
                        </>
                    )}
                </Form>

                {groups.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        You're not in any groups yet.
                    </p>
                ) : (
                    <ul className="divide-y rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                        {groups.map((group) => (
                            <li key={group.id}>
                                <Link
                                    href={show([organization.slug, group.id])}
                                    className="flex items-center justify-between gap-4 p-4 hover:bg-muted/50"
                                >
                                    <div className="min-w-0">
                                        <p className="font-medium">
                                            {group.name}
                                        </p>
                                        {group.description && (
                                            <p className="truncate text-sm text-muted-foreground">
                                                {group.description}
                                            </p>
                                        )}
                                    </div>
                                    <div className="flex shrink-0 items-center gap-2 text-sm text-muted-foreground">
                                        {group.role && (
                                            <Badge variant="secondary">
                                                {group.role}
                                            </Badge>
                                        )}
                                        {group.members_count}{' '}
                                        {group.members_count === 1
                                            ? 'member'
                                            : 'members'}
                                    </div>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}
