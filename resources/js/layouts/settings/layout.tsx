import { Link, router, usePage } from '@inertiajs/react';
import {
    Activity,
    Bell,
    Box,
    DatabaseBackup,
    Globe,
    Palette,
    Settings,
    Settings2,
    Shield,
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
import { settingsReturnUrl } from '@/lib/settings';
import { cn, toUrl } from '@/lib/utils';
import { dashboard } from '@/routes';
import { index as backupsIndex } from '@/routes/admin/backups';
import { edit as editGeneral } from '@/routes/admin/general';
import { show as showMonitoring } from '@/routes/admin/monitoring';
import { index as sandboxesIndex } from '@/routes/admin/sandboxes';
import { edit as editServer } from '@/routes/admin/server';
import { index as aiSettings } from '@/routes/agent-connections';
import { edit as editAppearance } from '@/routes/appearance';
import { index as groupsIndex } from '@/routes/groups';
import { index as invitationsIndex } from '@/routes/invitations';
import { edit as editNotifications } from '@/routes/notifications';
import { edit } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import { index as usersIndex } from '@/routes/users';
import type { NavItem } from '@/types';

type NavSection = { title: string; items: NavItem[] };

const accountSection: NavSection = {
    title: 'Account',
    items: [
        { title: 'Profile', href: edit(), icon: User },
        { title: 'Security', href: editSecurity(), icon: Shield },
        { title: 'AI', href: aiSettings(), icon: Sparkles },
        { title: 'Appearance', href: editAppearance(), icon: Palette },
        { title: 'Notifications', href: editNotifications(), icon: Bell },
    ],
};

const peopleSection: NavSection = {
    title: 'People',
    items: [
        { title: 'Groups', href: groupsIndex(), icon: Users },
        { title: 'Invite people', href: invitationsIndex(), icon: UserPlus },
    ],
};

const adminSection: NavSection = {
    title: 'Admin',
    items: [
        { title: 'Users', href: usersIndex(), icon: UserCog },
        { title: 'General', href: editGeneral(), icon: Settings2 },
        { title: 'Sandboxes', href: sandboxesIndex(), icon: Box },
        { title: 'Monitoring', href: showMonitoring(), icon: Activity },
        { title: 'Server', href: editServer(), icon: Globe },
        { title: 'Backups', href: backupsIndex(), icon: DatabaseBackup },
    ],
};

/** Settings pages render in a modal over the app; closing it returns to the page you came from. */
export default function SettingsLayout({ children }: PropsWithChildren) {
    const { auth } = usePage().props;
    const { isCurrentOrParentUrl } = useCurrentUrl();

    const sections = auth.user.is_admin
        ? [accountSection, peopleSection, adminSection]
        : [accountSection, peopleSection];

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
                                            isCurrentOrParentUrl(item.href) &&
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
