import { Head, router, usePage } from '@inertiajs/react';
import {
    LayoutTemplate,
    Package,
    PenLine,
    Shuffle,
    SlidersHorizontal,
    Sparkles,
    Wand2,
    X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useDeferredValue, useEffect, useMemo, useState } from 'react';
import ProjectController from '@/actions/App/Http/Controllers/ProjectController';
import AgentModelPicker from '@/components/agent-model-picker';
import PromptComposer from '@/components/prompt-composer';
import RepositoryPicker from '@/components/repository-picker';
import type { ImportGitHub } from '@/components/repository-picker';
import {
    AppDetails,
    TemplateDetails,
    TemplateLogo,
} from '@/components/app-gallery';
import {
    FreeAppsPanel,
    TemplatesPanel,
    tones,
} from '@/components/ways-to-start';
import { useBuildMode } from '@/hooks/use-build-mode';
import { useOrganization } from '@/hooks/use-organization';
import { indexTemplates, matchTemplates } from '@/lib/template-match';
import { cn } from '@/lib/utils';
import type {
    AgentSelection,
    AppTemplate,
    BuildMode,
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
    // A built-in template picked on the home page opens its details with what they sent (PRJ-004); a free app
    // opens its own once the apps have loaded.
    const startedFrom = templates.find(
        (option) => option.value === start?.template,
    );
    const [prompt, setPrompt] = useState(
        remix?.prompt ?? (startedFrom ? null : start?.prompt) ?? '',
    );
    // The template whose details are open, and its description as they've changed it there.
    const [templateViewing, setTemplateViewing] = useState<{
        template: AppTemplate;
        prompt: string;
    } | null>(() =>
        startedFrom
            ? {
                  template: startedFrom,
                  prompt: start?.prompt ?? startedFrom.prompt,
              }
            : null,
    );
    // "No thanks" to the templates suggested for what they typed, until the prompt is cleared.
    const [dismissed, setDismissed] = useState(false);
    // The free app whose details are open, and what they'd typed when they opened it from a suggestion.
    const [viewing, setViewing] = useState<{
        app: CatalogTemplate;
        keep: string;
    } | null>(null);
    // Starting a project from a free app's or template's details, and why it couldn't.
    const [starting, setStarting] = useState(false);
    const [startError, setStartError] = useState<string | null>(null);
    const { chosen: mode, simple, choose: chooseMode } = useBuildMode();
    // Which way to start is showing (PRJ-001): what they picked on the home page, back from connecting GitHub
    // (the repository import), or "Something new".
    const [way, setWay] = useState<Way>(() => {
        if (startedFrom) {
            return 'template';
        }

        return start?.template || github.returned !== null ? 'existing' : 'new';
    });
    // Their own repository goes under "Something that exists", in Advanced mode (PRJ-009, PRJ-013).
    const importing = way === 'existing' && !simple;
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
        way === 'new' && !dismissed ? matchTemplates(typed, index) : [];

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
     * "Use" in a free app's or template's details creates the project right away (PRJ-004, PRJ-012), named after
     * it; what they typed, if it was suggested, goes after its description.
     */
    const startFrom = (from: AppTemplate, prompt: string) => {
        router.post(
            ProjectController.store(organization.slug).url,
            {
                prompt,
                template: from.value,
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

    /** Open a template's details; `keep` is what they typed, added after its description (PRJ-004). */
    const pickTemplate = (picked: AppTemplate, keep = '') => {
        setStartError(null);
        setTemplateViewing({
            template: picked,
            prompt: withKept(picked.prompt, keep),
        });
    };

    return (
        <>
            <Head title="New project" />

            <div className="flex flex-1 flex-col justify-center px-4 py-10">
                <div className="mx-auto w-full max-w-3xl space-y-8">
                    <div className="space-y-2">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <h1 className="text-3xl font-medium tracking-tight md:text-4xl">
                                {firstName}, what are we working on today?
                            </h1>
                            {mode && (
                                <ModeSwitch mode={mode} onChange={chooseMode} />
                            )}
                        </div>
                        <p className="text-muted-foreground">
                            Pick how you’d like to start. You can change
                            anything later by chatting with the AI.
                        </p>
                    </div>

                    {!mode && <ModeChooser onChoose={chooseMode} />}

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
                        className="grid gap-2 sm:grid-cols-3"
                        role="group"
                        aria-label="How to start"
                    >
                        {WAYS.map((option) => (
                            <WayButton
                                key={option.way}
                                option={option}
                                description={
                                    option.way === 'existing' && !simple
                                        ? 'A free app, or your own code.'
                                        : option.description
                                }
                                pressed={way === option.way}
                                onClick={() => setWay(option.way)}
                            />
                        ))}
                    </div>

                    {(way === 'new' || importing) && (
                        <div className="space-y-4">
                            {importing && (
                                <h2 className="text-sm font-medium text-muted-foreground">
                                    Your own code
                                </h2>
                            )}
                            <PromptComposer
                                action={ProjectController.store(
                                    organization.slug,
                                )}
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
                                        setDismissed(false);
                                    }
                                }}
                                autoFocus={!importing}
                                attachments
                                allowEmpty={
                                    importing && repository.trim() !== ''
                                }
                                header={
                                    importing && (
                                        <RepositoryPicker
                                            value={repository}
                                            onChange={setRepository}
                                            onClose={() => setWay('new')}
                                            github={github}
                                        />
                                    )
                                }
                                size="large"
                                extraData={{
                                    repository: importing ? repository : null,
                                    ...agentFields,
                                }}
                                footer={
                                    selection ? (
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
                                    )
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
                    )}

                    {way === 'template' && (
                        <TemplatesPanel
                            bare
                            templates={templates}
                            selected={templateViewing?.template.value ?? null}
                            onPick={(option) => pickTemplate(option)}
                        />
                    )}

                    {/* Kept mounted while hidden, so ⌘K can still jump to its search (PRJ-012). */}
                    <div
                        className={cn(
                            'space-y-4',
                            way !== 'existing' && 'hidden',
                        )}
                    >
                        {importing && (
                            <h2 className="text-sm font-medium text-muted-foreground">
                                Or install a free app
                            </h2>
                        )}
                        <FreeAppsPanel
                            bare
                            apps={apps}
                            featured={featured}
                            compose={compose}
                            selected={viewing?.app.value ?? null}
                            onView={(app) => view(app)}
                            onShortcut={() => setWay('existing')}
                        />
                    </div>

                    <AppDetails
                        app={viewing?.app ?? null}
                        compose={compose}
                        onOpenChange={(open) => !open && setViewing(null)}
                        starting={starting}
                        error={startError}
                        onUse={(app) =>
                            startFrom(app, withKept(app.prompt, viewing?.keep))
                        }
                    />

                    <TemplateDetails
                        template={templateViewing?.template ?? null}
                        prompt={templateViewing?.prompt ?? ''}
                        onPromptChange={(changed) =>
                            setTemplateViewing(
                                (current) =>
                                    current && { ...current, prompt: changed },
                            )
                        }
                        onOpenChange={(open) =>
                            !open && setTemplateViewing(null)
                        }
                        starting={starting}
                        error={startError}
                        onUse={(picked, changed) => startFrom(picked, changed)}
                    />
                </div>
            </div>
        </>
    );
}

/** A template's or free app's description, with what they'd typed (when it was suggested for it) after it. */
const withKept = (description: string, keep = ''): string =>
    keep.trim() === '' ? description : `${description}\n\n${keep.trim()}`;

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
type Way = 'new' | 'template' | 'existing';

/** The three ways to start a project, in words a first-time user knows (PRJ-001). */
const WAYS: {
    way: Way;
    tone: keyof typeof tones;
    icon: LucideIcon;
    title: string;
    description: string;
}[] = [
    {
        way: 'new',
        tone: 'scratch',
        icon: PenLine,
        title: 'Something new',
        description: 'Describe it and the AI builds it.',
    },
    {
        way: 'template',
        tone: 'template',
        icon: LayoutTemplate,
        title: 'From a template',
        description: 'A ready-made start to change.',
    },
    {
        way: 'existing',
        tone: 'app',
        icon: Package,
        title: 'Something that exists',
        description: 'A free app, already built.',
    },
];

function WayButton({
    option,
    description,
    pressed,
    onClick,
}: {
    option: (typeof WAYS)[number];
    description: string;
    pressed: boolean;
    onClick: () => void;
}) {
    const Icon = option.icon;

    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={pressed}
            className={cn(
                'flex items-center gap-3 rounded-xl border border-input bg-background p-3 text-left transition-colors',
                tones[option.tone].card,
                pressed && tones[option.tone].pressed,
            )}
            data-test={`way-${option.way}`}
        >
            <span
                className={cn(
                    'flex size-9 shrink-0 items-center justify-center rounded-lg',
                    tones[option.tone].tile,
                )}
            >
                <Icon className="size-4" />
            </span>
            <span className="min-w-0">
                <span className="block text-sm font-medium">
                    {option.title}
                </span>
                <span className="block text-xs text-muted-foreground">
                    {description}
                </span>
            </span>
        </button>
    );
}

