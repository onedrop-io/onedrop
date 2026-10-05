import { Link, usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import AppLogo from '@/components/app-logo';
import { NavDesktopApp } from '@/components/nav-desktop-app';
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
    useSidebar,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { useOrganization } from '@/hooks/use-organization';
import { isProjectPath } from '@/lib/open-project';
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

    // On a phone the sidebar slides over the page: going to another page closes it (PRJ-002).
    const { setOpenMobile } = useSidebar();

    useEffect(() => {
        setOpenMobile(false);
    }, [currentUrl, setOpenMobile]);

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link
                                href={home(organization.slug)}
                                prefetch
                                data-test="sidebar-home"
                            >
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
                <NavDesktopApp />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
