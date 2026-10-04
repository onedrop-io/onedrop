import { createInertiaApp } from '@inertiajs/react';
import type { ComponentType } from 'react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import OnboardingLayout from '@/layouts/onboarding-layout';
import SettingsLayout from '@/layouts/settings/layout';
import {
    preserveStateOnSamePageOnly,
    trackCurrentComponent,
} from '@/lib/layout-props';
import { isSettingsPage } from '@/lib/settings';
import { markUnseenReloads } from '@/lib/unseen-reloads';

const fallbackName = import.meta.env.VITE_APP_NAME || 'OneDrop';

// Every page, loaded when it's first shown.
const pages = import.meta.glob<{ default: ComponentType }>('./pages/**/*.tsx');

/**
 * Start the app: in the browser (app.tsx), and in the desktop app (DESK-001), which gives it the first page and its
 * own HTTP client to reach the server.
 */
export function startApp(
    options: Pick<
        Parameters<typeof createInertiaApp>[0] & object,
        'page' | 'http'
    > = {},
): void {
    void createInertiaApp({
        ...options,
        resolve: async (name) => (await pages[`./pages/${name}.tsx`]()).default,
        // The name an admin set (ADMIN-001) comes with every page.
        title: (title, page) => {
            const appName =
                (page?.props.name as string | undefined) || fallbackName;

            return title ? `${title} - ${appName}` : appName;
        },
        layout: (name) => {
            switch (true) {
                case name === 'welcome':
                case name === 'pricing':
                case name.startsWith('share/'):
                    return null;
                case name.startsWith('onboarding/'):
                    return OnboardingLayout;
                case name.startsWith('auth/'):
                    return AuthLayout;
                case isSettingsPage(name):
                    return [AppLayout, SettingsLayout];
                default:
                    return AppLayout;
            }
        },
        strictMode: true,
        defaults: {
            visitOptions: preserveStateOnSamePageOnly,
        },
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
    trackCurrentComponent();
}
