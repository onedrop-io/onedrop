import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { Input } from '@/components/ui/input';
import { show } from '@/routes/users';

type OrganizationRow = {
    id: number;
    name: string;
    slug: string;
    owners: { id: number; name: string; email: string }[];
    members_count: number;
    projects_count: number;
    created_at: string | null;
};

/** Every organization on the install, for platform admins (ORG-006). Counts only: their projects stay theirs. */
export default function AdminOrganizations({
    organizations,
}: {
    organizations: OrganizationRow[];
}) {
    const [query, setQuery] = useState('');
    const needle = query.trim().toLowerCase();
    const shown = needle
        ? organizations.filter(
              (organization) =>
                  organization.name.toLowerCase().includes(needle) ||
                  organization.slug.includes(needle),
          )
        : organizations;

    return (
        <>
            <Head title="Organizations" />

            <div className="flex flex-1 flex-col gap-8">
                <Heading
                    title="Organizations"
                    description={`${organizations.length} on this install, newest first`}
                />

                <Input
                    type="search"
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                    placeholder="Search by name or address"
                    className="max-w-sm"
                    data-test="organization-search"
                />

                <div className="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <table className="w-full text-left text-sm">
                        <thead className="border-b text-muted-foreground">
                            <tr>
                                <th className="p-3 font-medium">Name</th>
                                <th className="p-3 font-medium">Address</th>
                                <th className="p-3 font-medium">Owners</th>
                                <th className="p-3 font-medium">Members</th>
                                <th className="p-3 font-medium">Projects</th>
                                <th className="p-3 font-medium">Created</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {shown.map((organization) => (
                                <tr
                                    key={organization.id}
                                    data-test={`organization-${organization.slug}`}
                                >
                                    <td className="p-3 font-medium">
                                        {organization.name}
                                    </td>
                                    <td className="p-3 text-muted-foreground">
                                        /o/{organization.slug}
                                    </td>
                                    <td className="p-3">
                                        {organization.owners.map(
                                            (owner, index) => (
                                                <span key={owner.id}>
                                                    {index > 0 && ', '}
                                                    <Link
                                                        href={show(owner.id)}
                                                        className="underline-offset-4 hover:underline"
                                                    >
                                                        {owner.name}
                                                    </Link>
                                                </span>
                                            ),
                                        )}
                                    </td>
                                    <td className="p-3">
                                        {organization.members_count}
                                    </td>
                                    <td className="p-3">
                                        {organization.projects_count}
                                    </td>
                                    <td className="p-3">
                                        {organization.created_at}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {shown.length === 0 && (
                        <p className="p-4 text-sm text-muted-foreground">
                            No organization matches “{query}”.
                        </p>
                    )}
                </div>
            </div>
        </>
    );
}
