import { Link, router, usePoll } from '@inertiajs/react';
import { Archive, ChevronRight, Clock, Pin, Plus } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { ProjectAvatar } from '@/components/project-avatar';
import { ProjectMenu } from '@/components/project-menu';
import { TaskStatusIcon } from '@/components/task-status-icon';
import { Spinner } from '@/components/ui/spinner';
import {
    SidebarGroup,
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
import { isProjectPath } from '@/lib/open-project';
import { useWorkspaceLinks } from '@/lib/workspace-view';
import { show } from '@/routes/projects';
import {
    create as createTask,
    show as showTask,
} from '@/routes/projects/tasks';
import type { OpenProject, SidebarProject, SidebarProjects } from '@/types';

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
}

export function NavProjects({ projects }: { projects: SidebarProjects }) {
    const [showArchived, setShowArchived] = useState(false);

    return (
        <>
            <ProjectGroup
                label="Pinned"
                icon={Pin}
                projects={projects.pinned}
            />
            <ProjectGroup
                label="Recent"
                icon={Clock}
                projects={projects.recent}
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

function ProjectGroup({
    label,
    icon: Icon,
    projects,
}: {
    label: string;
    icon: LucideIcon;
    projects: SidebarProject[];
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
            <ProjectList projects={projects} />
        </SidebarGroup>
    );
}

function ProjectList({ projects }: { projects: SidebarProject[] }) {
    const { isCurrentUrl, currentUrl } = useCurrentUrl();
    const [expanded, toggle] = useExpandedProjects();
    // Moving between a project's chats keeps the workspace on the same tab and tool (TASK-004).
    const link = useWorkspaceLinks();

    return (
        <SidebarMenu>
            {projects.map((project) => {
                const subtitle = project.failed
                    ? 'Sandbox failed'
                    : project.working
                      ? (project.activity ?? 'Working…')
                      : null;

                return (
                    <SidebarMenuItem
                        key={project.id}
                        data-test="sidebar-project"
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
                                        <span
                                            className="ml-auto size-2 shrink-0 rounded-full bg-sky-500 group-data-[collapsible=icon]:hidden"
                                            aria-label="Unread"
                                            data-test="sidebar-project-unread"
                                        />
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
                                                <span>{task.title}</span>
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
