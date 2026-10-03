import { createInertiaApp, router } from '@inertiajs/react';
import AgentModelController from '@/actions/App/Http/Controllers/AgentModelController';
import TemplateScreenshotController from '@/actions/App/Http/Controllers/TemplateScreenshotController';
import AiCreditsBalance from '@/components/ai-credits-balance';
import TurnOnDocker from '@/components/turn-on-docker';
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
import { configureClient, forgetCatalog } from '@/lib/client';
import type { Catalog, Screenshot } from '@/lib/client';
import { jsonRequest } from '@/lib/json-request';
import { isSettingsPage } from '@/lib/settings';
import { markUnseenReloads } from '@/lib/unseen-reloads';

const fallbackName = import.meta.env.VITE_APP_NAME || 'OneDrop';

void createInertiaApp({
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

// The components the web app shares with the desktop app (the model picker, the new-project page's ways to start)
// use the session's routes. Connecting or removing a provider (Settings → AI) changes which agents and models can run,
// so every Inertia response drops the model catalog and the next open refetches it.
configureClient({
    loadCatalog: () => jsonRequest<Catalog>(AgentModelController.index.url()),
    saveFavorite: (change) =>
        jsonRequest(AgentModelController.favorite.url(), change, 'PUT'),
    loadScreenshots: (template) =>
        jsonRequest<{ screenshots: Screenshot[] }>(
            TemplateScreenshotController.url({ query: { template } }),
        ).then(({ screenshots }) => screenshots),
    CreditsBalance: AiCreditsBalance,
    TurnOnDocker,
});

if (typeof window !== 'undefined') {
    router.on('success', forgetCatalog);
}

// This will set light / dark mode on load...
initializeTheme();

markUnseenReloads();
trackCurrentComponent();
