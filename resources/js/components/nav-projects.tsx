import { Link, router, usePoll } from '@inertiajs/react';
import {
    Archive,
    ArrowUpDown,
    ChevronRight,
    Clock,
    Folder,
    Pin,
    Plus,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { DragEvent, ReactNode } from 'react';
import { ProjectAvatar } from '@/components/project-avatar';
import { ProjectMenu } from '@/components/project-menu';
import { TaskStatusIcon } from '@/components/task-status-icon';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';
import {
    SidebarGroup,
    SidebarGroupAction,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuAction,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { desktopNotificationStatus } from '@/hooks/use-desktop-notifications';
import { useOrganization } from '@/hooks/use-organization';
import { isProjectPath } from '@/lib/open-project';
import { isTabSeen } from '@/lib/unseen-reloads';
import { useWorkspaceLinks } from '@/lib/workspace-view';
import { order, show, sort as sortProjects } from '@/routes/projects';
import {
    create as createTask,
    show as showTask,
} from '@/routes/projects/tasks';
import type {
    OpenProject,
    ProjectSort,
    SidebarProject,
    SidebarProjects,
} from '@/types';

/**
 * Keep the sidebar current: poll while titles, icons or agents (the main chat's or tasks') are in progress,
 * and tell the user when a project's agents are done.
 */
export function useSidebarUpdates(
    projects: SidebarProjects | null,
    open: OpenProject | null,
) {
    const all = useMemo(
        () =>
            projects
                ? [...projects.pinned, ...projects.recent, ...projects.archived]
                : [],
        [projects],
    );
    const naming = all.some((project) => project.naming);
    const working =
        all.some((project) => project.working) ||
        !!open?.working ||
        !!open?.tasks.some((task) => task.working);
    const drawing = all.some((project) => project.drawing_icon);
    const { start, stop } = usePoll(
        1500,
        { only: ['sidebarProjects', 'openProject'] },
        // Keep polling in background tabs so desktop notifications arrive on time.
        { autoStart: false, keepAlive: true },
    );

    // Pick up AI-generated titles, drawn icons and finished agents as soon as they're ready.
    useEffect(() => {
        if (naming || working || drawing) {
            start();
        } else {
            stop();
        }

        return stop;
    }, [naming, working, drawing, start, stop]);

    useReadyNotifications(all);
    useRefreshWhenSeen(all, open);
}

/**
 * Replies that arrived while the user was in another tab or app stay unread (PRJ-008); coming back to
 * the chat they're on reloads it, which marks it read and clears its dot.
 */
function useRefreshWhenSeen(
    projects: SidebarProject[],
    open: OpenProject | null,
) {
    const unreadHere = useRef(false);
    const path = typeof window === 'undefined' ? '' : window.location.pathname;
    unreadHere.current = open
        ? isProjectPath(path, open.id) &&
          (open.unread || open.tasks.some((task) => task.unread))
        : projects.some(
              (project) => project.unread && isProjectPath(path, project.id),
          );

    useEffect(() => {
        const refresh = () => {
            if (unreadHere.current && isTabSeen()) {
                router.reload({ only: ['sidebarProjects', 'openProject'] });
            }
        };

        window.addEventListener('focus', refresh);
        document.addEventListener('visibilitychange', refresh);

        return () => {
            window.removeEventListener('focus', refresh);
            document.removeEventListener('visibilitychange', refresh);
        };
    }, []);
}

export function NavProjects({ projects }: { projects: SidebarProjects }) {
    const [showArchived, setShowArchived] = useState(false);
    const organization = useOrganization();
    const manual = projects.sort === 'manual';
    // Dropping a project saves the list's new order and sorts by it from now on (PRJ-010).
    const reorder = (ids: number[], done: () => void) =>
        router.put(
            order.url(organization.slug),
            { ids },
            {
                preserveScroll: true,
                preserveState: true,
                only: ['sidebarProjects'],
                onFinish: done,
            },
        );
    const sortMenu = <SortMenu sort={projects.sort} />;

    return (
        <>
            <ProjectGroup
                label="Pinned"
                icon={Pin}
                projects={projects.pinned}
                onReorder={reorder}
                action={projects.recent.length === 0 ? sortMenu : undefined}
            />
            <ProjectGroup
                label={manual ? 'Projects' : 'Recent'}
                icon={manual ? Folder : Clock}
                projects={projects.recent}
                onReorder={reorder}
                action={sortMenu}
            />

            {projects.archived.length > 0 && (
                <SidebarGroup className="px-2 py-0 group-data-[collapsible=icon]:hidden">
                    <SidebarGroupLabel asChild>
                        <button
                            type="button"
                            onClick={() => setShowArchived((shown) => !shown)}
                            aria-expanded={showArchived}
                            data-test="sidebar-archived-toggle"
                        >
                            <Archive className="mr-2" />
                            Archived
                            <ChevronRight
                                className={`ml-1 transition-transform ${showArchived ? 'rotate-90' : ''}`}
                            />
                        </button>
                    </SidebarGroupLabel>
                    {showArchived && (
                        <ProjectList projects={projects.archived} />
                    )}
                </SidebarGroup>
            )}
        </>
    );
}

/** Shows a desktop notification when a project's agent finishes working, unless it's the page in front of the user. */
function useReadyNotifications(projects: SidebarProject[]) {
    const wasWorking = useRef<Set<number> | null>(null);

    useEffect(() => {
        const previous = wasWorking.current;
        wasWorking.current = new Set(
            projects.filter((p) => p.working).map((p) => p.id),
        );

        if (!previous || desktopNotificationStatus() !== 'on') {
            return;
        }

        projects
            .filter((project) => previous.has(project.id) && !project.working)
            .forEach((project) => {
                const url = show(project.id).url;

                if (document.hasFocus() && window.location.pathname === url) {
                    return;
                }

                const notification = new Notification(project.name, {
                    body: 'Ready for your review',
                    tag: `project-${project.id}`,
                    icon: '/favicon.svg',
                });

                notification.onclick = () => {
                    window.focus();
                    router.visit(url);
                    notification.close();
                };
            });
    }, [projects]);
}

const SORTS: { value: ProjectSort; label: string }[] = [
    { value: 'updated', label: 'Last updated' },
    { value: 'created', label: 'Created' },
    { value: 'manual', label: 'Manual' },
];

/** Chooses how pinned and recent projects are sorted (PRJ-010). */
function SortMenu({ sort }: { sort: ProjectSort }) {
    const organization = useOrganization();

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <SidebarGroupAction
                    className="top-1.5 right-3"
                    aria-label="Sort projects"
                    title="Sort projects"
                    data-test="sidebar-sort"
                >
                    <ArrowUpDown />
                </SidebarGroupAction>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-44">
                <DropdownMenuLabel>Sort by</DropdownMenuLabel>
                <DropdownMenuRadioGroup
                    value={sort}
                    onValueChange={(value) =>
                        router.put(
                            sortProjects.url(organization.slug),
                            { sort: value },
                            {
                                preserveScroll: true,
                                preserveState: true,
                                only: ['sidebarProjects'],
                            },
                        )
                    }
                >
                    {SORTS.map((option) => (
                        <DropdownMenuRadioItem
                            key={option.value}
                            value={option.value}
                            data-test={`sidebar-sort-${option.value}`}
                        >
                            {option.label}
                        </DropdownMenuRadioItem>
                    ))}
                </DropdownMenuRadioGroup>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

type Reorder = (ids: number[], done: () => void) => void;

function ProjectGroup({
    label,
    icon: Icon,
    projects,
    onReorder,
    action,
}: {
    label: string;
    icon: LucideIcon;
    projects: SidebarProject[];
    onReorder?: Reorder;
    action?: ReactNode;
}) {
    if (projects.length === 0) {
        return null;
    }

    return (
        <SidebarGroup className="px-2 py-0">
            <SidebarGroupLabel>
                <Icon className="mr-2" />
                {label}
            </SidebarGroupLabel>
            {action}
            <ProjectList projects={projects} onReorder={onReorder} />
        </SidebarGroup>
    );
}

/**
 * Drag-and-drop reordering of a sidebar list (PRJ-010), with the browser's own drag and drop.
 * The list shows the dragged order until the saved one comes back from the server.
 */
function useDragOrder(projects: SidebarProject[], onReorder?: Reorder) {
    const [order, setOrder] = useState<number[] | null>(null);
    const [dragging, setDragging] = useState<number | null>(null);
    const saving = useRef(false);

    // New props (a poll, or the saved order) replace the dragged order, but not mid-drag or mid-save.
    useEffect(() => {
        if (dragging === null && !saving.current) {
            setOrder(null);
        }
    }, [projects, dragging]);

    const ids = order ?? projects.map((project) => project.id);
    const shown = ids
        .map((id) => projects.find((project) => project.id === id))
        .filter((project): project is SidebarProject => !!project);

    if (!onReorder) {
        return {
            shown: projects,
            dragging: null,
            listProps: {},
            itemProps: () => ({}),
        };
    }

    const itemProps = (project: SidebarProject) => ({
        draggable: true,
        onDragStart: (event: DragEvent) => {
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', project.name);
            setDragging(project.id);
            setOrder(ids);
        },
        onDragOver: (event: DragEvent<HTMLElement>) => {
            if (dragging === null || !ids.includes(dragging)) {
                return;
            }

            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';

            if (dragging === project.id) {
                return;
            }

            const box = event.currentTarget.getBoundingClientRect();
            const after = event.clientY > box.top + box.height / 2;
            const rest = ids.filter((id) => id !== dragging);
            const at = rest.indexOf(project.id) + (after ? 1 : 0);
            const next = [...rest.slice(0, at), dragging, ...rest.slice(at)];

            if (next.join() !== ids.join()) {
                setOrder(next);
            }
        },
        onDrop: (event: DragEvent) => event.preventDefault(),
        onDragEnd: (event: DragEvent) => {
            setDragging(null);

            const moved =
                order !== null &&
                order.join() !== projects.map((p) => p.id).join();

            // Escape or a drop outside the list puts it back.
            if (event.dataTransfer.dropEffect === 'none' || !moved) {
                setOrder(null);

                return;
            }

            saving.current = true;
            onReorder(order, () => {
                saving.current = false;
                setOrder(null);
            });
        },
    });

    // Dropping in the gaps between projects counts as a drop on the list, not a cancel.
    const listProps = {
        onDragOver: (event: DragEvent) => {
            if (dragging !== null && ids.includes(dragging)) {
                event.preventDefault();
            }
        },
        onDrop: (event: DragEvent) => event.preventDefault(),
    };

    return { shown, dragging, listProps, itemProps };
}

function ProjectList({
    projects,
    onReorder,
}: {
    projects: SidebarProject[];
    onReorder?: Reorder;
}) {
    const { isCurrentUrl, currentUrl } = useCurrentUrl();
    const [expanded, toggle] = useExpandedProjects();
    // Moving between a project's chats keeps the workspace on the same tab and tool (TASK-004).
    const link = useWorkspaceLinks();
    const { shown, dragging, listProps, itemProps } = useDragOrder(
        projects,
        onReorder,
    );

    return (
        <SidebarMenu {...listProps}>
            {shown.map((project) => {
                const subtitle = project.failed
                    ? 'Sandbox failed'
                    : project.working
                      ? (project.activity ?? 'Working…')
                      : null;

                return (
                    <SidebarMenuItem
                        key={project.id}
                        data-test="sidebar-project"
                        className={
                            dragging === project.id ? 'opacity-50' : undefined
                        }
                        {...itemProps(project)}
                    >
                        {project.tasks.length > 0 && (
                            <button
                                type="button"
                                onClick={() => toggle(project.id)}
                                aria-expanded={isExpanded(
                                    project,
                                    expanded,
                                    currentUrl,
                                )}
                                aria-label={`${isExpanded(project, expanded, currentUrl) ? 'Hide' : 'Show'} tasks in ${project.name}`}
                                className="absolute top-1 left-1 z-10 flex size-6 items-center justify-center rounded-md bg-sidebar-accent text-sidebar-accent-foreground opacity-0 group-hover/menu-item:opacity-100 group-data-[collapsible=icon]:hidden focus-visible:opacity-100"
                                data-test="sidebar-project-toggle"
                            >
                                <ChevronRight
                                    className={`size-4 transition-transform ${isExpanded(project, expanded, currentUrl) ? 'rotate-90' : ''}`}
                                />
                            </button>
                        )}
                        <SidebarMenuButton
                            asChild
                            isActive={isCurrentUrl(show(project.id))}
                            tooltip={{ children: project.name }}
                            className={`group-data-[collapsible=icon]:p-1.5! md:group-focus-within/menu-item:pr-14 md:group-hover/menu-item:pr-14 ${subtitle ? 'h-auto min-h-8 py-1.5' : ''}`}
                        >
                            <Link href={link(project.id, show(project.id).url)}>
                                <ProjectAvatar project={project} />
                                <span className="flex min-w-0 flex-1 flex-col group-data-[collapsible=icon]:hidden">
                                    <span
                                        className={`truncate ${project.unread ? 'font-semibold' : ''}`}
                                    >
                                        {project.name}
                                    </span>
                                    {subtitle && (
                                        <span
                                            className={`truncate text-xs ${project.failed ? 'text-red-600 dark:text-red-400' : 'text-muted-foreground'}`}
                                            data-test="sidebar-project-activity"
                                        >
                                            {subtitle}
                                        </span>
                                    )}
                                </span>
                                {project.naming ? (
                                    <Spinner
                                        className="ml-auto size-3 shrink-0 text-muted-foreground"
                                        aria-label="Coming up with a title"
                                        data-test="sidebar-project-naming"
                                    />
                                ) : (
                                    project.unread && (
                                        <UnreadDot data-test="sidebar-project-unread" />
                                    )
                                )}
                            </Link>
                        </SidebarMenuButton>
                        <SidebarMenuAction
                            asChild
                            showOnHover
                            className="right-7"
                        >
                            <Link
                                href={link(
                                    project.id,
                                    createTask(project.id).url,
                                )}
                                aria-label={`New task in ${project.name}`}
                                title="New task"
                                data-test="sidebar-new-task"
                            >
                                <Plus />
                            </Link>
                        </SidebarMenuAction>
                        <ProjectMenu project={project} />
                        {isExpanded(project, expanded, currentUrl) && (
                            <SidebarMenuSub data-test="sidebar-tasks">
                                {project.tasks.map((task) => (
                                    <SidebarMenuSubItem key={task.id}>
                                        <SidebarMenuSubButton
                                            asChild
                                            isActive={isCurrentUrl(
                                                showTask({
                                                    project: project.id,
                                                    task: task.id,
                                                }),
                                            )}
                                        >
                                            <Link
                                                href={link(
                                                    project.id,
                                                    showTask({
                                                        project: project.id,
                                                        task: task.id,
                                                    }).url,
                                                )}
                                                title={
                                                    task.activity ?? task.title
                                                }
                                                data-test="sidebar-task"
                                            >
                                                <TaskStatusIcon
                                                    stage={task.stage}
                                                    working={task.working}
                                                />
                                                <span
                                                    className={
                                                        task.unread
                                                            ? 'font-semibold'
                                                            : ''
                                                    }
                                                >
                                                    {task.title}
                                                </span>
                                                {task.unread && <UnreadDot />}
                                            </Link>
                                        </SidebarMenuSubButton>
                                    </SidebarMenuSubItem>
                                ))}
                            </SidebarMenuSub>
                        )}
                    </SidebarMenuItem>
                );
            })}
        </SidebarMenu>
    );
}

/** localStorage key for the projects whose tasks are shown (or hidden) in the sidebar, by id. */
const EXPANDED_KEY = 'onedrop.sidebar-expanded-projects';

/** Which projects the user expanded (true) or collapsed (false); unset ones follow `isExpanded`'s default. */
function useExpandedProjects(): [
    Record<number, boolean>,
    (projectId: number) => void,
] {
    const [expanded, setExpanded] = useState<Record<number, boolean>>({});
    const { currentUrl } = useCurrentUrl();
    const current = useRef(currentUrl);
    current.current = currentUrl;

    // Read after mount so server-rendered and client HTML match.
    useEffect(() => {
        try {
            setExpanded(JSON.parse(localStorage.getItem(EXPANDED_KEY) ?? '{}'));
        } catch {
            // Start over with the defaults.
        }
    }, []);

    const toggle = (projectId: number) =>
        setExpanded((state) => {
            const next = {
                ...state,
                [projectId]: !(
                    state[projectId] ??
                    isProjectPath(current.current, projectId)
                ),
            };
            localStorage.setItem(EXPANDED_KEY, JSON.stringify(next));

            return next;
        });

    return [expanded, toggle];
}

/** A project's tasks show when the user expanded it, or, by default, while they're on one of its pages. */
function isExpanded(
    project: SidebarProject,
    expanded: Record<number, boolean>,
    currentUrl: string,
): boolean {
    return (
        project.tasks.length > 0 &&
        (expanded[project.id] ?? isProjectPath(currentUrl, project.id))
    );
}

/** A blue dot for a chat with a reply the user hasn't seen yet (PRJ-003, PRJ-008). */
export function UnreadDot({
    'data-test': dataTest = 'sidebar-task-unread',
}: {
    'data-test'?: string;
}) {
    return (
        <span
            className="ml-auto size-2 shrink-0 rounded-full bg-sky-500 group-data-[collapsible=icon]:hidden"
            aria-label="Unread"
            data-test={dataTest}
        />
    );
}
