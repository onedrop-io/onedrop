import { router, usePage } from '@inertiajs/react';
import { Check, Copy, ExternalLink, Globe, Lock, Rocket } from 'lucide-react';
import { useState } from 'react';
import ProjectPublicationController from '@/actions/App/Http/Controllers/ProjectPublicationController';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useClipboard } from '@/hooks/use-clipboard';
import { cn } from '@/lib/utils';
import type { Publication, PublishTarget } from '@/types';

type Visibility = 'private' | 'public';

/** Who each means depends on the target (e.g. the tailnet, or people signed in to OneDrop). */
const VISIBILITY: Record<Visibility, { label: string; icon: typeof Globe }> = {
    private: { label: 'Private', icon: Lock },
    public: { label: 'Public', icon: Globe },
};

/** What publishing is waiting on in Tailscale, and the button that goes there. */
const WAITING: Record<
    NonNullable<Publication['waiting_for']>,
    { message: string; action: string }
> = {
    login: {
        message:
            'Approve this project in Tailscale to give it a URL. You only need to do this once per project.',
        action: 'Approve in Tailscale',
    },
    funnel: {
        message:
            'Public links need Tailscale Funnel, which is off for this tailnet. Turn it on (add the "funnel" node attribute in your access controls), or publish privately.',
        action: 'Turn on Funnel',
    },
    https: {
        message:
            'Tailscale links need HTTPS certificates, which are off for this tailnet. Turn them on in the DNS settings.',
        action: 'Turn on HTTPS',
    },
};

function timeAgo(iso: string): string {
    const seconds = Math.round((new Date(iso).getTime() - Date.now()) / 1000);
    const format = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });

    for (const [unit, size] of [
        ['day', 86400],
        ['hour', 3600],
        ['minute', 60],
    ] as const) {
        if (Math.abs(seconds) >= size) {
            return format.format(Math.round(seconds / size), unit);
        }
    }

    return 'just now';
}

