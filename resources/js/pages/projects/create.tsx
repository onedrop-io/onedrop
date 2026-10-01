import { Deferred, Head, router, usePage } from '@inertiajs/react';
import {
    LayoutTemplate,
    Package,
    PenLine,
    Shuffle,
    Sparkles,
    X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useDeferredValue, useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import ProjectController from '@/actions/App/Http/Controllers/ProjectController';
import AgentModelPicker from '@/components/agent-model-picker';
import AppCoverflow from '@/components/app-coverflow';
import PromptComposer from '@/components/prompt-composer';
import RepositoryPicker, {
    RepositoryToggle,
} from '@/components/repository-picker';
import type { ImportGitHub } from '@/components/repository-picker';
import AppGallery, {
    AppDetails,
    TemplateLogo,
    templateIcons,
} from '@/components/app-gallery';
import { Skeleton } from '@/components/ui/skeleton';
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
    /** Importing a repository instead (PRJ-009). */
    github: ImportGitHub;
}) {
    const [selection, setSelection] = useState(agent);
    const { auth } = usePage().props;
    const organization = useOrganization();
    const [prompt, setPrompt] = useState(remix?.prompt ?? '');
    const [template, setTemplate] = useState<string | null>(null);
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
                        <div
                            className={cn(
                                'space-y-4',
                                panelClass,
                                tones.template.panel,
                            )}
                        >
                            <WayToStart
                                tone="template"
                                icon={LayoutTemplate}
                                title="Or start from a template"
                                description="A ready-made starting point. Pick one, change anything, then send it."
                            />
                            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                {templates.map((option) => {
                                    const Icon =
                                        templateIcons[option.value] ??
                                        LayoutTemplate;

                                    return (
                                        <TemplateCard
                                            tone="template"
                                            key={option.value}
                                            label={option.label}
                                            description={option.description}
                                            icon={
                                                <Icon
                                                    className={cn(
                                                        'mt-0.5 size-4 shrink-0',
                                                        tones.template.icon,
                                                    )}
                                                />
                                            }
                                            pressed={template === option.value}
                                            onClick={() => pickTemplate(option)}
                                        />
                                    );
                                })}
                            </div>
                        </div>

                        <div
                            className={cn(
                                'space-y-4',
                                panelClass,
                                tones.app.panel,
                            )}
                        >
                            <WayToStart
                                tone="app"
                                icon={Package}
                                title="Or install a free app"
                                description="Free open-source apps, already built. Pick one and we set it up for you, ready to use."
                            />
                            {!compose && (
                                <p className="text-xs text-muted-foreground">
                                    These run with Docker. An admin can turn on
                                    Docker inside sandboxes in Settings →
                                    Sandboxes.
                                </p>
                            )}
                            <Deferred
                                data="featured"
                                fallback={
                                    <Skeleton className="h-56 rounded-xl sm:h-72" />
                                }
                            >
                                <AppCoverflow
                                    apps={featured ?? []}
                                    onOpen={(app) => view(app)}
                                />
                            </Deferred>
                            <Deferred
                                data="apps"
                                fallback={
                                    <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                        {Array.from({ length: 9 }, (_, key) => (
                                            <Skeleton
                                                key={key}
                                                className="h-[4.25rem] rounded-xl"
                                            />
                                        ))}
                                    </div>
                                }
                            >
                                <AppGallery
                                    apps={apps ?? []}
                                    onPick={(app) => view(app)}
                                    renderApp={(app) => (
                                        <TemplateCard
                                            tone="app"
                                            key={app.value}
                                            label={app.label}
                                            description={app.description}
                                            icon={
                                                <TemplateLogo
                                                    template={app}
                                                    className="size-6"
                                                />
                                            }
                                            pressed={template === app.value}
                                            dimmed={!compose}
                                            onClick={() => view(app)}
                                        />
                                    )}
                                />
                            </Deferred>
                        </div>
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

/** Each way to start has its own colour, so the three read apart at a glance. */
const tones = {
    scratch: {
        tile: 'bg-violet-500/15 text-violet-600 dark:text-violet-400',
        icon: 'text-violet-600 dark:text-violet-400',
        card: 'hover:border-violet-500/50 hover:bg-violet-500/5',
        pressed: 'border-violet-500 bg-violet-500/10',
        panel: 'border-violet-500/25 bg-violet-500/[0.04] dark:border-violet-500/30 dark:bg-violet-500/[0.07]',
    },
    template: {
        tile: 'bg-sky-500/15 text-sky-600 dark:text-sky-400',
        icon: 'text-sky-600 dark:text-sky-400',
        card: 'hover:border-sky-500/50 hover:bg-sky-500/5',
        pressed: 'border-sky-500 bg-sky-500/10',
        panel: 'border-sky-500/25 bg-sky-500/[0.04] dark:border-sky-500/30 dark:bg-sky-500/[0.07]',
    },
    app: {
        tile: 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400',
        icon: 'text-emerald-600 dark:text-emerald-400',
        card: 'hover:border-emerald-500/50 hover:bg-emerald-500/5',
        pressed: 'border-emerald-500 bg-emerald-500/10',
        panel: 'border-emerald-500/25 bg-emerald-500/[0.04] dark:border-emerald-500/30 dark:bg-emerald-500/[0.07]',
    },
};

type Tone = keyof typeof tones;

const panelClass = 'rounded-2xl border p-4 sm:p-5';

/** A heading for one of the ways to start a project, in words a first-time user knows. */
function WayToStart({
    tone,
    icon: Icon,
    title,
    description,
    className,
}: {
    tone: Tone;
    icon: LucideIcon;
    title: string;
    description: string;
    className?: string;
}) {
    return (
        <div className={cn('flex items-start gap-3', className)}>
            <span
                className={cn(
                    'flex size-10 shrink-0 items-center justify-center rounded-xl',
                    tones[tone].tile,
                )}
            >
                <Icon className="size-5" />
            </span>
            <div className="space-y-0.5">
                <h2 className="text-lg font-semibold">{title}</h2>
                <p className="text-sm text-muted-foreground">{description}</p>
            </div>
        </div>
    );
}

function TemplateCard({
    tone,
    label,
    description,
    icon,
    pressed,
    dimmed = false,
    onClick,
}: {
    tone: Tone;
    label: string;
    description: string;
    icon: ReactNode;
    pressed: boolean;
    /** Can't be used here (it still opens, to read about it). */
    dimmed?: boolean;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={pressed}
            className={cn(
                'flex items-start gap-3 rounded-xl border border-input bg-background px-4 py-3 text-left transition-colors',
                dimmed && 'opacity-60',
                tones[tone].card,
                pressed && tones[tone].pressed,
            )}
        >
            {icon}
            <span className="min-w-0 space-y-0.5">
                <span className="block text-sm font-medium break-words">
                    {label}
                </span>
                <span className="line-clamp-2 text-xs break-words text-muted-foreground">
                    {description}
                </span>
            </span>
        </button>
    );
}

CreateProject.layout = {
    breadcrumbs: [{ title: 'New project', href: dashboard() }],
};
