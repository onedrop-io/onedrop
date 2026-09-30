import { createInertiaApp } from '@inertiajs/react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { isSettingsPage } from '@/lib/settings';
import { markUnseenReloads } from '@/lib/unseen-reloads';

const fallbackName = import.meta.env.VITE_APP_NAME || 'OneDrop';

void createInertiaApp({
    // The name an admin set (ADMIN-001) comes with every page.
    title: (title, page) => {
        const appName = (page?.props.name as string | undefined) || fallbackName;

        return title ? `${title} - ${appName}` : appName;
    },
    layout: (name) => {
        switch (true) {
            case name === 'welcome':
            case name === 'pricing':
            case name.startsWith('share/'):
            case name.startsWith('onboarding/'):
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case isSettingsPage(name):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={0}>
                {app}
                <Toaster />
            </TooltipProvider>
        );
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();

markUnseenReloads();
