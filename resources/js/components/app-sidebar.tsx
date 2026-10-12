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
import { onPageVisit } from '@/lib/page-visits';
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

    // On a phone the sidebar slides over the page: going to another page closes it (PRJ-002), as soon as the visit
    // starts, so the tap shows at once while the page loads behind it.
    const { setOpenMobile } = useSidebar();

    useEffect(() => {
        setOpenMobile(false);
    }, [currentUrl, setOpenMobile]);

    useEffect(
        () =>
            onPageVisit(
                () => setOpenMobile(false),
                () => {},
            ),
        [setOpenMobile],
    );

    // A project's page needs a lot of code (the workspace, editor, terminal): fetch it while the browser is idle,
    // so opening a project from here only waits for its data (PRJ-002).
    const hasProjects =
        (sidebarProjects?.pinned.length ?? 0) +
            (sidebarProjects?.recent.length ?? 0) >
        0;

    useEffect(() => {
        if (!hasProjects) {
            return;
        }

        const warm = () => void import('@/pages/projects/show');

        if ('requestIdleCallback' in window) {
            const id = window.requestIdleCallback(warm, { timeout: 5000 });

            return () => window.cancelIdleCallback(id);
        }

        const timer = setTimeout(warm, 2000);

        return () => clearTimeout(timer);
    }, [hasProjects]);

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
