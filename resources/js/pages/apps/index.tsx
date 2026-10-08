import { Head, Link, router, setLayoutProps } from '@inertiajs/react';
import {
    EyeOff,
    Globe,
    LayoutGrid,
    Lock,
    MoreHorizontal,
    Pin,
    PinOff,
    Search,
    SquareArrowOutUpRight,
    Star,
    Users,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { useInitials } from '@/hooks/use-initials';
import { useOrganization } from '@/hooks/use-organization';
import { cn } from '@/lib/utils';
import { index, pin, unpin, update } from '@/routes/apps';
import { show as showProject } from '@/routes/projects';

type GroupOption = { id: number; name: string };

type App = {
    id: number;
    name: string;
    url: string;
    icon_url: string | null;
    visibility: 'private' | 'public' | null;
    published_at: string | null;
    owner: { name: string; avatar: string | null };
    groups: GroupOption[];
    pinned: boolean;
    featured: boolean;
    listed: boolean;
    can: { open_project: boolean; edit: boolean; feature: boolean };
};

/**
 * The organization's Apps page (APPS-001..003): every app its people published where everyone in it can open it,
 * with the user's own pins on top and a filter by group.
 */
export default function AppsIndex({
    apps,
    assignableGroups,
}: {
    apps: App[];
    assignableGroups: GroupOption[];
}) {
    const organization = useOrganization();
    const [query, setQuery] = useState('');
    const [groupId, setGroupId] = useState<number | null>(null);
    const [grouping, setGrouping] = useState<App | null>(null);

    setLayoutProps({
        breadcrumbs: [{ title: 'Apps', href: index(organization.slug) }],
    });

    // Only groups that have an app the user can see.
    const groups = useMemo(() => {
        const byId = new Map<number, GroupOption>();
        apps.filter((app) => app.listed).forEach((app) =>
            app.groups.forEach((group) => byId.set(group.id, group)),
        );

        return [...byId.values()].sort((a, b) => a.name.localeCompare(b.name));
    }, [apps]);

    const search = query.trim().toLowerCase();
    const matches = (app: App) =>
        (!search ||
            app.name.toLowerCase().includes(search) ||
            app.owner.name.toLowerCase().includes(search)) &&
        (groupId === null || app.groups.some((group) => group.id === groupId));

    const listed = apps.filter((app) => app.listed && matches(app));
    const hidden = apps.filter((app) => !app.listed && matches(app));
    const pinned = listed.filter((app) => app.pinned);
    const filtering = search !== '' || groupId !== null;

    const tiles = (list: App[]) => (
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            {list.map((app) => (
                <AppTile
                    key={app.id}
                    app={app}
                    canGroup={assignableGroups.length > 0}
                    onGroups={() => setGrouping(app)}
                />
            ))}
        </div>
    );

    return (
        <>
            <Head title="Apps" />

            <div className="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-wrap items-end justify-between gap-4">
                    <div className="space-y-0.5">
                        <h2 className="text-xl font-semibold tracking-tight">
                            Apps
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            Everything published in {organization.name}.
                        </p>
                    </div>
                    {apps.length > 0 && (
                        <div className="relative w-full sm:w-72">
                            <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                value={query}
                                onChange={(event) =>
                                    setQuery(event.target.value)
                                }
                                placeholder="Search apps or people…"
                                aria-label="Search apps"
                                className="pl-9"
                                data-test="apps-search"
                            />
                        </div>
                    )}
                </header>

                {groups.length > 0 && (
                    <div
                        className="flex flex-wrap gap-2"
                        role="group"
                        aria-label="Filter by group"
                    >
                        <FilterChip
                            active={groupId === null}
                            onClick={() => setGroupId(null)}
                        >
                            All
                        </FilterChip>
                        {groups.map((group) => (
                            <FilterChip
                                key={group.id}
                                active={groupId === group.id}
                                onClick={() => setGroupId(group.id)}
                                testId={`apps-group-${group.id}`}
                            >
                                <Users className="size-3.5" />
                                {group.name}
                            </FilterChip>
                        ))}
                    </div>
                )}

                {apps.length === 0 ? (
                    <div
                        className="flex flex-1 flex-col items-center justify-center gap-3 rounded-xl border border-dashed p-12 text-center"
                        data-test="apps-empty"
                    >
                        <LayoutGrid className="size-8 text-muted-foreground" />
                        <p className="font-medium">No apps yet</p>
                        <p className="max-w-sm text-sm text-muted-foreground">
                            Apps show up here once someone in{' '}
                            {organization.name} publishes one from its project's
                            Publish button.
                        </p>
                    </div>
                ) : (
                    <>
                        {pinned.length > 0 && !filtering && (
                            <section className="space-y-3">
                                <h3 className="text-sm font-medium text-muted-foreground">
                                    Your apps
                                </h3>
                                {tiles(pinned)}
                            </section>
                        )}

                        <section className="space-y-3" data-test="apps-all">
                            {pinned.length > 0 && !filtering && (
                                <h3 className="text-sm font-medium text-muted-foreground">
                                    All apps
                                </h3>
                            )}
                            {listed.length > 0 ? (
                                tiles(listed)
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    No apps match.
                                </p>
                            )}
                        </section>

                        {hidden.length > 0 && (
                            <section
                                className="space-y-3"
                                data-test="apps-hidden"
                            >
                                <h3 className="text-sm font-medium text-muted-foreground">
                                    Hidden from Apps
                                </h3>
                                {tiles(hidden)}
                            </section>
                        )}
                    </>
                )}
            </div>

            <GroupsDialog
                app={grouping}
                groups={assignableGroups}
                onClose={() => setGrouping(null)}
            />
        </>
    );
}

function FilterChip({
    active,
    onClick,
    testId,
    children,
}: {
    active: boolean;
    onClick: () => void;
    testId?: string;
    children: ReactNode;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={active}
            data-test={testId}
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-sm transition-colors',
                active
                    ? 'border-primary bg-primary text-primary-foreground'
                    : 'hover:bg-muted',
            )}
        >
            {children}
        </button>
    );
}

