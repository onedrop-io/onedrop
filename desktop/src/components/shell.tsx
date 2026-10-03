import { ChevronDown, Globe, LogOut, Plus, Settings } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { ProjectAvatar } from '@/components/project-avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import ResizeHandle from '@/components/workspace/resize-handle';
import { useResizableWidth } from '@/hooks/use-resizable-width';
import { cn } from '@/lib/utils';
import type { SidebarProject, SidebarProjects } from '@/types';
import { api } from '../lib/api';
import { useBlobUrl, useCoalesced, useInterval } from '../lib/hooks';
import { isLooking, notify, openInBrowser } from '../lib/native';
import type { Me } from '../lib/types';
import Logo from './logo';
import NewProject from './new-project';
import ProjectMenu from './project-menu';
import TitleBar from './title-bar';
import WorkspaceView from './workspace';

const SELECTED_KEY = 'onedrop.selected-project';
const SIDEBAR_WIDTH_KEY = 'onedrop.sidebar-width';
const SIDEBAR_WIDTH = { initial: 260, min: 200, max: 420 };

/** The signed-in app: the projects on the left (DESK-002), the open project or a new one on the right. */
export default function Shell({
    me,
    onSignOut,
    onSwitched,
}: {
    me: Me;
    onSignOut: () => void;
    /** The user moved to another organization: reload who they are and where. */
    onSwitched: () => void;
}) {
    const [projects, setProjects] = useState<SidebarProjects | null>(null);
    const [selected, setSelected] = useState<number | null>(
        () => Number(localStorage.getItem(SELECTED_KEY)) || null,
    );
    const [width, setWidth] = useResizableWidth(
        SIDEBAR_WIDTH_KEY,
        SIDEBAR_WIDTH,
    );
    const working = useRef<Map<number, string> | null>(null);

    const select = (id: number | null) => {
        localStorage.setItem(SELECTED_KEY, id ? String(id) : '');
        setSelected(id);
    };

    const refresh = useCoalesced(async () => {
        const next = await api<SidebarProjects>('projects');
        const all = [...next.pinned, ...next.recent, ...next.archived];

        // A project whose agent just finished is ready for review: say so when the user is elsewhere (NOTIF-001).
        if (working.current && !isLooking()) {
            for (const project of all) {
                if (working.current.has(project.id) && !project.working) {
                    void notify(project.name, 'Ready for your review.').catch(
                        () => {},
                    );
                }
            }
        }

        working.current = new Map(
            all
                .filter((project) => project.working)
                .map((project) => [project.id, project.name]),
        );
        setProjects(next);
    });

    useEffect(refresh, [refresh]);

    const anyWorking =
        !!projects &&
        [...projects.pinned, ...projects.recent].some(
            (project) => project.working,
        );

    useInterval(refresh, anyWorking ? 3_000 : 15_000);

    return (
        <div className="flex h-screen">
            <aside
                className="flex shrink-0 flex-col border-r bg-sidebar text-sidebar-foreground"
                style={{ width }}
                aria-label="Projects"
            >
                <TitleBar leading className="justify-end">
                    <button
                        type="button"
                        onClick={() => select(null)}
                        title="New project"
                        aria-label="New project"
                        className="flex size-7 items-center justify-center rounded-md text-muted-foreground hover:bg-sidebar-accent hover:text-foreground"
                        data-test="new-project"
                    >
                        <Plus className="size-4" />
                    </button>
                </TitleBar>

                <nav className="flex-1 space-y-4 overflow-y-auto px-2 pb-4">
                    {projects && (
                        <>
                            <ProjectList
                                title="Pinned"
                                projects={projects.pinned}
                                selected={selected}
                                onSelect={select}
                                onChanged={refresh}
                            />
                            <ProjectList
                                title="Recent"
                                projects={projects.recent}
                                selected={selected}
                                onSelect={select}
                                onChanged={refresh}
                            />
                            <ProjectList
                                title="Archived"
                                projects={projects.archived}
                                selected={selected}
                                onSelect={select}
                                onChanged={refresh}
                                collapsed
                            />
                            {projects.pinned.length + projects.recent.length ===
                                0 && (
                                <p className="px-2 text-sm text-muted-foreground">
                                    No projects yet. Describe one to start.
                                </p>
                            )}
                        </>
                    )}
                </nav>

                <AccountMenu
                    me={me}
                    onSignOut={onSignOut}
                    onSwitch={(id) =>
                        void api(
                            'user/organization',
                            { organization: id },
                            'PUT',
                        )
                            .then(() => {
                                select(null);
                                onSwitched();
                            })
                            .catch((error: Error) => toast.error(error.message))
                    }
                />
            </aside>

            <ResizeHandle
                label="Resize sidebar"
                side="left"
                width={width}
                limits={SIDEBAR_WIDTH}
                onResize={setWidth}
            />

            <main className="flex min-w-0 flex-1 flex-col">
                {selected ? (
                    <WorkspaceView
                        key={selected}
                        projectId={selected}
                        realtime={me.realtime}
                        onChanged={refresh}
                        onMissing={() => select(null)}
                    />
                ) : (
                    <NewProject
                        userName={me.user.name}
                        onCreated={(id) => {
                            select(id);
                            refresh();
                        }}
                    />
                )}
            </main>
        </div>
    );
}

