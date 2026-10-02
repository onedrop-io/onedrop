import { Deferred, Head, router, usePage } from '@inertiajs/react';
import { PenLine, Shuffle, Sparkles, X } from 'lucide-react';
import { useDeferredValue, useEffect, useMemo, useState } from 'react';
import ProjectController from '@/actions/App/Http/Controllers/ProjectController';
import AgentModelPicker from '@/components/agent-model-picker';
import PromptComposer from '@/components/prompt-composer';
import RepositoryPicker, {
    RepositoryToggle,
} from '@/components/repository-picker';
import type { ImportGitHub } from '@/components/repository-picker';
import { AppDetails, TemplateLogo } from '@/components/app-gallery';
import {
    FreeAppsPanel,
    TemplatesPanel,
    WayToStart,
    panelClass,
    tones,
} from '@/components/ways-to-start';
import { useOrganization } from '@/hooks/use-organization';
import { indexTemplates, matchTemplates } from '@/lib/template-match';
import { cn } from '@/lib/utils';
import type {
    AgentSelection,
    AppTemplate,
    CatalogTemplate,
    FeaturedApp,
} from '@/types';
import { dashboard } from '@/routes';

export default function CreateProject({
    defaultAi,
    agent,
    templates,
    apps,
    featured,
    compose,
    remix,
    start,
    github,
}: {
    defaultAi: string | null;
    agent: AgentSelection | null;
    templates: AppTemplate[];
    /** Every free open-source app from the registries, popular ones first (PRJ-012); deferred, since a registry may be slow. */
    apps?: CatalogTemplate[];
    /** The popular ones with a picture each, for the coverflow; deferred on their own, since finding pictures is slow. */
    featured?: FeaturedApp[];
    /** New projects' sandboxes can run registry templates' Docker Compose stacks. */
    compose: boolean;
    /** "Remix this" on a share page: the shared project's name and prompt (SHARE-002). */
    remix: { name: string; prompt: string } | null;
    /** What they typed or picked on the home page before signing up (HOME-004). */
    start: { prompt: string | null; template: string | null } | null;
    /** Importing a repository instead (PRJ-009). */
    github: ImportGitHub;
}) {
    const [selection, setSelection] = useState(agent);
    const { auth } = usePage().props;
    const organization = useOrganization();
    // A built-in template picked on the home page comes with its prompt; a free app opens its details below.
    const startedFrom = templates.find(
        (option) => option.value === start?.template,
    );
    const [prompt, setPrompt] = useState(
        remix?.prompt ?? start?.prompt ?? startedFrom?.prompt ?? '',
    );
    const [template, setTemplate] = useState<string | null>(
        startedFrom?.value ?? null,
    );
    // "No thanks" to the templates suggested for what they typed, until the prompt is cleared.
    const [dismissed, setDismissed] = useState(false);
    // The free app whose details are open, and what they'd typed when they opened it from a suggestion.
    const [viewing, setViewing] = useState<{
        app: CatalogTemplate;
        keep: string;
    } | null>(null);
    // Starting a project from the free app's details, and why it couldn't.
    const [starting, setStarting] = useState(false);
    const [startError, setStartError] = useState<string | null>(null);
    // Back from connecting GitHub here: open the import again.
    const [importing, setImporting] = useState(github.returned !== null);
    const [repository, setRepository] = useState('');
    const firstName = auth.user.name.split(' ')[0];
    const [openedStart, setOpenedStart] = useState(false);

    // What's already built, to offer when what they type sounds like one of them (PRJ-001).
    const index = useMemo(
        () =>
            indexTemplates([
                ...templates.map(builtIn),
                ...(apps ?? []).filter((app) => compose || !app.compose),
            ]),
        [templates, apps, compose],
    );
    const typed = useDeferredValue(prompt);
    const suggestions =
        template === null && !importing && !dismissed
            ? matchTemplates(typed, index)
            : [];

    const agentFields: Record<string, string | null> = selection
        ? {
              agent_harness: selection.harness,
              agent_provider: selection.provider,
              agent_model: selection.model,
              agent_variant: selection.variant,
          }
        : {};

    const view = (app: CatalogTemplate, keep = '') => {
        setStartError(null);
        setViewing({ app, keep });
    };

    // The free app picked on the home page, once the apps have loaded.
    useEffect(() => {
        const picked = apps?.find((app) => app.value === start?.template);

        if (picked && !openedStart) {
            setOpenedStart(true);
            setViewing({ app: picked, keep: '' });
        }
    }, [apps, start, openedStart]);

    /**
     * "Use" in a free app's details creates the project right away (PRJ-012), since the prompt may be scrolled out
     * of sight; what they typed, if it was suggested, goes after the app's description.
     */
    const startFrom = (app: CatalogTemplate, keep = '') => {
        router.post(
            ProjectController.store(organization.slug).url,
            {
                prompt:
                    keep.trim() === ''
                        ? app.prompt
                        : `${app.prompt}\n\n${keep.trim()}`,
                template: app.value,
                ...agentFields,
            },
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

    /** Start from a template; `keep` is what they typed, added after its description. */
    const pickTemplate = (picked: AppTemplate, keep = '') => {
        setTemplate(picked.value);
        setPrompt(
            keep.trim() === ''
                ? picked.prompt
                : `${picked.prompt}\n\n${keep.trim()}`,
        );

        const composer = document.getElementById('composer-prompt');

        if (composer instanceof HTMLTextAreaElement) {
            composer.focus();
            composer.setSelectionRange(0, 0);
            composer.scrollTop = 0;
        }
    };

    return (
        <>
            <Head title="New project" />

            <div className="flex flex-1 flex-col justify-center px-4 py-10">
                <div className="mx-auto w-full max-w-3xl space-y-8">
                    <div className="space-y-2">
                        <h1 className="text-3xl font-medium tracking-tight md:text-4xl">
                            {firstName}, what are we working on today?
                        </h1>
                        {!importing && (
                            <p className="text-muted-foreground">
                                There are three ways to start. You can change
                                anything later by chatting with the AI.
                            </p>
                        )}
                    </div>

                    {remix && (
                        <p
                            className="flex items-center gap-2 text-sm text-muted-foreground"
                            data-test="remix-note"
                        >
                            <Shuffle className="size-4" />
                            Remixing “{remix.name}”. Make the prompt your own,
                            then send it.
                        </p>
                    )}

                    <div
                        className={cn(
                            'space-y-4',
                            !importing && [panelClass, tones.scratch.panel],
                        )}
                    >
                        {!importing && (
                            <WayToStart
                                tone="scratch"
                                icon={PenLine}
                                title="Start from scratch"
                                description="Describe your app in your own words. The AI builds it for you."
                            />
                        )}

                        <PromptComposer
                            action={ProjectController.store(organization.slug)}
                            field="prompt"
                            placeholder={
                                importing
                                    ? 'What should the agent do with it? (optional)'
                                    : 'Describe the app you want to build…'
                            }
                            value={prompt}
                            onValueChange={(value) => {
                                setPrompt(value);

                                if (value.trim() === '') {
                                    setTemplate(null);
                                    setDismissed(false);
                                }
                            }}
                            autoFocus={!importing}
                            attachments
                            allowEmpty={importing && repository.trim() !== ''}
                            header={
                                importing && (
                                    <RepositoryPicker
                                        value={repository}
                                        onChange={setRepository}
                                        onClose={() => setImporting(false)}
                                        github={github}
                                    />
                                )
                            }
                            size="large"
                            extraData={{
                                template: importing ? null : template,
                                repository: importing ? repository : null,
                                ...agentFields,
                            }}
                            footer={
                                <>
                                    {selection ? (
                                        <AgentModelPicker
                                            selection={selection}
                                            onChange={setSelection}
                                        />
                                    ) : (
                                        defaultAi && (
                                            <span className="inline-flex items-center gap-1">
                                                <Sparkles className="size-3" />
                                                {defaultAi}
                                            </span>
                                        )
                                    )}
                                    <RepositoryToggle
                                        pressed={importing}
                                        onPressedChange={setImporting}
                                    />
                                </>
                            }
                        />

                        {suggestions.length > 0 && (
                            <AlreadyBuilt
                                suggestions={suggestions}
                                onPick={(picked) =>
                                    picked.compose
                                        ? view(picked, prompt)
                                        : pickTemplate(picked, prompt)
                                }
                                onDismiss={() => setDismissed(true)}
                            />
                        )}
                    </div>

                    <div className={cn('space-y-6', importing && 'hidden')}>
                        <TemplatesPanel
                            templates={templates}
                            selected={template}
                            onPick={(option) => pickTemplate(option)}
                        />

                        <FreeAppsPanel
                            apps={apps}
                            featured={featured}
                            compose={compose}
                            selected={template}
                            onView={(app) => view(app)}
                        />
                    </div>

                    <AppDetails
                        app={viewing?.app ?? null}
                        compose={compose}
                        onOpenChange={(open) => !open && setViewing(null)}
                        starting={starting}
                        error={startError}
                        onUse={(app) => startFrom(app, viewing?.keep)}
                    />
                </div>
            </div>
        </>
    );
}

/** A built-in template in the shape of a registry one, to match against what they type. */
const builtIn = (template: AppTemplate): CatalogTemplate => ({
    ...template,
    registry: null,
    logo: null,
    tags: ['business'],
    compose: false,
    version: null,
    links: { website: null, github: null, docs: null },
});

/** Templates and free apps that sound like what they typed, offered before they start from scratch (PRJ-001). */
function AlreadyBuilt({
    suggestions,
    onPick,
    onDismiss,
}: {
    suggestions: CatalogTemplate[];
    onPick: (template: CatalogTemplate) => void;
    onDismiss: () => void;
}) {
    return (
        <div
            className="space-y-2 rounded-xl border border-amber-500/40 bg-amber-500/10 p-3"
            data-test="already-built"
        >
            <div className="flex items-start justify-between gap-3">
                <p className="text-sm">
                    <span className="font-medium">
                        This might already exist.
                    </span>{' '}
                    <span className="text-muted-foreground">
                        Start from one of these to save time, or just send yours
                        to start from scratch.
                    </span>
                </p>
                <button
                    type="button"
                    onClick={onDismiss}
                    className="inline-flex shrink-0 items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
                    data-test="already-built-dismiss"
                >
                    <X className="size-3.5" />
                    No thanks
                </button>
            </div>
            <div className="flex flex-wrap gap-2">
                {suggestions.map((suggestion) => (
                    <button
                        key={suggestion.value}
                        type="button"
                        onClick={() => onPick(suggestion)}
                        className="inline-flex items-center gap-2 rounded-lg border border-input bg-background py-1.5 pr-3 pl-1.5 text-sm hover:border-amber-500/60"
                        data-test="already-built-option"
                    >
                        <TemplateLogo
                            template={suggestion}
                            className="size-6"
                        />
                        <span className="font-medium">{suggestion.label}</span>
                        <span className="text-xs text-muted-foreground">
                            {suggestion.compose ? 'Free app' : 'Template'}
                        </span>
                    </button>
                ))}
            </div>
        </div>
    );
}

CreateProject.layout = {
    breadcrumbs: [{ title: 'New project', href: dashboard() }],
};
