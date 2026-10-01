import { router, usePage } from '@inertiajs/react';
import {
    Check,
    Copy,
    ExternalLink,
    Eye,
    RefreshCw,
    Share2,
    Shuffle,
} from 'lucide-react';
import { useState } from 'react';
import ProjectShareController from '@/actions/App/Http/Controllers/ProjectShareController';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { useClipboard } from '@/hooks/use-clipboard';
import type { Sharing } from '@/types';

/**
 * Share a project on a public page with its prompt, a screenshot and a preview card (SHARE-001).
 */
export default function ShareMenu({
    projectId,
    projectName,
    sharing,
}: {
    projectId: number;
    projectName: string;
    sharing: Sharing;
}) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [prompt, setPrompt] = useState(sharing.prompt);
    const [pagePath, setPagePath] = useState(sharing.page_path);
    const [saving, setSaving] = useState(false);
    const [copiedText, copy] = useClipboard();
    const capturing = sharing.card_status === 'capturing';
    const changed =
        prompt.trim() !== sharing.prompt || pagePath !== sharing.page_path;

    const save = () =>
        router.post(
            ProjectShareController.store.url(projectId),
            { prompt, page_path: pagePath },
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
            },
        );

    const refresh = () =>
        router.post(
            ProjectShareController.refresh.url(projectId),
            {},
            { preserveScroll: true },
        );

    const stop = () =>
        router.delete(ProjectShareController.destroy.url(projectId), {
            preserveScroll: true,
        });

    const postText = `I built ${projectName} with one prompt on OneDrop`;

    return (
        <DropdownMenu
            modal={false}
            onOpenChange={(open) => {
                // Start from what's saved each time the panel opens.
                if (open) {
                    setPrompt(sharing.prompt);
                    setPagePath(sharing.page_path);
                }
            }}
        >
            <DropdownMenuTrigger asChild>
                <Button size="sm" variant="outline" data-test="share-button">
                    <Share2 className="size-4" />
                    Share
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="end"
                className="max-h-[calc(100svh-6rem)] w-[26rem] space-y-4 overflow-y-auto p-4"
                data-test="share-panel"
                // The preview iframe can grab focus while loading; only close on a real click outside or Escape.
                onFocusOutside={(event) => event.preventDefault()}
                // Let the prompt box keep its own keys (the menu would jump to items on typing).
                onKeyDown={(event) => event.stopPropagation()}
            >
                <div className="space-y-1">
                    <h2 className="font-medium">Show it off</h2>
                    <p className="text-sm text-muted-foreground">
                        {sharing.shared
                            ? 'Anyone with the link can see this page and remix your prompt.'
                            : 'Get a public page with your prompt and a screenshot of the app, plus a preview card for social posts. Only the prompt below is shown, never the chat or the code.'}
                    </p>
                </div>

                {sharing.review && (
                    <p
                        className="rounded-md bg-amber-500/10 p-3 text-sm text-amber-700 dark:text-amber-400"
                        data-test="share-review"
                    >
                        {sharing.review === 'held'
                            ? "OneDrop checks apps before they go public, and this one needs a person to look at it first. The page stays hidden until it's approved."
                            : "OneDrop took this app down after a review, so it can't be shared. Contact support if you think that's a mistake."}
                    </p>
                )}

                {sharing.shared && (
                    <div className="space-y-3">
                        <div className="relative aspect-[1200/630] overflow-hidden rounded-lg border bg-muted">
                            {sharing.card_url && !capturing ? (
                                <img
                                    src={sharing.card_url}
                                    alt="Preview card"
                                    className="size-full object-cover"
                                    data-test="share-card"
                                />
                            ) : capturing ? (
                                <div
                                    className="flex size-full flex-col items-center justify-center gap-2 text-sm text-muted-foreground"
                                    data-test="share-card-capturing"
                                >
                                    <Skeleton className="absolute inset-0 animate-pulse rounded-none" />
                                    <span className="relative">
                                        Making the card…
                                    </span>
                                </div>
                            ) : (
                                <div className="flex size-full items-center justify-center p-4 text-center text-sm text-muted-foreground">
                                    No card yet. Link previews use OneDrop's own
                                    card until there is one.
                                </div>
                            )}
                        </div>

                        {sharing.card_status === 'failed' &&
                            sharing.card_error && (
                                <p
                                    className="text-sm text-red-600"
                                    data-test="share-card-error"
                                >
                                    {sharing.card_error}
                                </p>
                            )}

                        <div className="flex min-w-0 items-center gap-1 rounded-md border px-2 py-1.5 text-sm">
                            <a
                                href={sharing.url!}
                                target="_blank"
                                rel="noreferrer"
                                className="min-w-0 flex-1 truncate underline-offset-4 hover:underline"
                                data-test="share-url"
                            >
                                {sharing.url!.replace(/^https?:\/\//, '')}
                            </a>
                            <button
                                type="button"
                                onClick={() => void copy(sharing.url!)}
                                aria-label="Copy link"
                                className="shrink-0 rounded p-1 hover:bg-muted"
                                data-test="share-copy"
                            >
                                {copiedText === sharing.url ? (
                                    <Check className="size-3.5" />
                                ) : (
                                    <Copy className="size-3.5" />
                                )}
                            </button>
                            <a
                                href={sharing.url!}
                                target="_blank"
                                rel="noreferrer"
                                aria-label="Open share page"
                                className="shrink-0 rounded p-1 hover:bg-muted"
                            >
                                <ExternalLink className="size-3.5" />
                            </a>
                        </div>

                        <div className="flex items-center gap-2 text-sm">
                            <Button asChild size="sm" variant="outline">
                                <a
                                    href={`https://x.com/intent/post?${new URLSearchParams({ text: postText, url: sharing.url! })}`}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    Post on X
                                </a>
                            </Button>
                            <Button asChild size="sm" variant="outline">
                                <a
                                    href={`https://www.linkedin.com/sharing/share-offsite/?${new URLSearchParams({ url: sharing.url! })}`}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    LinkedIn
                                </a>
                            </Button>
                            <span
                                className="ml-auto flex items-center gap-3 text-muted-foreground"
                                data-test="share-stats"
                            >
                                <span
                                    className="flex items-center gap-1"
                                    title="Views"
                                >
                                    <Eye className="size-3.5" />
                                    {sharing.views}
                                </span>
                                <span
                                    className="flex items-center gap-1"
                                    title="Remixes"
                                >
                                    <Shuffle className="size-3.5" />
                                    {sharing.remixes}
                                </span>
                            </span>
                        </div>
                    </div>
                )}

                <label className="block space-y-1.5">
                    <span className="text-sm text-muted-foreground">
                        Prompt to show
                    </span>
                    <textarea
                        value={prompt}
                        onChange={(event) => setPrompt(event.target.value)}
                        rows={4}
                        maxLength={2000}
                        className="w-full resize-y rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        data-test="share-prompt"
                    />
                </label>
                {errors.prompt && (
                    <p className="text-sm text-red-600">{errors.prompt}</p>
                )}

                <label className="block space-y-1.5">
                    <span className="text-sm text-muted-foreground">
                        Page to screenshot
                    </span>
                    <Input
                        value={pagePath}
                        onChange={(event) => setPagePath(event.target.value)}
                        placeholder="/"
                        data-test="share-page"
                    />
                </label>
                {errors.page_path && (
                    <p className="text-sm text-red-600">{errors.page_path}</p>
                )}

                <div className="flex gap-2">
                    {sharing.shared ? (
                        <>
                            <Button
                                variant="outline"
                                onClick={stop}
                                data-test="share-stop"
                            >
                                Stop sharing
                            </Button>
                            {changed ? (
                                <Button
                                    className="flex-1"
                                    onClick={save}
                                    disabled={saving || !prompt.trim()}
                                    data-test="share-save"
                                >
                                    Save and refresh card
                                </Button>
                            ) : (
                                <Button
                                    className="flex-1"
                                    variant="secondary"
                                    onClick={refresh}
                                    disabled={capturing}
                                    data-test="share-refresh"
                                >
                                    <RefreshCw
                                        className={
                                            capturing
                                                ? 'size-4 animate-spin'
                                                : 'size-4'
                                        }
                                    />
                                    Refresh card
                                </Button>
                            )}
                        </>
                    ) : (
                        <Button
                            className="flex-1"
                            onClick={save}
                            disabled={saving || !prompt.trim()}
                            data-test="share-submit"
                        >
                            <Share2 className="size-4" />
                            Share
                        </Button>
                    )}
                </div>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
