import {
    Boxes,
    CalendarDays,
    CalendarHeart,
    Handshake,
    KanbanSquare,
    LayoutTemplate,
    LifeBuoy,
    Newspaper,
    Receipt,
    Sparkles,
    UserSearch,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import AgentModelPicker from '@/components/agent-model-picker';
import RepositoryPicker, {
    RepositoryToggle,
} from '@/components/repository-picker';
import type { Repository } from '@/components/repository-picker';
import { cn } from '@/lib/utils';
import type { AgentSelection, AppTemplate } from '@/types';
import { api } from '../lib/api';
import { openInBrowser } from '../lib/native';
import type { NewProjectOptions } from '../lib/types';
import Composer from './composer';
import TitleBar from './title-bar';

const templateIcons: Record<string, LucideIcon> = {
    crm: Handshake,
    'project-tracker': KanbanSquare,
    'content-calendar': Newspaper,
    inventory: Boxes,
    hiring: UserSearch,
    events: CalendarHeart,
    'help-desk': LifeBuoy,
    'time-off': CalendarDays,
    expenses: Receipt,
};

/**
 * Start a project (DESK-004), as on the web's new-project page (resources/js/pages/projects/create.tsx): from a
 * description, a template, or a repository (PRJ-009), with the agent and model to build it.
 */
export default function NewProject({
    userName,
    onCreated,
}: {
    userName: string;
    onCreated: (id: number) => void;
}) {
    const [options, setOptions] = useState<NewProjectOptions | null>(null);
    const [selection, setSelection] = useState<AgentSelection | null>(null);
    const [prompt, setPrompt] = useState('');
    const [template, setTemplate] = useState<string | null>(null);
    const [importing, setImporting] = useState(false);
    const [repository, setRepository] = useState('');
    const firstName = userName.split(' ')[0];

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

        // Back from connecting GitHub (or an AI) in the browser: pick it up.
        const onFocus = () => void load(false);

        window.addEventListener('focus', onFocus);

        return () => window.removeEventListener('focus', onFocus);
    }, [load]);

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

    const send = async (text: string, files: File[]) => {
        const body = new FormData();

        body.append('prompt', text);

        if (importing) {
            body.append('repository', repository);
        } else if (template) {
            body.append('template', template);
        }

        if (selection) {
            body.append('agent_harness', selection.harness);
            body.append('agent_provider', selection.provider);
            body.append('agent_model', selection.model);

            if (selection.variant) {
                body.append('agent_variant', selection.variant);
            }
        }

        files.forEach((file) => body.append('attachments[]', file));

        const { id } = await api<{ id: number }>('projects', body);

        onCreated(id);
    };

    return (
        <div className="flex min-h-0 flex-1 flex-col">
            <TitleBar />
            <div className="flex flex-1 flex-col justify-center overflow-y-auto px-6 pb-10">
                <div className="mx-auto w-full max-w-3xl space-y-8">
                    <h1 className="text-3xl font-medium tracking-tight md:text-4xl">
                        {firstName}, what are we working on today?
                    </h1>

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
                                        api<{ repositories: Repository[] }>(
                                            'github/repositories',
                                        ).then(
                                            ({ repositories }) => repositories,
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

                    {options && (
                        <div className={cn('space-y-3', importing && 'hidden')}>
                            <p className="text-sm text-muted-foreground">
                                Or start from a template
                            </p>
                            <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                {options.templates.map((option) => {
                                    const Icon =
                                        templateIcons[option.value] ??
                                        LayoutTemplate;

                                    return (
                                        <button
                                            key={option.value}
                                            type="button"
                                            onClick={() => pickTemplate(option)}
                                            aria-pressed={
                                                template === option.value
                                            }
                                            className={cn(
                                                'flex items-start gap-3 rounded-xl border border-input px-4 py-3 text-left transition-colors hover:bg-muted',
                                                template === option.value &&
                                                    'border-primary bg-muted',
                                            )}
                                        >
                                            <Icon className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                                            <span className="space-y-0.5">
                                                <span className="block text-sm font-medium">
                                                    {option.label}
                                                </span>
                                                <span className="block text-xs text-muted-foreground">
                                                    {option.description}
                                                </span>
                                            </span>
                                        </button>
                                    );
                                })}
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}
