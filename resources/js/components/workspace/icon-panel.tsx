import { router, usePage } from '@inertiajs/react';
import { Sparkles, Upload } from 'lucide-react';
import { useRef, useState } from 'react';
import type { ChangeEvent } from 'react';
import ProjectIconController from '@/actions/App/Http/Controllers/ProjectIconController';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { jsonRequest } from '@/lib/json-request';
import { cn } from '@/lib/utils';

/**
 * The app's icon (its favicon, also shown on the project's sidebar tile): upload one, or have the AI draw one.
 */
export default function IconPanel({
    projectId,
    running,
}: {
    projectId: number;
    running: boolean;
}) {
    const { sidebarProjects } = usePage().props;
    const project = sidebarProjects
        ? [
              ...sidebarProjects.pinned,
              ...sidebarProjects.recent,
              ...sidebarProjects.archived,
          ].find((p) => p.id === projectId)
        : undefined;
    const [busy, setBusy] = useState(false);
    const [notice, setNotice] = useState<{
        text: string;
        error?: boolean;
    } | null>(null);
    const input = useRef<HTMLInputElement>(null);
    const iconUrl = project?.icon_url ?? null;
    const drawing = project?.drawing_icon ?? false;

    const refresh = () => router.reload({ only: ['sidebarProjects'] });

    const upload = (event: ChangeEvent<HTMLInputElement>) => {
        const file = event.target.files?.[0];
        event.target.value = '';

        if (!file) {
            return;
        }

        const body = new FormData();
        body.append('icon', file);
        setBusy(true);
        setNotice(null);

        jsonRequest(ProjectIconController.update.url(projectId), body)
            .then(() => {
                setNotice({ text: "Updated the app's icon." });
                refresh();
            })
            .catch((e: Error) => setNotice({ text: e.message, error: true }))
            .finally(() => setBusy(false));
    };

    const draw = () => {
        setBusy(true);
        setNotice(null);

        jsonRequest(ProjectIconController.draw.url(projectId), {})
            .then(refresh)
            .catch((e: Error) => setNotice({ text: e.message, error: true }))
            .finally(() => setBusy(false));
    };

    return (
        <div className="max-w-xl space-y-6" data-test="icon-panel">
            <div className="flex items-center gap-5 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                <div
                    className={cn(
                        'flex size-20 shrink-0 items-center justify-center overflow-hidden rounded-2xl bg-muted',
                        drawing && 'animate-pulse',
                    )}
                >
                    {iconUrl ? (
                        <img
                            src={iconUrl}
                            alt="App icon"
                            className="size-full object-contain"
                            data-test="icon-panel-image"
                        />
                    ) : drawing ? (
                        <Spinner className="size-5 text-muted-foreground" />
                    ) : (
                        <span className="text-2xl font-semibold text-muted-foreground uppercase">
                            {project?.name.trim().charAt(0) || '?'}
                        </span>
                    )}
                </div>
                <div className="space-y-1 text-sm">
                    <p className="font-medium" data-test="icon-panel-status">
                        {drawing
                            ? 'Drawing an icon…'
                            : iconUrl
                              ? "Your app's icon"
                              : 'No icon yet'}
                    </p>
                    <p className="text-muted-foreground">
                        Shown in browser tabs and bookmarks for your app, and on
                        the project in your sidebar. It's saved in your app as{' '}
                        <code>public/favicon.svg</code>.
                    </p>
                </div>
            </div>

            <div className="flex flex-wrap gap-2">
                <input
                    ref={input}
                    type="file"
                    accept="image/png,image/jpeg,image/webp,image/svg+xml,.svg"
                    className="hidden"
                    onChange={upload}
                    data-test="icon-panel-file"
                />
                <Button
                    variant="outline"
                    onClick={() => input.current?.click()}
                    disabled={!running || busy}
                    data-test="icon-panel-upload"
                >
                    <Upload />
                    Upload image
                </Button>
                <Button
                    variant="outline"
                    onClick={draw}
                    disabled={!running || busy || drawing}
                    data-test="icon-panel-draw"
                >
                    <Sparkles />
                    {iconUrl ? 'Draw a new one' : 'Draw one'}
                </Button>
            </div>

            {!running && (
                <p className="text-sm text-muted-foreground">
                    Changing the icon works when the sandbox is running.
                </p>
            )}
            {notice && (
                <p
                    className={cn(
                        'text-sm',
                        notice.error ? 'text-red-600' : 'text-muted-foreground',
                    )}
                    data-test="icon-panel-notice"
                >
                    {notice.text}
                </p>
            )}
            <p className="text-sm text-muted-foreground">
                You can also paste an image in the chat and ask the agent to
                make it the favicon. PNG, JPEG, WebP or SVG, up to 512 KB.
            </p>
        </div>
    );
}
