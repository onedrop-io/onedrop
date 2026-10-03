import { PenLine, Sparkles } from 'lucide-react';
import {
    useCallback,
    useDeferredValue,
    useEffect,
    useMemo,
    useState,
} from 'react';
import AgentModelPicker from '@/components/agent-model-picker';
import AlreadyBuilt, { builtIn } from '@/components/already-built';
import { AppDetails } from '@/components/app-gallery';
import RepositoryPicker, {
    RepositoryToggle,
} from '@/components/repository-picker';
import type { Repository } from '@/components/repository-picker';
import {
    FreeAppsPanel,
    TemplatesPanel,
    WayToStart,
    panelClass,
    tones,
} from '@/components/ways-to-start';
import { indexTemplates, matchTemplates } from '@/lib/template-match';
import { cn } from '@/lib/utils';
import type {
    AgentSelection,
    AppTemplate,
    CatalogTemplate,
    FeaturedApp,
} from '@/types';
import { api, ApiError } from '../lib/api';
import { openInBrowser } from '../lib/native';
import type { NewProjectOptions } from '../lib/types';
import Composer from './composer';
import TitleBar from './title-bar';

/**
 * Start a project (DESK-004), as on the web's new-project page (resources/js/pages/projects/create.tsx), with its
 * own components: from scratch, from a template, from a free app (PRJ-012), or from a repository (PRJ-009).
 */
export default function NewProject({
    userName,
    onCreated,
}: {
    userName: string;
    onCreated: (id: number) => void;
}) {
    const [options, setOptions] = useState<NewProjectOptions | null>(null);
    // Every free app and the featured ones, fetched on their own since a registry may be slow (undefined until then).
    const [apps, setApps] = useState<CatalogTemplate[] | undefined>(undefined);
    const [featured, setFeatured] = useState<FeaturedApp[] | undefined>(
        undefined,
    );
    const [selection, setSelection] = useState<AgentSelection | null>(null);
    const [prompt, setPrompt] = useState('');
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
    const [importing, setImporting] = useState(false);
    const [repository, setRepository] = useState('');
    const firstName = userName.split(' ')[0];
    const templates = options?.templates ?? [];
    const compose = options?.compose ?? false;

    const load = useCallback(
        (first: boolean) =>
            api<NewProjectOptions>('projects/new')
                .then((loaded) => {
                    setOptions(loaded);

                    // The default agent only until the user picks one.
                    if (first) {
                        setSelection(loaded.agent);
                    }
                })
                .catch(() => {}),
        [],
    );

    useEffect(() => {
        void load(true);
        api<{ apps: CatalogTemplate[] }>('projects/new/apps')
            .then(({ apps: loaded }) => setApps(loaded))
            .catch(() => setApps([]));
        api<{ featured: FeaturedApp[] }>('projects/new/featured')
            .then(({ featured: loaded }) => setFeatured(loaded))
            .catch(() => setFeatured([]));

        // Back from connecting GitHub (or an AI) in the browser: pick it up.
        const onFocus = () => void load(false);

        window.addEventListener('focus', onFocus);

        return () => window.removeEventListener('focus', onFocus);
    }, [load]);

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

    const agentFields = (body: FormData) => {
        if (selection) {
            body.append('agent_harness', selection.harness);
            body.append('agent_provider', selection.provider);
            body.append('agent_model', selection.model);

            if (selection.variant) {
                body.append('agent_variant', selection.variant);
            }
        }
    };

    const create = async (body: FormData) => {
        agentFields(body);

        const { id } = await api<{ id: number }>('projects', body);

        onCreated(id);
    };

    const send = (text: string, files: File[]) => {
        const body = new FormData();

        body.append('prompt', text);

        if (importing) {
            body.append('repository', repository);
        } else if (template) {
            body.append('template', template);
        }

        files.forEach((file) => body.append('attachments[]', file));

        return create(body);
    };

    const view = (app: CatalogTemplate, keep = '') => {
        setStartError(null);
        setViewing({ app, keep });
    };

    /**
     * "Use" in a free app's details creates the project right away (PRJ-012); what they typed, if it was suggested,
     * goes after the app's description.
     */
    const startFrom = (app: CatalogTemplate, keep = '') => {
        const body = new FormData();

        body.append(
            'prompt',
            keep.trim() === '' ? app.prompt : `${app.prompt}\n\n${keep.trim()}`,
        );
        body.append('template', app.value);
        setStarting(true);
        create(body)
            .catch((error: Error) =>
                setStartError(
                    error instanceof ApiError
                        ? (Object.values(error.errors)[0] ?? error.message)
                        : 'Couldn’t start the project. Try again.',
                ),
            )
            .finally(() => setStarting(false));
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
        <div className="flex min-h-0 flex-1 flex-col">
            <TitleBar />
            <div className="flex flex-1 flex-col overflow-y-auto px-6 pt-4 pb-10">
                <div className="mx-auto my-auto w-full max-w-3xl space-y-8">
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

                        <Composer
                            onSend={(text, files) => send(text, files)}
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
                                importing &&
                                options && (
                                    <RepositoryPicker
                                        value={repository}
                                        onChange={setRepository}
                                        onClose={() => setImporting(false)}
                                        github={options.github}
                                        loadRepositories={() =>
                                            api<{
                                                repositories: Repository[];
                                            }>('github/repositories').then(
                                                ({ repositories }) =>
                                                    repositories,
                                            )
                                        }
                                        onConnectGitHub={(url) =>
                                            void openInBrowser(url)
                                        }
                                    />
                                )
                            }
                            size="large"
                            footer={
                                <>
                                    {selection ? (
                                        <AgentModelPicker
                                            selection={selection}
                                            onChange={setSelection}
                                        />
                                    ) : (
                                        options?.defaultAi && (
                                            <span className="inline-flex items-center gap-1">
                                                <Sparkles className="size-3" />
                                                {options.defaultAi}
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

                    {options && (
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
                    )}

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
        </div>
    );
}
