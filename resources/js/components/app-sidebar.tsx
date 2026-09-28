import { Link, usePage } from '@inertiajs/react';
import {
    BookOpen,
    FolderGit2,
    Plus,
    UserCog,
    UserPlus,
    Users,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavProjects } from '@/components/nav-projects';
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
import { DOCUMENTATION_URL, REPOSITORY_URL } from '@/lib/links';
import { dashboard } from '@/routes';
import { index as groupsIndex } from '@/routes/groups';
import { index as invitationsIndex } from '@/routes/invitations';
import { index as usersIndex } from '@/routes/users';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'New project',
        href: dashboard(),
        icon: Plus,
    },
    {
        title: 'Groups',
        href: groupsIndex(),
        icon: Users,
    },
    {
        title: 'Invite people',
        href: invitationsIndex(),
        icon: UserPlus,
    },
];

const adminNavItems: NavItem[] = [
    {
        title: 'Users',
        href: usersIndex(),
        icon: UserCog,
    },
];

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: REPOSITORY_URL,
        icon: FolderGit2,
    },
    {
        title: 'Documentation',
        href: DOCUMENTATION_URL,
        icon: BookOpen,
    },
];

export function AppSidebar() {
    const { auth, sidebarProjects } = usePage().props;

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain
                    items={
                        auth.user.is_admin
                            ? [...mainNavItems, ...adminNavItems]
                            : mainNavItems
                    }
                />
                {sidebarProjects && <NavProjects projects={sidebarProjects} />}
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
