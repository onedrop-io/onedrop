import { createInertiaApp, router } from '@inertiajs/react';
import AgentModelController from '@/actions/App/Http/Controllers/AgentModelController';
import AiCreditsBalance from '@/components/ai-credits-balance';
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
import { configureAgentModels, forgetCatalog } from '@/lib/agent-models';
import type { Catalog } from '@/lib/agent-models';
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

// The model picker (AGT-002) talks to the session's routes. Connecting or removing a provider (Settings → AI) changes
// which agents and models can run, so every Inertia response drops its catalog and the next open refetches it.
configureAgentModels({
    load: () => jsonRequest<Catalog>(AgentModelController.index.url()),
    favorite: (change) =>
        jsonRequest(AgentModelController.favorite.url(), change, 'PUT'),
    CreditsBalance: AiCreditsBalance,
});

if (typeof window !== 'undefined') {
    router.on('success', forgetCatalog);
}

// This will set light / dark mode on load...
initializeTheme();

markUnseenReloads();
trackCurrentComponent();
