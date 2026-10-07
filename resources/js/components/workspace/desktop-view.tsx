import { Link, router } from '@inertiajs/react';
import { HardDrive, MoreHorizontal, RotateCcw, Trash2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';
import { destroy, restart, retry } from '@/routes/computers';
import { index as drive } from '@/routes/drive';
import type { SandboxState } from '@/types';

// How long the frame may stay silent before it's loaded again: the viewer speaks as soon as it loads.
const SILENT_MS = 4_000;

/**
 * A person's computer's desktop (CMP-001), where an app's preview would be: the viewer the sandbox serves
 * (docker/sandbox/desktop-viewer.html) in a frame, or what's happening while it starts.
 */
export function DesktopView({
    organization,
    sandbox,
    url,
    wakes,
}: {
    organization: string;
    sandbox: SandboxState | null;
    /** The viewer's address, once the computer runs. */
    url: string | null;
    /** Changes when the tab came back to the computer asleep: the viewer loads again. */
    wakes: number;
}) {
    const frame = useRef<HTMLIFrameElement>(null);
    const [connected, setConnected] = useState(false);
    // A computer that was woken is still starting its desktop, so the frame first gets an error page, which never
    // loads again by itself: it's loaded again until the viewer speaks, then the viewer reconnects on its own.
    const [reloads, setReloads] = useState(0);
    const frameKey = `${wakes}:${reloads}:${url}`;
    const [heardFrom, setHeardFrom] = useState<string | null>(null);
    const heard = heardFrom === frameKey;

    // The viewer says when it loads and when its screen connects, and takes the keyboard then.
    useEffect(() => {
        const onMessage = (event: MessageEvent) => {
            if (
                event.source === frame.current?.contentWindow &&
                event.data?.type === 'onedrop-desktop'
            ) {
                setHeardFrom(frameKey);
                setConnected(event.data.state === 'connected');

                if (event.data.state === 'connected') {
                    frame.current?.focus();
                }
            }
        };

        window.addEventListener('message', onMessage);

        return () => window.removeEventListener('message', onMessage);
    }, [frameKey]);

    useEffect(() => {
        if (!url || heard) {
            return;
        }

        const timer = window.setTimeout(
            () => setReloads((count) => count + 1),
            SILENT_MS,
        );

        return () => window.clearTimeout(timer);
    }, [url, heard, frameKey]);

    if (!url) {
        return (
            <div className="flex flex-1 bg-neutral-700">
                <DesktopPlaceholder
                    organization={organization}
                    sandbox={sandbox}
                />
            </div>
        );
    }

    return (
        <div
            className="relative min-h-0 flex-1 bg-neutral-700"
            data-test="desktop"
            data-connected={connected}
        >
            <iframe
                key={`${wakes}:${reloads}`}
                ref={frame}
                src={url}
                title="Computer"
                allow="clipboard-read; clipboard-write; fullscreen"
                className="absolute inset-0 size-full border-0"
                data-test="desktop-frame"
            />
            {!heard && (
                <div className="absolute inset-0 flex bg-neutral-700">
                    <DesktopPlaceholder
                        organization={organization}
                        sandbox={sandbox}
                    />
                </div>
            )}
        </div>
    );
}

/**
 * What's happening while the computer starts, wakes or updates, or why it didn't start.
 */
function DesktopPlaceholder({
    organization,
    sandbox,
}: {
    organization: string;
    sandbox: SandboxState | null;
}) {
    const failed = sandbox?.status === 'failed';

    return (
        <div
            className="flex flex-1 items-center justify-center p-6"
            data-test="desktop-placeholder"
        >
            <div className="max-w-sm rounded-xl border border-sidebar-border/70 bg-background/95 p-5 text-center text-sm shadow-sm dark:border-sidebar-border">
                {!failed && (
                    <Spinner className="mx-auto mb-3 size-5 text-muted-foreground" />
                )}
                <p className="font-medium">
                    {failed
                        ? "Your computer didn't start"
                        : sandbox?.updating
                          ? 'Updating your computer…'
                          : 'Starting your computer…'}
                </p>
                <p
                    className={
                        failed
                            ? 'mt-1 text-red-600'
                            : 'mt-1 text-muted-foreground'
                    }
                    data-test="desktop-message"
                >
                    {failed
                        ? sandbox.error
                        : sandbox?.updating
                          ? 'Getting the latest tools. Your files are kept.'
                          : 'Your desktop will appear here in a moment.'}
                </p>
                {failed && (
                    <Button
                        className="mt-4"
                        size="sm"
                        onClick={() => router.post(retry.url(organization))}
                        data-test="desktop-retry"
                    >
                        Try again
                    </Button>
                )}
            </div>
        </div>
    );
}

/**
 * The computer's menu in the header (CMP-001): restart its desktop, open Drive, or reset it.
 */
export function ComputerMenu({
    organization,
    running,
}: {
    organization: string;
    running: boolean;
}) {
    const [resetting, setResetting] = useState(false);
    const [processing, setProcessing] = useState(false);

    return (
        <>
            <DropdownMenu modal={false}>
                <DropdownMenuTrigger asChild>
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label="Computer menu"
                        data-test="computer-menu"
                    >
                        <MoreHorizontal className="size-4" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuItem
                        disabled={!running}
                        onSelect={() =>
                            router.post(
                                restart.url(organization),
                                {},
                                { preserveScroll: true },
                            )
                        }
                        data-test="computer-restart"
                    >
                        <RotateCcw />
                        Restart desktop
                    </DropdownMenuItem>
                    <DropdownMenuItem asChild>
                        <Link href={drive(organization)}>
                            <HardDrive />
                            Open Drive
                        </Link>
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        variant="destructive"
                        onSelect={() => setResetting(true)}
                        data-test="computer-reset"
                    >
                        <Trash2 />
                        Reset computer…
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
            <Dialog open={resetting} onOpenChange={setResetting}>
                <DialogContent>
                    <DialogTitle>Reset your computer?</DialogTitle>
                    <DialogDescription>
                        You get a new computer. Everything in its Home (Desktop,
                        Documents, Downloads), Chromium's sign-ins and history,
                        and this chat are deleted. Your Drive is kept.
                    </DialogDescription>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setResetting(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            variant="destructive"
                            disabled={processing}
                            data-test="computer-reset-confirm"
                            onClick={() =>
                                router.delete(destroy.url(organization), {
                                    onStart: () => setProcessing(true),
                                    onFinish: () => {
                                        setProcessing(false);
                                        setResetting(false);
                                    },
                                })
                            }
                        >
                            Reset computer
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