/** The two modes, in the words the chooser and the switch use (PRJ-013). */
const MODES: {
    mode: BuildMode;
    icon: LucideIcon;
    title: string;
    description: string;
}[] = [
    {
        mode: 'simple',
        icon: Wand2,
        title: 'Simple',
        description: 'I describe it, the AI handles the technical side.',
    },
    {
        mode: 'advanced',
        icon: SlidersHorizontal,
        title: 'Advanced',
        description: 'I want models, files, the shell and git.',
    },
];

/** Asked the first time they're here: how much of the builder to show (PRJ-013). */
function ModeChooser({ onChoose }: { onChoose: (mode: BuildMode) => void }) {
    return (
        <div
            className="space-y-3 rounded-2xl border border-dashed p-4 sm:p-5"
            data-test="mode-chooser"
        >
            <div className="space-y-0.5">
                <h2 className="font-semibold">How do you like to build?</h2>
                <p className="text-sm text-muted-foreground">
                    You can switch any time.
                </p>
            </div>
            <div className="grid gap-2 sm:grid-cols-2">
                {MODES.map(({ mode, icon: Icon, title, description }) => (
                    <button
                        key={mode}
                        type="button"
                        onClick={() => onChoose(mode)}
                        className="flex items-start gap-3 rounded-xl border border-input bg-background p-3 text-left transition-colors hover:border-foreground/30 hover:bg-muted/50"
                        data-test={`mode-${mode}`}
                    >
                        <Icon className="mt-0.5 size-4 shrink-0" />
                        <span>
                            <span className="block text-sm font-medium">
                                {title}
                            </span>
                            <span className="block text-xs text-muted-foreground">
                                {description}
                            </span>
                        </span>
                    </button>
                ))}
            </div>
        </div>
    );
}

/** Simple / Advanced, once they've chosen (PRJ-013). */
function ModeSwitch({
    mode,
    onChange,
}: {
    mode: BuildMode;
    onChange: (mode: BuildMode) => void;
}) {
    return (
        <div
            className="inline-flex shrink-0 rounded-lg border p-0.5 text-xs"
            role="group"
            aria-label="Mode"
        >
            {MODES.map((option) => (
                <button
                    key={option.mode}
                    type="button"
                    onClick={() => onChange(option.mode)}
                    aria-pressed={mode === option.mode}
                    title={option.description}
                    className={cn(
                        'rounded-md px-2.5 py-1 text-muted-foreground transition-colors hover:text-foreground',
                        mode === option.mode &&
                            'bg-muted font-medium text-foreground',
                    )}
                    data-test={`mode-switch-${option.mode}`}
                >
                    {option.title}
                </button>
            ))}
        </div>
    );
}

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
