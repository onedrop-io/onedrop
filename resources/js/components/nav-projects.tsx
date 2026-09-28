import { Link, usePoll } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { useEffect, useState } from 'react';
import { ProjectMenu } from '@/components/project-menu';
import { Spinner } from '@/components/ui/spinner';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { show } from '@/routes/projects';
import type { SidebarProject, SidebarProjects } from '@/types';

export function NavProjects({ projects }: { projects: SidebarProjects }) {
    const [showArchived, setShowArchived] = useState(false);
    const naming = [
        ...projects.pinned,
        ...projects.recent,
        ...projects.archived,
    ].some((project) => project.naming);
    const { start, stop } = usePoll(
        1500,
        { only: ['sidebarProjects'] },
        { autoStart: false },
    );

    // Pick up AI-generated titles as soon as they're ready.
    useEffect(() => {
        if (naming) {
            start();
        } else {
            stop();
        }

        return stop;
    }, [naming, start, stop]);

    return (
        <>
            <ProjectGroup label="Pinned" projects={projects.pinned} />
            <ProjectGroup label="Recent" projects={projects.recent} />

            {projects.archived.length > 0 && (
                <SidebarGroup className="px-2 py-0 group-data-[collapsible=icon]:hidden">
                    <SidebarGroupLabel asChild>
                        <button
                            type="button"
                            onClick={() => setShowArchived((shown) => !shown)}
                            aria-expanded={showArchived}
                            data-test="sidebar-archived-toggle"
                        >
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

function ProjectGroup({
    label,
    projects,
}: {
    label: string;
    projects: SidebarProject[];
}) {
    if (projects.length === 0) {
        return null;
    }

    return (
        <SidebarGroup className="px-2 py-0 group-data-[collapsible=icon]:hidden">
            <SidebarGroupLabel>{label}</SidebarGroupLabel>
            <ProjectList projects={projects} />
        </SidebarGroup>
    );
}

function ProjectList({ projects }: { projects: SidebarProject[] }) {
    const { isCurrentUrl } = useCurrentUrl();

    return (
        <SidebarMenu>
            {projects.map((project) => (
                <SidebarMenuItem key={project.id} data-test="sidebar-project">
                    <SidebarMenuButton
                        asChild
                        isActive={isCurrentUrl(show(project.id))}
                        tooltip={{ children: project.name }}
                    >
                        <Link href={show(project.id)} prefetch>
                            <span
                                className={`truncate ${project.unread ? 'font-semibold' : ''}`}
                            >
                                {project.name}
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
                                        className="ml-auto size-2 shrink-0 rounded-full bg-sky-500"
                                        aria-label="Unread"
                                        data-test="sidebar-project-unread"
                                    />
                                )
                            )}
                        </Link>
                    </SidebarMenuButton>
                    <ProjectMenu project={project} />
                </SidebarMenuItem>
            ))}
        </SidebarMenu>
    );
}
