import { Deferred, Head, router, usePage } from '@inertiajs/react';
import { ArrowRight, LayoutTemplate, Shuffle, Sparkles } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import ProjectController from '@/actions/App/Http/Controllers/ProjectController';
import AgentModelPicker from '@/components/agent-model-picker';
import PromptComposer from '@/components/prompt-composer';
import RepositoryPicker, {
    RepositoryToggle,
} from '@/components/repository-picker';
import type { ImportGitHub } from '@/components/repository-picker';
import TemplateBrowser, {
    TemplateLogo,
    templateIcons,
} from '@/components/template-browser';
import { Skeleton } from '@/components/ui/skeleton';
import { useOrganization } from '@/hooks/use-organization';
import { cn } from '@/lib/utils';
import type { AgentSelection, AppTemplate, CatalogTemplate } from '@/types';
import { dashboard } from '@/routes';

export default function CreateProject({
    defaultAi,
    agent,
    templates,
    popular,
    catalog,
    compose,
    remix,
    github,
}: {
    defaultAi: string | null;
    agent: AgentSelection | null;
    templates: AppTemplate[];
    /** Popular open-source apps from the registries (PRJ-012); deferred, since a registry may be slow. */
    popular?: CatalogTemplate[];
    /** Every template, built-in and from registries; loaded when "Browse all templates" opens. */
    catalog?: CatalogTemplate[];
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
    const [browsing, setBrowsing] = useState(false);
    // A template picked in the browser that isn't one of the cards, named beside "Browse all templates".
    const [browsed, setBrowsed] = useState<CatalogTemplate | null>(null);
    // Back from connecting GitHub here: open the import again.
    const [importing, setImporting] = useState(github.returned !== null);
    const [repository, setRepository] = useState('');
    const firstName = auth.user.name.split(' ')[0];

    const browse = () => {
        setBrowsing(true);

        if (catalog === undefined) {
            router.reload({ only: ['catalog'] });
        }
    };

    const pickTemplate = (picked: AppTemplate) => {
        setTemplate(picked.value);
        setBrowsed(
            [...templates, ...(popular ?? [])].some(
                (option) => option.value === picked.value,
            )
                ? null
                : (picked as CatalogTemplate),
        );
        setPrompt(picked.prompt);

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
                    <h1 className="text-3xl font-medium tracking-tight md:text-4xl">
                        {firstName}, what are we working on today?
                    </h1>

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
                                setBrowsed(null);
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
                            ...(selection && {
                                agent_harness: selection.harness,
                                agent_provider: selection.provider,
                                agent_model: selection.model,
                                agent_variant: selection.variant,
                            }),
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

                    <div className={cn('space-y-3', importing && 'hidden')}>
                        <div className="flex items-baseline justify-between gap-4">
                            <p className="text-sm text-muted-foreground">
                                Or start from a template
                            </p>
                            <button
                                type="button"
                                onClick={browse}
                                className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                                data-test="browse-templates"
                            >
                                {browsed
                                    ? `Starting from ${browsed.label}. Browse all templates`
                                    : 'Browse all templates'}
                                <ArrowRight className="size-3.5" />
                            </button>
                        </div>
                        <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                            {templates.map((option) => {
                                const Icon =
                                    templateIcons[option.value] ??
                                    LayoutTemplate;

                                return (
                                    <TemplateCard
                                        key={option.value}
                                        label={option.label}
                                        description={option.description}
                                        icon={
                                            <Icon className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                                        }
                                        pressed={template === option.value}
                                        onClick={() => pickTemplate(option)}
                                    />
                                );
                            })}
                        </div>

                        <p className="pt-3 text-sm text-muted-foreground">
                            Popular open-source apps
                        </p>
                        {!compose && (
                            <p className="text-xs text-muted-foreground">
                                These run with Docker. An admin can turn on
                                Docker inside sandboxes in Settings → Sandboxes.
                            </p>
                        )}
                        <Deferred
                            data="popular"
                            fallback={
                                <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                    {Array.from({ length: 6 }, (_, index) => (
                                        <Skeleton
                                            key={index}
                                            className="h-[4.25rem] rounded-xl"
                                        />
                                    ))}
                                </div>
                            }
                        >
                            <div
                                className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3"
                                data-test="popular-templates"
                            >
                                {(popular ?? []).map((option) => (
                                    <TemplateCard
                                        key={option.value}
                                        label={option.label}
                                        description={option.description}
                                        icon={
                                            <TemplateLogo
                                                template={option}
                                                className="size-6"
                                            />
                                        }
                                        pressed={template === option.value}
                                        disabled={!compose}
                                        onClick={() => pickTemplate(option)}
                                    />
                                ))}
                            </div>
                        </Deferred>
                    </div>

                    <TemplateBrowser
                        open={browsing}
                        onOpenChange={setBrowsing}
                        templates={catalog}
                        compose={compose}
                        onPick={pickTemplate}
                    />
                </div>
            </div>
        </>
    );
}

function TemplateCard({
    label,
    description,
    icon,
    pressed,
    disabled = false,
    onClick,
}: {
    label: string;
    description: string;
    icon: ReactNode;
    pressed: boolean;
    disabled?: boolean;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            disabled={disabled}
            aria-pressed={pressed}
            className={cn(
                'flex items-start gap-3 rounded-xl border border-input px-4 py-3 text-left transition-colors hover:bg-muted disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:bg-transparent',
                pressed && 'border-primary bg-muted',
            )}
        >
            {icon}
            <span className="min-w-0 space-y-0.5">
                <span className="block text-sm font-medium">{label}</span>
                <span className="line-clamp-2 text-xs text-muted-foreground">
                    {description}
                </span>
            </span>
        </button>
    );
}

CreateProject.layout = {
    breadcrumbs: [{ title: 'New project', href: dashboard() }],
};
