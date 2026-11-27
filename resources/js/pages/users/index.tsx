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
    last_active_at: string | null;
};

/**
 * "Active now", "12 min ago", "3 h ago", "5 days ago", then the date. Activity is recorded at most every
 * 5 minutes (USR-004), so anything newer reads as now.
 */
function formatActive(iso: string): string {
    const minutes = Math.floor((Date.now() - new Date(iso).getTime()) / 60_000);

    if (minutes < 5) {
        return 'Active now';
    }

    if (minutes < 60) {
        return `${minutes} min ago`;
    }

    if (minutes < 24 * 60) {
        return `${Math.floor(minutes / 60)} h ago`;
    }

    const days = Math.floor(minutes / (24 * 60));

    if (days < 30) {
        return days === 1 ? 'Yesterday' : `${days} days ago`;
    }

    return new Date(iso).toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

export default function UsersIndex({ users }: { users: UserRow[] }) {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="Users" />

            <div className="flex flex-1 flex-col gap-8">
                <Heading
                    title="Users"
                    description="Everyone with an account, most recently active first"
                />

                <div className="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <table className="w-full text-left text-sm">
                        <thead className="border-b text-muted-foreground">
                            <tr>
                                <th className="p-3 font-medium">Name</th>
                                <th className="p-3 font-medium">Email</th>
                                <th className="p-3 font-medium">Groups</th>
                                <th className="p-3 font-medium">Joined</th>
                                <th className="p-3 font-medium">Last active</th>
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
                                    <td
                                        className="p-3 whitespace-nowrap"
                                        data-testid="last-active"
                                    >
                                        {user.last_active_at ? (
                                            <time
                                                dateTime={user.last_active_at}
                                                title={new Date(
                                                    user.last_active_at,
                                                ).toLocaleString()}
                                            >
                                                {formatActive(
                                                    user.last_active_at,
                                                )}
                                            </time>
                                        ) : (
                                            <span className="text-muted-foreground">
                                                Never
                                            </span>
                                        )}
                                    </td>
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
