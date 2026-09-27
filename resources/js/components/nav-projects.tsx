import { Link } from '@inertiajs/react';
import { AppWindow } from 'lucide-react';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { show } from '@/routes/projects';
import type { ProjectSummary } from '@/types';

export function NavProjects({ projects }: { projects: ProjectSummary[] }) {
    const { isCurrentUrl } = useCurrentUrl();

    if (projects.length === 0) {
        return null;
    }

    return (
        <SidebarGroup className="px-2 py-0">
            <SidebarGroupLabel>Recent</SidebarGroupLabel>
            <SidebarMenu>
                {projects.map((project) => (
                    <SidebarMenuItem key={project.id}>
                        <SidebarMenuButton
                            asChild
                            isActive={isCurrentUrl(show(project.id))}
                            tooltip={{ children: project.name }}
                        >
                            <Link href={show(project.id)} prefetch>
                                <AppWindow />
                                <span>{project.name}</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                ))}
            </SidebarMenu>
        </SidebarGroup>
    );
}
