import { usePage } from '@inertiajs/react';
import { NavOpenProject } from '@/components/nav-open-project';
import { NavProjects, useSidebarUpdates } from '@/components/nav-projects';
import { NavSearch } from '@/components/nav-search';
import { NavUser } from '@/components/nav-user';
import { OrganizationSwitcher } from '@/components/organization-switcher';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { isProjectPath } from '@/lib/open-project';

export function AppSidebar() {
    const { sidebarProjects, openProject } = usePage().props;
    const { currentUrl } = useCurrentUrl();
    // An opened project takes over the sidebar while the user is on its pages (TASK-001).
    const opened =
        openProject && isProjectPath(currentUrl, openProject.id)
            ? openProject
            : null;

    useSidebarUpdates(sidebarProjects, openProject);

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <OrganizationSwitcher />
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
