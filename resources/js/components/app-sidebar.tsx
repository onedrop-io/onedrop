import { Link, usePage } from '@inertiajs/react';
import AppLogo from '@/components/app-logo';
import { NavOpenProject } from '@/components/nav-open-project';
import { NavProjects, useSidebarUpdates } from '@/components/nav-projects';
import { NavSearch } from '@/components/nav-search';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { isProjectPath } from '@/lib/open-project';
import { useOrganization } from '@/hooks/use-organization';
import { home } from '@/routes/organizations';

export function AppSidebar() {
    const { sidebarProjects, openProject } = usePage().props;
    const { currentUrl } = useCurrentUrl();
    const organization = useOrganization();
    // An opened project takes over the sidebar while the user is on its pages (TASK-001).
    const opened =
        openProject && isProjectPath(currentUrl, openProject.id)
            ? openProject
            : null;

    useSidebarUpdates(sidebarProjects, openProject);

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={home(organization.slug)} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavSearch />
                {opened ? (
                    <NavOpenProject project={opened} />
                ) : (
                    sidebarProjects && (
                        <NavProjects projects={sidebarProjects} />
                    )
                )}
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
