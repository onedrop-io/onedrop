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
import type { Publication } from '@/types';

type Visibility = 'private' | 'public';

const VISIBILITY: Record<
    Visibility,
    { label: string; description: string; icon: typeof Globe }
> = {
    private: {
        label: 'Private',
        description: "People on your team's tailnet",
        icon: Lock,
    },
    public: {
        label: 'Public',
        description: 'Anyone on the internet with the URL',
        icon: Globe,
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
    const [copiedText, copy] = useClipboard();
    const published = publication.status === 'live';
    const publishing = publication.status === 'publishing';
    const everPublished = publication.published_at !== null;
    const error = errors.publish ?? publication.error;

    const publish = () =>
        router.post(
            ProjectPublicationController.store.url(projectId),
            { visibility },
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
                className="w-96 space-y-4 p-4"
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
                                        : 'Not published'}
                            </dd>
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
                                                    {option.description}
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
                                    Approve this project in Tailscale to give it
                                    a URL. You only need to do this once per
                                    project.
                                </p>
                                <Button asChild size="sm" variant="outline">
                                    <a
                                        href={publication.login_url}
                                        target="_blank"
                                        rel="noreferrer"
                                        data-test="publish-login-link"
                                    >
                                        Approve in Tailscale
                                        <ExternalLink className="size-3.5" />
                                    </a>
                                </Button>
                                <p className="text-muted-foreground">
                                    This panel updates by itself once you've
                                    approved it.
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
                                disabled={publishing}
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
