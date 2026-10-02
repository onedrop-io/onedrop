import { router } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { useState } from 'react';
import { TemplateLogo } from '@/components/app-gallery';
import { destroy } from '@/routes/start';
import type { PendingStart } from '@/types';

/**
 * What they picked on the home page, beside the sign-up or log-in form (HOME-004), so a newcomer still sees why
 * they're here: the free app's card, or the template or prompt they wrote, and the steps ahead.
 */
export function PendingStartPanel({
    start,
    signingUp,
}: {
    start: PendingStart;
    /** Creating an account, rather than logging in. */
    signingUp: boolean;
}) {
    const { template, prompt } = start;
    const [coverFailed, setCoverFailed] = useState(false);
    const freeApp = template?.compose ?? false;
    const steps = [
        signingUp ? 'Create your free account' : 'Log in',
        'Connect your AI: a Claude or ChatGPT plan, or an API key',
        freeApp
            ? `We set up ${template!.label} for you, ready to use`
            : 'The AI builds it, and you see it running',
    ];

    return (
        <div className="w-full max-w-md space-y-6" data-test="pending-start">
            <p className="text-sm font-medium text-muted-foreground">
                {freeApp ? 'You’re about to set up' : 'You’re about to build'}
            </p>

            {template ? (
                <div className="overflow-hidden rounded-2xl border bg-card">
                    {template.cover && !coverFailed && (
                        <img
                            src={template.cover}
                            alt=""
                            referrerPolicy="no-referrer"
                            onError={() => setCoverFailed(true)}
                            className="aspect-[16/9] w-full border-b object-cover object-top"
                        />
                    )}
                    <div className="space-y-3 p-5">
                        <div className="flex items-center gap-3">
                            <TemplateLogo
                                template={template}
                                className="size-12 rounded-xl"
                            />
                            <div className="min-w-0">
                                <p
                                    className="text-lg font-semibold break-words"
                                    data-test="pending-start-name"
                                >
                                    {template.label}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {freeApp
                                        ? template.version
                                            ? `Free and open source · Version ${template.version}`
                                            : 'Free and open source'
                                        : 'Template'}
                                </p>
                            </div>
                        </div>
                        <p className="line-clamp-4 text-sm text-muted-foreground">
                            {template.description}
                        </p>
                    </div>
                </div>
            ) : (
                prompt && (
                    <blockquote
                        className="line-clamp-6 rounded-2xl border bg-card p-5 text-sm leading-relaxed whitespace-pre-line"
                        data-test="pending-start-prompt"
                    >
                        {prompt}
                    </blockquote>
                )
            )}

            <ol className="space-y-2.5">
                {steps.map((step, index) => (
                    <li key={step} className="flex gap-3 text-sm">
                        <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-emerald-500/15 text-xs font-medium text-emerald-700 dark:text-emerald-400">
                            {index + 1}
                        </span>
                        <span className={index === 0 ? 'font-medium' : ''}>
                            {step}
                        </span>
                    </li>
                ))}
            </ol>

            <button
                type="button"
                onClick={() => router.delete(destroy().url)}
                className="text-xs text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                data-test="pending-start-dismiss"
            >
                Not now
            </button>
        </div>
    );
}

/** On the AI onboarding, a line saying what connecting an AI is for (HOME-004). */
export function PendingStartNext({ start }: { start: PendingStart }) {
    return (
        <p
            className="flex items-center gap-3 rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm"
            data-test="pending-start-next"
        >
            {start.template && (
                <TemplateLogo template={start.template} className="size-8" />
            )}
            <span>
                <span className="font-medium">
                    Next:{' '}
                    {start.template?.compose
                        ? `we set up ${start.template.label} for you.`
                        : `the AI builds your ${start.template?.label ?? 'app'}.`}
                </span>{' '}
                <span className="text-muted-foreground">
                    Connect your AI first, so it has something to build with.
                </span>
            </span>
            <ArrowRight className="ml-auto size-4 shrink-0 text-muted-foreground" />
        </p>
    );
}
