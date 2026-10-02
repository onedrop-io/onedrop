import { router } from '@inertiajs/react';
import { PenLine } from 'lucide-react';
import { useState } from 'react';
import { AppDetails } from '@/components/app-gallery';
import PromptComposer from '@/components/prompt-composer';
import {
    FreeAppsPanel,
    TemplatesPanel,
    WayToStart,
    panelClass,
    tones,
} from '@/components/ways-to-start';
import { cn } from '@/lib/utils';
import { start } from '@/routes';
import type { AppTemplate, CatalogTemplate, FeaturedApp } from '@/types';

/** Free apps shown on the home page before they search or ask for all, so the rest of the page isn't 500 cards away. */
const HOME_APPS = 12;

/**
 * The new-project page's three ways to start, on the home page (HOME-004). Sending a prompt, or using a free app,
 * signs them up first (or goes straight there when signed in) and has it waiting on the new-project page.
 */
export function StartSection({
    isLoggedIn,
    templates,
    apps,
    featured,
    compose,
}: {
    isLoggedIn: boolean;
    templates: AppTemplate[];
    apps?: CatalogTemplate[];
    featured?: FeaturedApp[];
    compose: boolean;
}) {
    const [prompt, setPrompt] = useState('');
    const [template, setTemplate] = useState<string | null>(null);
    const [viewing, setViewing] = useState<CatalogTemplate | null>(null);
    const [starting, setStarting] = useState(false);
    const [startError, setStartError] = useState<string | null>(null);

    const pickTemplate = (picked: AppTemplate) => {
        setTemplate(picked.value);
        setPrompt(picked.prompt);

        const composer = document.getElementById('composer-prompt');

        if (composer instanceof HTMLTextAreaElement) {
            composer.focus();
            composer.setSelectionRange(0, 0);
            composer.scrollTop = 0;
        }
    };

    const startFrom = (app: CatalogTemplate) => {
        router.post(
            start().url,
            { template: app.value },
            {
                onStart: () => setStarting(true),
                onFinish: () => setStarting(false),
                onError: (errors) =>
                    setStartError(
                        Object.values(errors)[0] ??
                            'Couldn’t start the project. Try again.',
                    ),
            },
        );
    };

    return (
        <section
            id="start"
            className="dark mx-auto max-w-6xl scroll-mt-20 px-6 py-24 text-foreground"
            data-test="home-start"
        >
            <h2 className="font-display text-3xl font-bold tracking-[-0.02em] sm:text-4xl">
                Start building now
            </h2>
            <p className="mt-4 max-w-xl text-lg text-[#B3A69C]">
                There are three ways to start. You can change anything later by
                chatting with the AI.
            </p>

            <div className="mt-12 space-y-6">
                <div
                    className={cn('space-y-4', panelClass, tones.scratch.panel)}
                >
                    <WayToStart
                        tone="scratch"
                        icon={PenLine}
                        title="Start from scratch"
                        description="Describe your app in your own words. The AI builds it for you."
                    />
                    <PromptComposer
                        action={start()}
                        field="prompt"
                        placeholder="Describe the app you want to build…"
                        value={prompt}
                        onValueChange={(value) => {
                            setPrompt(value);

                            if (value.trim() === '') {
                                setTemplate(null);
                            }
                        }}
                        size="large"
                        extraData={{ template }}
                        footer={
                            <span className="text-xs">
                                {isLoggedIn
                                    ? 'Opens it in your workspace.'
                                    : 'Free to start. You’ll create an account first.'}
                            </span>
                        }
                    />
                </div>

                <TemplatesPanel
                    templates={templates}
                    selected={template}
                    onPick={pickTemplate}
                />

                <FreeAppsPanel
                    apps={apps}
                    featured={featured}
                    compose={compose}
                    selected={null}
                    limit={HOME_APPS}
                    onView={(app) => {
                        setStartError(null);
                        setViewing(app);
                    }}
                />
            </div>

            <AppDetails
                app={viewing}
                compose={compose}
                signUpFirst={!isLoggedIn}
                onOpenChange={(open) => !open && setViewing(null)}
                starting={starting}
                error={startError}
                onUse={startFrom}
            />
        </section>
    );
}
