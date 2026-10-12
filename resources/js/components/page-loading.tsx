import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Skeleton } from '@/components/ui/skeleton';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { Spinner } from '@/components/ui/spinner';
import { onPageVisit } from '@/lib/page-visits';

/** How long a visit runs before the page it's going to shows as loading: quick ones don't flash. */
const DELAY_MS = 150;
/** How long before it says it's still loading. */
const SLOW_MS = 8000;

/**
 * While another page loads, an empty frame of it over the one being left, so a click shows at once (PRJ-002). A
 * project's has its name and a spinner where its chat goes. Nothing in it is drawn again once the page is there:
 * no stand-in messages or chat box to be swapped for the real ones.
 */
export function PageLoading() {
    const { sidebarProjects, openProject } = usePage().props;
    const [target, setTarget] = useState<URL | null>(null);
    const [shown, setShown] = useState(false);
    const [slow, setSlow] = useState(false);

    // An earlier version kept chats' last messages here to show while loading; they're not used any more.
    useEffect(() => {
        try {
            window.localStorage.removeItem('onedrop.glimpses');
        } catch {
            // Storage blocked: nothing was kept either.
        }
    }, []);

    useEffect(
        () =>
            onPageVisit(
                (url) => {
                    setTarget(url);
                    setShown(false);
                    setSlow(false);
                },
                () => setTarget(null),
            ),
        [],
    );

    useEffect(() => {
        if (!target) {
            return;
        }

        const show = setTimeout(() => setShown(true), DELAY_MS);
        const later = setTimeout(() => setSlow(true), SLOW_MS);

        return () => {
            clearTimeout(show);
            clearTimeout(later);
        };
    }, [target]);

    if (!target || !shown) {
        return null;
    }

    const projectId = Number(
        target.pathname.match(/^\/projects\/(\d+)(\/|$)/)?.[1] ?? NaN,
    );
    const isProject = !Number.isNaN(projectId);
    const name = isProject
        ? ([
              ...(sidebarProjects?.pinned ?? []),
              ...(sidebarProjects?.recent ?? []),
              ...(sidebarProjects?.archived ?? []),
              ...(openProject ? [openProject] : []),
          ].find((candidate) => candidate.id === projectId)?.name ?? null)
        : null;

    const loading = (
        <div
            className="flex flex-col items-center gap-3 text-sm text-muted-foreground"
            aria-live="polite"
        >
            <Spinner className="size-6" />
            {slow && <span>Still loading…</span>}
        </div>
    );

    return (
        <div
            className="absolute inset-0 z-30 flex flex-col overflow-hidden bg-background md:rounded-xl"
            aria-busy="true"
            data-test="page-loading"
        >
            <div className="flex h-16 shrink-0 items-center gap-2 border-b border-sidebar-border/50 px-6 md:px-4">
                <SidebarTrigger className="-ml-1" />
                {name ? (
                    <span className="truncate" data-test="page-loading-name">
                        {name}
                    </span>
                ) : (
                    <Skeleton className="h-4 w-32" />
                )}
            </div>
            {isProject ? (
                <>
                    {/* The chat (beside the workspace on a wide screen), where the page opens. */}
                    <div className="flex min-h-0 flex-1">
                        <div className="flex w-full items-center justify-center lg:w-[28rem] lg:shrink-0 lg:border-r lg:border-sidebar-border/50">
                            {loading}
                        </div>
                        <div className="hidden flex-1 lg:block" />
                    </div>
                    {/* The phone's tab bar (LAYOUT-007). */}
                    <div className="h-14 shrink-0 border-t border-sidebar-border/50 bg-toolbar md:hidden" />
                </>
            ) : (
                <div className="flex flex-1 items-center justify-center">
                    {loading}
                </div>
            )}
        </div>
    );
}