function AppTile({
    app,
    canGroup,
    onGroups,
}: {
    app: App;
    canGroup: boolean;
    onGroups: () => void;
}) {
    const organization = useOrganization();
    const getInitials = useInitials();
    const target = [organization.slug, app.id] as [string, number];
    const options = { preserveScroll: true };
    const change = (data: Record<string, boolean>) =>
        router.patch(update.url(target), data, options);

    return (
        <div
            className={cn(
                'group relative flex gap-4 rounded-xl border bg-card p-4 transition-colors hover:border-primary/40 hover:bg-muted/30',
                !app.listed && 'opacity-70',
            )}
            data-test="app-tile"
        >
            <a
                href={app.url}
                target="_blank"
                rel="noreferrer"
                className="absolute inset-0 rounded-xl focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                aria-label={`Open ${app.name}`}
                data-test="app-open"
            />
            <AppIcon app={app} />
            <div className="min-w-0 flex-1 space-y-1.5">
                <div className="flex items-center gap-1.5 pr-14">
                    <p className="truncate font-medium" data-test="app-name">
                        {app.name}
                    </p>
                    {app.featured && (
                        <Star
                            className="size-3.5 shrink-0 fill-amber-400 text-amber-400"
                            aria-label="Featured"
                        />
                    )}
                </div>
                <p className="truncate text-xs text-muted-foreground">
                    {app.url.replace(/^https?:\/\//, '')}
                </p>
                <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                    <Avatar className="size-4">
                        {app.owner.avatar && (
                            <AvatarImage src={app.owner.avatar} alt="" />
                        )}
                        <AvatarFallback className="text-[8px]">
                            {getInitials(app.owner.name)}
                        </AvatarFallback>
                    </Avatar>
                    <span className="truncate">{app.owner.name}</span>
                    {app.visibility && (
                        <span
                            className="ml-auto flex shrink-0 items-center gap-1"
                            title={
                                app.visibility === 'public'
                                    ? 'Anyone with the link'
                                    : `People in ${organization.name}`
                            }
                        >
                            {app.visibility === 'public' ? (
                                <Globe className="size-3" />
                            ) : (
                                <Lock className="size-3" />
                            )}
                            {app.visibility === 'public' ? 'Public' : 'Private'}
                        </span>
                    )}
                </div>
                {app.groups.length > 0 && (
                    <div className="flex flex-wrap gap-1 pt-0.5">
                        {app.groups.map((group) => (
                            <Badge
                                key={group.id}
                                variant="secondary"
                                className="font-normal"
                            >
                                {group.name}
                            </Badge>
                        ))}
                    </div>
                )}
            </div>

            <div className="absolute top-2 right-2 z-10 flex items-center">
                <button
                    type="button"
                    onClick={() =>
                        app.pinned
                            ? router.delete(unpin.url(target), options)
                            : router.put(pin.url(target), {}, options)
                    }
                    aria-label={app.pinned ? 'Unpin' : 'Pin to Your apps'}
                    aria-pressed={app.pinned}
                    className={cn(
                        'rounded-md p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground',
                        !app.pinned &&
                            'opacity-0 group-focus-within:opacity-100 group-hover:opacity-100',
                    )}
                    data-test="app-pin"
                >
                    <Pin
                        className={cn('size-4', app.pinned && 'fill-current')}
                    />
                </button>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <button
                            type="button"
                            aria-label={`More for ${app.name}`}
                            className="rounded-md p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground"
                            data-test="app-menu"
                        >
                            <MoreHorizontal className="size-4" />
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" className="w-52">
                        <DropdownMenuItem asChild>
                            <a href={app.url} target="_blank" rel="noreferrer">
                                <SquareArrowOutUpRight />
                                Open app
                            </a>
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            onSelect={() =>
                                app.pinned
                                    ? router.delete(unpin.url(target), options)
                                    : router.put(pin.url(target), {}, options)
                            }
                        >
                            {app.pinned ? <PinOff /> : <Pin />}
                            {app.pinned ? 'Unpin' : 'Pin to Your apps'}
                        </DropdownMenuItem>
                        {app.can.open_project && (
                            <DropdownMenuItem asChild>
                                <Link
                                    href={showProject(app.id)}
                                    data-test="app-open-project"
                                >
                                    <LayoutGrid />
                                    Open project
                                </Link>
                            </DropdownMenuItem>
                        )}
                        {(app.can.edit || app.can.feature) && (
                            <DropdownMenuSeparator />
                        )}
                        {app.can.edit && canGroup && (
                            <DropdownMenuItem
                                onSelect={onGroups}
                                data-test="app-groups"
                            >
                                <Users />
                                Groups…
                            </DropdownMenuItem>
                        )}
                        {app.can.feature && (
                            <DropdownMenuItem
                                onSelect={() =>
                                    change({ featured: !app.featured })
                                }
                                data-test="app-feature"
                            >
                                <Star />
                                {app.featured ? 'Unfeature' : 'Feature'}
                            </DropdownMenuItem>
                        )}
                        {app.can.edit && (
                            <DropdownMenuItem
                                onSelect={() => change({ listed: !app.listed })}
                                data-test="app-hide"
                            >
                                <EyeOff />
                                {app.listed ? 'Hide from Apps' : 'List in Apps'}
                            </DropdownMenuItem>
                        )}
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </div>
    );
}

/** The app's icon, or a colored tile with its initial, colored by its id like the sidebar's (PRJ-007). */
function AppIcon({ app }: { app: App }) {
    const hue = Math.round((app.id * 137.508) % 360);

    return app.icon_url ? (
        <img
            src={app.icon_url}
            alt=""
            className="size-12 shrink-0 rounded-xl object-contain"
        />
    ) : (
        <span
            className="flex size-12 shrink-0 items-center justify-center rounded-xl text-lg font-semibold text-white uppercase shadow-xs"
            style={{
                background: `linear-gradient(135deg, oklch(0.68 0.15 ${hue}), oklch(0.55 0.17 ${(hue + 40) % 360}))`,
            }}
            aria-hidden
        >
            {app.name.trim().charAt(0) || '?'}
        </span>
    );
}

/** Choose the groups an app is in (APPS-002). */
function GroupsDialog({
    app,
    groups,
    onClose,
}: {
    app: App | null;
    groups: GroupOption[];
    onClose: () => void;
}) {
    const organization = useOrganization();
    const [chosen, setChosen] = useState<number[]>([]);
    const [openFor, setOpenFor] = useState<number | null>(null);

    // Start from the app's groups each time it opens for one.
    if (app && openFor !== app.id) {
        setOpenFor(app.id);
        setChosen(app.groups.map((group) => group.id));
    }

    const save = () => {
        if (!app) {
            return;
        }

        router.patch(
            update.url([organization.slug, app.id]),
            {
                group_ids: chosen.filter((id) =>
                    groups.some((group) => group.id === id),
                ),
            },
            { preserveScroll: true, onSuccess: close },
        );
    };

    const close = () => {
        setOpenFor(null);
        onClose();
    };

    return (
        <Dialog open={app !== null} onOpenChange={(open) => !open && close()}>
            <DialogContent className="sm:max-w-sm">
                <DialogTitle>Groups</DialogTitle>
                <DialogDescription>
                    Choose the groups {app?.name} is for. People can filter Apps
                    by group.
                </DialogDescription>
                <div className="max-h-72 space-y-1 overflow-y-auto">
                    {groups.map((group) => (
                        <label
                            key={group.id}
                            className="flex cursor-pointer items-center gap-3 rounded-md px-2 py-1.5 text-sm hover:bg-muted"
                        >
                            <Checkbox
                                checked={chosen.includes(group.id)}
                                onCheckedChange={(checked) =>
                                    setChosen((ids) =>
                                        checked
                                            ? [...ids, group.id]
                                            : ids.filter(
                                                  (id) => id !== group.id,
                                              ),
                                    )
                                }
                                data-test={`app-group-option-${group.id}`}
                            />
                            {group.name}
                        </label>
                    ))}
                </div>
                <DialogFooter>
                    <Button variant="outline" onClick={close}>
                        Cancel
                    </Button>
                    <Button onClick={save} data-test="app-groups-save">
                        Save
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
