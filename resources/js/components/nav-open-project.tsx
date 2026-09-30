import { Link } from '@inertiajs/react';
import { ArrowLeft, CircleDot, Kanban, Plus } from 'lucide-react';
import { UnreadDot } from '@/components/nav-projects';
import { TaskStatusIcon } from '@/components/task-status-icon';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { closeProject } from '@/lib/open-project';
import { useWorkspaceLinks } from '@/lib/workspace-view';
import { board, show } from '@/routes/projects';
import {
    create as createTask,
    show as showTask,
} from '@/routes/projects/tasks';
import type { OpenProject, SidebarTask, TaskStage } from '@/types';

/** The order the sidebar lists an opened project's tasks in. */
const GROUPS: { stage: TaskStage; label: string }[] = [
    { stage: 'in_progress', label: 'In progress' },
    { stage: 'review', label: 'Review' },
    { stage: 'todo', label: 'To do' },
    { stage: 'done', label: 'Done' },
];

/**
 * The sidebar for an opened project (TASK-001): back to everything, its main chat, its board,
 * a new task, and its tasks by column.
 */
export function NavOpenProject({ project }: { project: OpenProject }) {
    const { isCurrentUrl } = useCurrentUrl();
    // Switching between Main and tasks keeps the workspace on the same tab and tool (TASK-004).
    const link = useWorkspaceLinks();

    return (
        <>
            <SidebarGroup className="px-2 py-0">
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton
                            onClick={closeProject}
                            tooltip={{ children: 'Back to all projects' }}
                            data-test="open-project-back"
                        >
                            <ArrowLeft />
                            <span>Back</span>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                    <SidebarMenuItem>
                        <SidebarMenuButton
                            asChild
                            isActive={isCurrentUrl(show(project.id))}
                            tooltip={{ children: `${project.name}: main chat` }}
                        >
                            <Link
                                href={link(project.id, show(project.id).url)}
                                data-test="open-project-main"
                            >
                                {project.working ? (
                                    <TaskStatusIcon
                                        stage="in_progress"
                                        working
                                    />
                                ) : (
                                    <CircleDot />
                                )}
                                <span
                                    className={`truncate ${project.unread ? 'font-semibold' : ''}`}
                                >
                                    Main
                                </span>
                                {project.unread && (
                                    <UnreadDot data-test="open-project-main-unread" />
                                )}
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                    <SidebarMenuItem>
                        <SidebarMenuButton
                            asChild
                            isActive={isCurrentUrl(board(project.id))}
                            tooltip={{ children: 'Board' }}
                        >
                            <Link
                                href={board(project.id)}
                                prefetch
                                data-test="open-project-board"
                            >
                                <Kanban />
                                <span>Board</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                    <SidebarMenuItem>
                        <SidebarMenuButton
                            asChild
                            isActive={isCurrentUrl(createTask(project.id))}
                            tooltip={{ children: 'New task' }}
                            className="border border-sidebar-border"
                        >
                            <Link
                                href={link(
                                    project.id,
                                    createTask(project.id).url,
                                )}
                                data-test="open-project-new-task"
                            >
                                <Plus />
                                <span>New task</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarGroup>

            {GROUPS.map(({ stage, label }) => (
                <TaskGroup
                    key={stage}
                    label={label}
                    projectId={project.id}
                    tasks={project.tasks.filter((task) => task.stage === stage)}
                />
            ))}
        </>
    );
}

function TaskGroup({
    label,
    projectId,
    tasks,
}: {
    label: string;
    projectId: number;
    tasks: SidebarTask[];
}) {
    const { isCurrentUrl } = useCurrentUrl();
    const link = useWorkspaceLinks();

    if (tasks.length === 0) {
        return null;
    }

    return (
        <SidebarGroup
            className="px-2 py-0 group-data-[collapsible=icon]:hidden"
            data-test="open-project-group"
        >
            <SidebarGroupLabel>{label}</SidebarGroupLabel>
            <SidebarMenu>
                {tasks.map((task) => {
                    const href = showTask({
                        project: projectId,
                        task: task.id,
                    });

                    return (
                        <SidebarMenuItem key={task.id}>
                            <SidebarMenuButton
                                asChild
                                isActive={isCurrentUrl(href)}
                                className={
                                    task.activity ? 'h-auto min-h-8 py-1.5' : ''
                                }
                            >
                                <Link
                                    href={link(projectId, href.url)}
                                    data-test="open-project-task"
                                >
                                    <TaskStatusIcon
                                        stage={task.stage}
                                        working={task.working}
                                    />
                                    <span className="flex min-w-0 flex-1 flex-col">
                                        <span
                                            className={`truncate ${task.unread ? 'font-semibold' : ''}`}
                                        >
                                            {task.title}
                                        </span>
                                        {task.activity && (
                                            <span className="truncate text-xs text-muted-foreground">
                                                {task.activity}
                                            </span>
                                        )}
                                    </span>
                                    {task.unread && <UnreadDot />}
                                </Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    );
                })}
            </SidebarMenu>
        </SidebarGroup>
    );
}
