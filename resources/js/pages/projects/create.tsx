import { Head, usePage } from '@inertiajs/react';
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
    Shuffle,
    Sparkles,
    UserSearch,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import ProjectController from '@/actions/App/Http/Controllers/ProjectController';
import AgentModelPicker from '@/components/agent-model-picker';
import PromptComposer from '@/components/prompt-composer';
import RepositoryPicker, {
    RepositoryToggle,
} from '@/components/repository-picker';
import type { ImportGitHub } from '@/components/repository-picker';
import { useOrganization } from '@/hooks/use-organization';
import { cn } from '@/lib/utils';
import type { AgentSelection, AppTemplate } from '@/types';
import { dashboard } from '@/routes';

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

export default function CreateProject({
    defaultAi,
    agent,
    templates,
    remix,
    github,
}: {
    defaultAi: string | null;
    agent: AgentSelection | null;
    templates: AppTemplate[];
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
    // Back from connecting GitHub here: open the import again.
    const [importing, setImporting] = useState(github.returned !== null);
    const [repository, setRepository] = useState('');
    const firstName = auth.user.name.split(' ')[0];

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
                        <p className="text-sm text-muted-foreground">
                            Or start from a template
                        </p>
                        <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                            {templates.map((option) => {
                                const Icon =
                                    templateIcons[option.value] ??
                                    LayoutTemplate;

                                return (
                                    <button
                                        key={option.value}
                                        type="button"
                                        onClick={() => pickTemplate(option)}
                                        aria-pressed={template === option.value}
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
                </div>
            </div>
        </>
    );
}

CreateProject.layout = {
    breadcrumbs: [{ title: 'New project', href: dashboard() }],
};
