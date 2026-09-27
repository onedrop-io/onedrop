import { Head, usePage } from '@inertiajs/react';
import {
    CalendarDays,
    ClipboardCheck,
    Sparkles,
    Timer,
    Users,
} from 'lucide-react';
import { useState } from 'react';
import ProjectController from '@/actions/App/Http/Controllers/ProjectController';
import PromptComposer from '@/components/prompt-composer';
import { dashboard } from '@/routes';

const suggestions = [
    { icon: Timer, text: 'A time tracker like Toggl' },
    { icon: ClipboardCheck, text: 'An expense report form with approvals' },
    { icon: Users, text: 'A lightweight CRM for our sales team' },
    { icon: CalendarDays, text: 'A team vacation calendar' },
];

export default function CreateProject({
    defaultAi,
}: {
    defaultAi: string | null;
}) {
    const { auth } = usePage().props;
    const [prompt, setPrompt] = useState('');
    const firstName = auth.user.name.split(' ')[0];

    return (
        <>
            <Head title="New project" />

            <div className="flex flex-1 flex-col justify-center px-4 py-10">
                <div className="mx-auto w-full max-w-3xl space-y-8">
                    <h1 className="text-3xl font-medium tracking-tight md:text-4xl">
                        {firstName}, what are we working on today?
                    </h1>

                    <div className="space-y-3">
                        <p className="text-sm text-muted-foreground">
                            Suggested for you
                        </p>
                        <div className="flex flex-col items-start gap-2">
                            {suggestions.map(({ icon: Icon, text }) => (
                                <button
                                    key={text}
                                    type="button"
                                    onClick={() => setPrompt(text)}
                                    className="flex items-center gap-3 rounded-full border border-input px-4 py-2 text-sm transition-colors hover:bg-muted"
                                >
                                    <Icon className="size-4 text-muted-foreground" />
                                    {text}
                                </button>
                            ))}
                        </div>
                    </div>

                    <PromptComposer
                        action={ProjectController.store()}
                        field="prompt"
                        placeholder="Describe the app you want to build…"
                        value={prompt}
                        onValueChange={setPrompt}
                        autoFocus
                        size="large"
                        footer={
                            defaultAi && (
                                <span className="inline-flex items-center gap-1">
                                    <Sparkles className="size-3" />
                                    {defaultAi}
                                </span>
                            )
                        }
                    />
                </div>
            </div>
        </>
    );
}

CreateProject.layout = {
    breadcrumbs: [{ title: 'New project', href: dashboard() }],
};