export default function PublishMenu({
    projectId,
    publication,
}: {
    projectId: number;
    publication: Publication;
}) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [visibility, setVisibility] = useState<Visibility>(
        publication.visibility ?? 'private',
    );
    const [target, setTarget] = useState<PublishTarget>(
        publication.target ??
            publication.targets.find((option) => !option.unavailable)?.target ??
            'tailscale',
    );
    const chosen =
        publication.targets.find((option) => option.target === target) ??
        publication.targets[0];
    const publishedTo = publication.targets.find(
        (option) => option.target === publication.target,
    );
    const [copiedText, copy] = useClipboard();
    const published = publication.status === 'live';
    const publishing = publication.status === 'publishing';
    const inReview = publication.status === 'review';
    const everPublished = publication.published_at !== null;
    const error = errors.publish ?? publication.error;

    const publish = () =>
        router.post(
            ProjectPublicationController.store.url(projectId),
            { visibility, target },
            { preserveScroll: true },
        );

    const unpublish = () =>
        router.delete(ProjectPublicationController.destroy.url(projectId), {
            preserveScroll: true,
        });

    return (
        <DropdownMenu modal={false}>
            <DropdownMenuTrigger asChild>
                <Button size="sm" data-test="publish-button">
                    <Rocket className="size-4" />
                    {everPublished ? 'Republish' : 'Publish'}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="end"
                className="w-96 max-w-[calc(100vw-1rem)] space-y-4 p-4"
                data-test="publish-panel"
                // The preview iframe can grab focus while loading; only close on a real click outside or Escape.
                onFocusOutside={(event) => event.preventDefault()}
            >
                <h2 className="font-medium">
                    {everPublished ? 'Republish' : 'Publish'}
                </h2>

                {publication.unavailable ? (
                    <p
                        className="text-sm text-muted-foreground"
                        data-test="publish-unavailable"
                    >
                        {publication.unavailable}
                    </p>
                ) : (
                    <>
                        <dl className="grid grid-cols-[6rem_1fr] gap-y-2 text-sm">
                            <dt className="text-muted-foreground">Status</dt>
                            <dd
                                className="flex items-center gap-2"
                                data-test="publish-status"
                            >
                                <span
                                    className={cn(
                                        'size-2 rounded-full',
                                        published && 'bg-green-500',
                                        publishing &&
                                            'animate-pulse bg-amber-500',
                                        publication.status === 'failed' &&
                                            'bg-red-500',
                                        inReview && 'bg-amber-500',
                                        !publication.status &&
                                            'bg-muted-foreground/40',
                                    )}
                                />
                                {published
                                    ? `${publication.published_by ?? 'Someone'} published ${timeAgo(publication.published_at!)}`
                                    : publishing
                                      ? 'Publishing…'
                                      : publication.status === 'failed'
                                        ? 'Failed'
                                        : inReview
                                          ? 'Waiting for a review'
                                          : 'Not published'}
                            </dd>
                            {published &&
                                publishedTo &&
                                publication.targets.length > 1 && (
                                    <>
                                        <dt className="text-muted-foreground">
                                            Where
                                        </dt>
                                        <dd data-test="published-target">
                                            {publishedTo.label}
                                        </dd>
                                    </>
                                )}
                            {published && publication.visibility && (
                                <>
                                    <dt className="text-muted-foreground">
                                        Visibility
                                    </dt>
                                    <dd>
                                        {
                                            VISIBILITY[publication.visibility]
                                                .label
                                        }
                                    </dd>
                                </>
                            )}
                            {published && publication.url && (
                                <>
                                    <dt className="text-muted-foreground">
                                        URL
                                    </dt>
                                    <dd className="flex min-w-0 items-center gap-1">
                                        <a
                                            href={publication.url}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="truncate underline-offset-4 hover:underline"
                                            data-test="published-url"
                                        >
                                            {publication.url.replace(
                                                'https://',
                                                '',
                                            )}
                                        </a>
                                        <button
                                            type="button"
                                            onClick={() =>
                                                void copy(publication.url!)
                                            }
                                            aria-label="Copy URL"
                                            className="shrink-0 rounded p-1 hover:bg-muted"
                                        >
                                            {copiedText === publication.url ? (
                                                <Check className="size-3.5" />
                                            ) : (
                                                <Copy className="size-3.5" />
                                            )}
                                        </button>
                                        <a
                                            href={publication.url}
                                            target="_blank"
                                            rel="noreferrer"
                                            aria-label="Open published app"
                                            className="shrink-0 rounded p-1 hover:bg-muted"
                                        >
                                            <ExternalLink className="size-3.5" />
                                        </a>
                                    </dd>
                                </>
                            )}
                        </dl>

                        {inReview && (
                            <p
                                className="rounded-md bg-amber-500/10 p-3 text-sm text-amber-700 dark:text-amber-400"
                                data-test="publish-review"
                            >
                                OneDrop checks apps before they go public, and
                                this one needs a person to look at it first. It
                                goes live as soon as it's approved; you can keep
                                working meanwhile.
                            </p>
                        )}

                        {publication.targets.length > 1 && (
                            <fieldset>
                                <legend className="mb-2 text-sm text-muted-foreground">
                                    Publish to
                                </legend>
                                <div className="grid grid-cols-2 gap-2">
                                    {publication.targets.map((option) => (
                                        <label
                                            key={option.target}
                                            className={cn(
                                                'cursor-pointer rounded-lg border p-2 text-center text-sm font-medium',
                                                target === option.target
                                                    ? 'border-primary'
                                                    : 'border-input hover:bg-muted/50',
                                            )}
                                        >
                                            <input
                                                type="radio"
                                                name="target"
                                                value={option.target}
                                                checked={
                                                    target === option.target
                                                }
                                                onChange={() =>
                                                    setTarget(option.target)
                                                }
                                                className="sr-only"
                                                data-test={`target-${option.target}`}
                                            />
                                            {option.label}
                                        </label>
                                    ))}
                                </div>
                            </fieldset>
                        )}

                        {chosen?.unavailable && (
                            <p
                                className="text-sm text-muted-foreground"
                                data-test="publish-target-unavailable"
                            >
                                {chosen.unavailable}
                            </p>
                        )}

                        <fieldset className="space-y-2">
                            <legend className="mb-2 text-sm text-muted-foreground">
                                Who can open it
                            </legend>
                            {(Object.keys(VISIBILITY) as Visibility[]).map(
                                (key) => {
                                    const option = VISIBILITY[key];

                                    return (
                                        <label
                                            key={key}
                                            className={cn(
                                                'flex cursor-pointer items-start gap-3 rounded-lg border p-3 text-sm',
                                                visibility === key
                                                    ? 'border-primary'
                                                    : 'border-input hover:bg-muted/50',
                                            )}
                                        >
                                            <input
                                                type="radio"
                                                name="visibility"
                                                value={key}
                                                checked={visibility === key}
                                                onChange={() =>
                                                    setVisibility(key)
                                                }
                                                className="sr-only"
                                                data-test={`visibility-${key}`}
                                            />
                                            <option.icon className="mt-0.5 size-4 text-muted-foreground" />
                                            <span>
                                                <span className="block font-medium">
                                                    {option.label}
                                                </span>
                                                <span className="text-muted-foreground">
                                                    {chosen?.[key]}
                                                </span>
                                            </span>
                                        </label>
                                    );
                                },
                            )}
                        </fieldset>

                        {publishing && publication.login_url && (
                            <div
                                className="space-y-2 rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-sm"
                                data-test="publish-login"
                            >
                                <p>
                                    {
                                        WAITING[
                                            publication.waiting_for ?? 'login'
                                        ].message
                                    }
                                </p>
                                <Button asChild size="sm" variant="outline">
                                    <a
                                        href={publication.login_url}
                                        target="_blank"
                                        rel="noreferrer"
                                        data-test="publish-login-link"
                                    >
                                        {
                                            WAITING[
                                                publication.waiting_for ??
                                                    'login'
                                            ].action
                                        }
                                        <ExternalLink className="size-3.5" />
                                    </a>
                                </Button>
                                <p className="text-muted-foreground">
                                    This panel updates by itself once you're
                                    done.
                                </p>
                            </div>
                        )}

                        {error && (
                            <p
                                className="text-sm text-red-600"
                                data-test="publish-error"
                            >
                                {error}
                            </p>
                        )}

                        <div className="flex gap-2">
                            {(published || publication.status === 'failed') && (
                                <Button
                                    variant="outline"
                                    className="flex-1"
                                    onClick={unpublish}
                                    data-test="unpublish"
                                >
                                    Unpublish
                                </Button>
                            )}
                            <Button
                                className="flex-1"
                                onClick={publish}
                                disabled={publishing || !!chosen?.unavailable}
                                data-test="publish-submit"
                            >
                                {publishing
                                    ? 'Publishing…'
                                    : everPublished
                                      ? 'Republish'
                                      : 'Publish'}
                            </Button>
                        </div>
                    </>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
