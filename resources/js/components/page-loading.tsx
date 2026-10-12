import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Skeleton } from '@/components/ui/skeleton';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { Spinner } from '@/components/ui/spinner';
import { onPageVisit } from '@/lib/page-visits';
import { cn } from '@/lib/utils';

/** How long a visit runs before the page it's going to shows as loading: quick ones don't flash. */
const DELAY_MS = 150;
/** How long before it says it's still loading. */
const SLOW_MS = 8000;

/**
 * While another page loads, a sketch of it over the one being left, so a click shows at once (PRJ-002). A
 * project's shows its name and the shape of the workspace.
 */
export function PageLoading() {
    const { sidebarProjects, openProject } = usePage().props;
    const [target, setTarget] = useState<URL | null>(null);
    const [shown, setShown] = useState(false);
    const [slow, setSlow] = useState(false);

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
    const project = Number.isNaN(projectId)
        ? null
        : ([
              ...(sidebarProjects?.pinned ?? []),
              ...(sidebarProjects?.recent ?? []),
              ...(sidebarProjects?.archived ?? []),
              ...(openProject ? [openProject] : []),
          ].find((candidate) => candidate.id === projectId) ?? {
              id: projectId,
              name: null,
          });

    return (
        <div
            className="absolute inset-0 z-30 flex flex-col overflow-hidden bg-background md:rounded-xl"
            aria-busy="true"
            data-test="page-loading"
        >
            <div className="flex h-16 shrink-0 items-center gap-2 border-b border-sidebar-border/50 px-6 md:px-4">
                <SidebarTrigger className="-ml-1" />
                {project?.name ? (
                    <span className="truncate" data-test="page-loading-name">
                        {project.name}
                    </span>
                ) : (
                    <Skeleton className="h-4 w-32" />
                )}
                <Spinner className="shrink-0 text-muted-foreground" />
                {slow && (
                    <span className="truncate text-sm text-muted-foreground">
                        Still loading…
                    </span>
                )}
                <Skeleton className="ml-auto h-8 w-20 max-sm:hidden" />
            </div>
            {project ? (
                <>
                    <WorkspaceSketch />
                    {/* The phone's tab bar (LAYOUT-007). */}
                    <div className="h-14 shrink-0 border-t border-sidebar-border/50 bg-toolbar md:hidden" />
                </>
            ) : (
                <PageSketch />
            )}
        </div>
    );
}

/** The chat beside the workspace; on a phone, the chat above the bottom tab bar (LAYOUT-007). */
function WorkspaceSketch() {
    return (
        <div className="flex min-h-0 flex-1">
            <div className="flex min-h-0 w-full flex-col p-4 lg:w-[28rem] lg:shrink-0 lg:border-r lg:border-sidebar-border/50">
                <div className="flex-1 space-y-4">
                    <Bubble className="ml-auto w-2/3" />
                    <Bubble className="w-5/6" />
                    <Bubble className="w-1/2" />
                </div>
                <Skeleton className="h-24 w-full rounded-2xl" />
            </div>
            <div className="hidden min-w-0 flex-1 flex-col lg:flex">
                <div className="flex h-11 items-center gap-2 border-b border-sidebar-border/50 px-3">
                    <Skeleton className="h-5 w-16" />
                    <Skeleton className="h-5 w-20" />
                    <Skeleton className="h-5 w-14" />
                </div>
                <Skeleton className="m-4 flex-1 rounded-lg" />
            </div>
        </div>
    );
}

function Bubble({ className }: { className?: string }) {
    return (
        <div className={cn('space-y-2', className)}>
            <Skeleton className="h-4 w-full" />
            <Skeleton className="h-4 w-4/5" />
        </div>
    );
}

function PageSketch() {
    return (
        <div className="space-y-4 p-6">
            <Skeleton className="h-6 w-48" />
            <Skeleton className="h-4 w-full max-w-xl" />
            <Skeleton className="h-4 w-full max-w-md" />
            <div className="grid gap-4 pt-2 sm:grid-cols-2 lg:grid-cols-3">
                <Skeleton className="h-32" />
                <Skeleton className="h-32" />
                <Skeleton className="h-32" />
            </div>
        </div>
    );
}
