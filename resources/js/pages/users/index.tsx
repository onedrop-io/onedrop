import { Head, Link, usePage } from '@inertiajs/react';
import UserController from '@/actions/App/Http/Controllers/UserController';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { index, show } from '@/routes/users';

type UserRow = {
    id: number;
    name: string;
    email: string;
    is_admin: boolean;
    groups_count: number;
    created_at: string | null;
};

export default function UsersIndex({ users }: { users: UserRow[] }) {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="Users" />

            <div className="flex flex-1 flex-col gap-8">
                <Heading title="Users" description="Everyone with an account" />

                <div className="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <table className="w-full text-left text-sm">
                        <thead className="border-b text-muted-foreground">
                            <tr>
                                <th className="p-3 font-medium">Name</th>
                                <th className="p-3 font-medium">Email</th>
                                <th className="p-3 font-medium">Groups</th>
                                <th className="p-3 font-medium">Joined</th>
                                <th className="p-3 font-medium">Access</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {users.map((user) => (
                                <tr key={user.id}>
                                    <td className="p-3 font-medium">
                                        <Link
                                            href={show(user.id)}
                                            className="underline-offset-4 hover:underline"
                                        >
                                            {user.name}
                                        </Link>
                                    </td>
                                    <td className="p-3">{user.email}</td>
                                    <td className="p-3">{user.groups_count}</td>
                                    <td className="p-3">{user.created_at}</td>
                                    <td className="p-3">
                                        <div className="flex items-center gap-3">
                                            {user.is_admin && (
                                                <Badge>admin</Badge>
                                            )}
                                            {user.id !== auth.user.id && (
                                                <Link
                                                    href={UserController.update(
                                                        user.id,
                                                    )}
                                                    data={{
                                                        is_admin:
                                                            !user.is_admin,
                                                    }}
                                                    as="button"
                                                    preserveScroll
                                                    className="text-muted-foreground underline-offset-4 hover:underline"
                                                >
                                                    {user.is_admin
                                                        ? 'Remove admin'
                                                        : 'Make admin'}
                                                </Link>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}

UsersIndex.layout = {
    breadcrumbs: [{ title: 'Users', href: index() }],
};
