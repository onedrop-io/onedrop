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

const reloadedKey = 'onedrop:reloaded-for-page';

/**
 * Load a page's code. When it can't be fetched (a deploy replaced the build, or the dev server restarted), load the
 * page's address in full instead, so a redirect, like the one after deleting the open project, still lands there
 * rather than leaving the browser on the old address (PRJ-003). Once per address, so a broken page can't loop.
 */
async function resolvePage(
    name: string,
    page?: { url: string },
): Promise<ComponentType> {
    try {
        const component = (await pages[`./pages/${name}.tsx`]()).default;
        sessionStorage.removeItem(reloadedKey);

        return component;
    } catch (error) {
        if (!page || sessionStorage.getItem(reloadedKey) === page.url) {
            throw error;
        }

        sessionStorage.setItem(reloadedKey, page.url);
        window.location.assign(page.url);

        return new Promise<never>(() => {});
    }
}

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
        resolve: resolvePage,
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
            // The theme's main colour, so it shows on dark and light alike.
            color: 'var(--primary)',
        },
    });

    // This will set light / dark mode on load...
    initializeTheme();

    markUnseenReloads();
    trackCurrentComponent();
}