function ProjectList({
    title,
    projects,
    selected,
    onSelect,
    onChanged,
    collapsed = false,
}: {
    title: string;
    projects: SidebarProject[];
    selected: number | null;
    onSelect: (id: number | null) => void;
    onChanged: () => void;
    collapsed?: boolean;
}) {
    const [open, setOpen] = useState(!collapsed);

    if (projects.length === 0) {
        return null;
    }

    return (
        <section>
            <button
                type="button"
                onClick={() => setOpen(!open)}
                className="flex w-full items-center gap-1 px-2 py-1 text-xs font-medium text-muted-foreground hover:text-foreground"
            >
                {title}
                <ChevronDown
                    className={cn(
                        'size-3 transition-transform',
                        !open && '-rotate-90',
                    )}
                />
            </button>
            {open && (
                <ul className="mt-1 space-y-0.5">
                    {projects.map((project) => (
                        <ProjectItem
                            key={project.id}
                            project={project}
                            active={project.id === selected}
                            onSelect={() => onSelect(project.id)}
                            onChanged={onChanged}
                            onDeleted={() => {
                                if (project.id === selected) {
                                    onSelect(null);
                                }

                                onChanged();
                            }}
                        />
                    ))}
                </ul>
            )}
        </section>
    );
}

function ProjectItem({
    project,
    active,
    onSelect,
    onChanged,
    onDeleted,
}: {
    project: SidebarProject;
    active: boolean;
    onSelect: () => void;
    onChanged: () => void;
    onDeleted: () => void;
}) {
    const icon = useBlobUrl(project.icon_url);

    return (
        <li>
            <div
                role="button"
                tabIndex={0}
                onClick={onSelect}
                onKeyDown={(event) => event.key === 'Enter' && onSelect()}
                className={cn(
                    'group flex items-center gap-2 rounded-md px-2 py-1.5 text-sm outline-none hover:bg-sidebar-accent focus-visible:ring-2 focus-visible:ring-ring',
                    active &&
                        'bg-sidebar-accent font-medium text-sidebar-accent-foreground',
                )}
                data-test="sidebar-project"
            >
                <ProjectAvatar project={{ ...project, icon_url: icon }} />
                <span className="min-w-0 flex-1">
                    <span
                        className={cn(
                            'block truncate',
                            project.naming && 'animate-pulse',
                        )}
                    >
                        {project.name}
                    </span>
                    {project.working && project.activity && (
                        <span className="block truncate text-xs font-normal text-muted-foreground">
                            {project.activity}
                        </span>
                    )}
                </span>
                {project.unread && !active && (
                    <span
                        className="size-2 shrink-0 rounded-full bg-blue-500"
                        aria-label="Unread"
                        data-test="sidebar-unread"
                    />
                )}
                <ProjectMenu
                    project={project}
                    onChanged={onChanged}
                    onDeleted={onDeleted}
                />
            </div>
        </li>
    );
}

function AccountMenu({
    me,
    onSignOut,
    onSwitch,
}: {
    me: Me;
    onSignOut: () => void;
    onSwitch: (organization: number) => void;
}) {
    const host = new URL(me.app.url).host;
    const web = (path: string) =>
        void openInBrowser(new URL(path, me.app.url).toString());

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    className="m-2 flex items-center gap-2 rounded-md p-2 text-left hover:bg-sidebar-accent"
                    data-test="account-menu"
                >
                    <Logo logo={me.app.logo} className="size-6 shrink-0" />
                    <span className="min-w-0 flex-1">
                        <span className="block truncate text-sm font-medium">
                            {me.user.name}
                        </span>
                        <span className="block truncate text-xs text-muted-foreground">
                            {host}
                        </span>
                    </span>
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent side="top" align="start" className="w-56">
                <DropdownMenuLabel className="truncate font-normal text-muted-foreground">
                    {me.user.email}
                </DropdownMenuLabel>
                <DropdownMenuSeparator />
                {me.organizations.length > 1 && (
                    <>
                        <DropdownMenuRadioGroup
                            value={String(me.organization.id)}
                            onValueChange={(id) => onSwitch(Number(id))}
                        >
                            {me.organizations.map((organization) => (
                                <DropdownMenuRadioItem
                                    key={organization.id}
                                    value={String(organization.id)}
                                >
                                    {organization.name}
                                </DropdownMenuRadioItem>
                            ))}
                        </DropdownMenuRadioGroup>
                        <DropdownMenuSeparator />
                    </>
                )}
                <DropdownMenuItem onSelect={() => web('/dashboard')}>
                    <Globe /> Open {me.app.name} in browser
                </DropdownMenuItem>
                <DropdownMenuItem onSelect={() => web('/settings/profile')}>
                    <Settings /> Settings
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem onSelect={onSignOut} data-test="sign-out">
                    <LogOut /> Sign out
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
