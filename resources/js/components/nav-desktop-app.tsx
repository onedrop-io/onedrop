import { usePage } from '@inertiajs/react';
import { Download, Monitor, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useDesktopDownload } from '@/hooks/use-desktop-download';
import { DESKTOP_DOCS_URL } from '@/lib/links';

/** Remembered in the browser: it's only a hint, and they're signed in on this browser anyway. */
const DISMISSED_KEY = 'onedrop.desktop-app-offer-dismissed';

/** The sidebar's offer of the desktop app (DESK-005), above the account menu, until it's signed in somewhere or closed. */
export function NavDesktopApp() {
    const { offerDesktopApp } = usePage().props;
    const download = useDesktopDownload();
    // Hidden until the browser says it wasn't closed before, so it never flashes in for someone who closed it.
    const [dismissed, setDismissed] = useState(true);

    useEffect(() => {
        setDismissed(localStorage.getItem(DISMISSED_KEY) === '1');
    }, []);

    if (!offerDesktopApp || dismissed) {
        return null;
    }

    const dismiss = () => {
        localStorage.setItem(DISMISSED_KEY, '1');
        setDismissed(true);
    };

    return (
        <div
            data-test="desktop-app-offer"
            className="relative rounded-lg border bg-sidebar-accent/40 p-3 text-sm group-data-[collapsible=icon]:hidden"
        >
            <button
                type="button"
                onClick={dismiss}
                aria-label="Close"
                data-test="desktop-app-offer-close"
                className="absolute top-2 right-2 rounded p-1 text-muted-foreground hover:bg-sidebar-accent hover:text-sidebar-accent-foreground"
            >
                <X className="size-3.5" />
            </button>
            <p className="flex items-center gap-2 pr-5 font-medium">
                <Monitor className="size-4 shrink-0" />
                Get the desktop app
            </p>
            <p className="mt-1 text-xs leading-relaxed text-muted-foreground">
                Your projects in a window of their own, with a notification when
                the agent is done.
            </p>
            <div className="mt-2.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                <a
                    href={download.href}
                    data-test="desktop-app-offer-download"
                    className="inline-flex items-center gap-1.5 font-medium text-foreground underline-offset-4 hover:underline"
                >
                    <Download className="size-3.5" />
                    {download.label}
                </a>
                <a
                    href={DESKTOP_DOCS_URL}
                    target="_blank"
                    rel="noreferrer"
                    className="text-muted-foreground underline-offset-4 hover:underline"
                >
                    Other systems
                </a>
            </div>
        </div>
    );
}
