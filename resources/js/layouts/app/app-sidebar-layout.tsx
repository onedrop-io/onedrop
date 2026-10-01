import { usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import ImpersonationBanner from '@/components/impersonation-banner';
import { isSettingsPage, rememberSettingsReturnUrl } from '@/lib/settings';
import type { AppLayoutProps } from '@/types';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    const { component, url } = usePage();

    useEffect(() => {
        if (!isSettingsPage(component)) {
            rememberSettingsReturnUrl(url);
        }
    }, [component, url]);

    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            <AppContent variant="sidebar" className="min-w-0 overflow-x-clip">
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                {children}
            </AppContent>
            <ImpersonationBanner />
        </AppShell>
    );
}
