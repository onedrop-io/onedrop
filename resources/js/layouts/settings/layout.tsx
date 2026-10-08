import { Link, router, usePage } from '@inertiajs/react';
import {
    Activity,
    Bell,
    Box,
    Building2,
    Contact,
    CloudUpload,
    DatabaseBackup,
    Globe,
    KeyRound,
    Monitor,
    Palette,
    Settings,
    Settings2,
    Shield,
    ShieldAlert,
    Sparkles,
    User,
    UserCog,
    UserPlus,
    Users,
} from 'lucide-react';
import type { PropsWithChildren } from 'react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { useOrganization } from '@/hooks/use-organization';
import { settingsReturnUrl } from '@/lib/settings';
import { cn, toUrl } from '@/lib/utils';
import { dashboard } from '@/routes';
import { index as backupsIndex } from '@/routes/admin/backups';
import { edit as editGeneral } from '@/routes/admin/general';
import { index as hostingIndex } from '@/routes/admin/hosting';
import { show as showMonitoring } from '@/routes/admin/monitoring';
import { index as reviewsIndex } from '@/routes/admin/reviews';
import { index as sandboxesIndex } from '@/routes/admin/sandboxes';
import { edit as editServer } from '@/routes/admin/server';
import { index as aiSettings } from '@/routes/agent-connections';
import { edit as editAppearance } from '@/routes/appearance';
import { index as desktopDevices } from '@/routes/desktop-devices';
import { index as groupsIndex } from '@/routes/groups';
import { edit as editOrganization } from '@/routes/organizations';
import { index as organizationHosting } from '@/routes/organizations/hosting';
import { index as organizationMembers } from '@/routes/organizations/members';
import { index as organizationSecrets } from '@/routes/organizations/secrets';
import { index as invitationsIndex } from '@/routes/invitations';
import { edit as editNotifications } from '@/routes/notifications';
import { edit } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import { index as usersIndex } from '@/routes/users';
import { index as adminOrganizationsIndex } from '@/routes/admin/organizations';
import type { CurrentOrganization, NavItem } from '@/types';

type NavSection = {
    title: string;
    items: (NavItem & { exact?: boolean })[];
};

const accountSection: NavSection = {
    title: 'Account',
    items: [
        { title: 'Profile', href: edit(), icon: User },
        { title: 'Security', href: editSecurity(), icon: Shield },
        { title: 'AI', href: aiSettings(), icon: Sparkles },
        { title: 'Appearance', href: editAppearance(), icon: Palette },
        { title: 'Notifications', href: editNotifications(), icon: Bell },
        { title: 'Desktop app', href: desktopDevices(), icon: Monitor },
    ],
};

/**
 * The organization's settings and people (ORG-004, ORG-005, SECRET-003, HOST-003). General, Secrets and Hosting
 * accounts are for the people who manage it; everyone sees its members, groups and invites.
 */
const organizationSection = (
    organization: CurrentOrganization,
): NavSection => ({
    title: 'Organization',
    items: [
        ...(organization.manages
            ? [
                  {
                      title: 'General',
                      href: editOrganization(organization.slug),
                      // Its own pages (Members, Secrets…) sit under its address.
                      exact: true,
                      icon: Building2,
                  },
              ]
            : []),
        {
            title: 'Members',
            href: organizationMembers(organization.slug),
            icon: Contact,
        },
        { title: 'Groups', href: groupsIndex(organization.slug), icon: Users },
        {
            title: 'Invite people',
            href: invitationsIndex(organization.slug),
            icon: UserPlus,
        },
        ...(organization.manages
            ? [
                  {
                      title: 'Secrets',
                      href: organizationSecrets(organization.slug),
                      icon: KeyRound,
                  },
                  {
                      title: 'Hosting accounts',
                      href: organizationHosting(organization.slug),
                      icon: CloudUpload,
                  },
              ]
            : []),
    ],
});

/** Install-wide settings; Organizations (ORG-006) and Reviews (ADMIN-006) only on the hosted install. */
const adminSection = (multiTenant: boolean): NavSection => ({
    title: 'Admin',
    items: [
        { title: 'Users', href: usersIndex(), icon: UserCog },
        ...(multiTenant
            ? [
                  {
                      title: 'Organizations',
                      href: adminOrganizationsIndex(),
                      icon: Building2,
                  },
                  // Apps held by the abuse check (ADMIN-006).
                  {
                      title: 'Reviews',
                      href: reviewsIndex(),
                      icon: ShieldAlert,
                  },
              ]
            : []),
        { title: 'General', href: editGeneral(), icon: Settings2 },
        { title: 'Sandboxes', href: sandboxesIndex(), icon: Box },
        { title: 'Hosting', href: hostingIndex(), icon: CloudUpload },
        { title: 'Monitoring', href: showMonitoring(), icon: Activity },
        { title: 'Server', href: editServer(), icon: Globe },
        { title: 'Backups', href: backupsIndex(), icon: DatabaseBackup },
    ],
});

/** Settings pages render in a modal over the app; closing it returns to the page you came from. */
export default function SettingsLayout({ children }: PropsWithChildren) {
    const { auth, multiTenant } = usePage().props;
    const organization = useOrganization();
    const { isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();

    const people = organizationSection(organization);
    const sections = auth.user.is_admin
        ? [accountSection, people, adminSection(multiTenant)]
        : [accountSection, people];

    const close = () => {
        router.visit(settingsReturnUrl() ?? dashboard().url);
    };

    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open) {
                    close();
                }
            }}
        >
            <DialogContent
                className="flex h-[calc(100dvh-2rem)] max-h-[900px] flex-col gap-0 overflow-hidden p-0 sm:max-w-6xl"
                data-test="settings-modal"
            >
                <div className="flex shrink-0 items-center gap-2 border-b px-5 py-3.5">
                    <Settings className="size-5 text-muted-foreground" />
                    <DialogTitle className="text-lg font-medium">
                        Settings
                    </DialogTitle>
                    <DialogDescription className="sr-only">
                        Manage your account, people, and preferences
                    </DialogDescription>
                </div>

                <div className="flex min-h-0 flex-1 flex-col md:flex-row">
                    <nav
                        className="flex shrink-0 gap-4 overflow-x-auto border-b p-3 md:w-60 md:flex-col md:gap-5 md:overflow-y-auto md:border-r md:border-b-0"
                        aria-label="Settings"
                    >
                        {sections.map((section) => (
                            <div
                                key={section.title}
                                className="flex shrink-0 items-center gap-1 md:flex-col md:items-stretch"
                            >
                                <p className="hidden px-2 pb-1 text-xs font-medium text-muted-foreground md:block">
                                    {section.title}
                                </p>
                                {section.items.map((item) => (
                                    <Link
                                        key={toUrl(item.href)}
                                        href={item.href}
                                        prefetch
                                        className={cn(
                                            'flex shrink-0 items-center gap-2.5 rounded-md px-2 py-1.5 text-sm text-foreground/80 transition-colors hover:bg-muted hover:text-foreground',
                                            (item.exact
                                                ? isCurrentUrl(item.href)
                                                : isCurrentOrParentUrl(
                                                      item.href,
                                                  )) &&
                                                'bg-muted font-medium text-foreground',
                                        )}
                                    >
                                        {item.icon && (
                                            <item.icon className="size-4 text-muted-foreground" />
                                        )}
                                        {item.title}
                                    </Link>
                                ))}
                            </div>
                        ))}
                    </nav>

                    <div className="min-h-0 flex-1 overflow-y-auto p-6 md:p-8">
                        <section className="max-w-3xl space-y-12">
                            {children}
                        </section>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}
