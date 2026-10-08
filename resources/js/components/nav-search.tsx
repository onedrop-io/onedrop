import { Link, router, usePage } from '@inertiajs/react';
import {
    Archive,
    HardDrive,
    LayoutGrid,
    Monitor,
    Search,
    SquarePen,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import type { KeyboardEvent } from 'react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    SidebarGroup,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { jsonRequest } from '@/lib/json-request';
import { useOrganization } from '@/hooks/use-organization';
import { index as apps } from '@/routes/apps';
import { show as computer } from '@/routes/computers';
import { index as drive } from '@/routes/drive';
import { home } from '@/routes/organizations';
import { search, show } from '@/routes/projects';

type SearchResult = { id: number; name: string; archived: boolean };

/**
 * The sidebar's Search row (opens a dialog that searches all the user's projects) under a full-width New project row,
 * then the organization's Apps (APPS-001), the user's own Computer (CMP-001) and Drive (DRIVE-001).
 */
export function NavSearch() {
    const [open, setOpen] = useState(false);
    const organization = useOrganization();
    const currentUrl = usePage().url;

    return (
        <SidebarGroup className="px-2 pt-px pb-0">
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton
                        asChild
                        tooltip={{ children: 'New project' }}
                        className="bg-orange-500/10 font-medium text-orange-700 ring-1 ring-orange-500/20 hover:bg-orange-500/15 hover:text-orange-700 active:bg-orange-500/20 active:text-orange-700 dark:text-orange-300 dark:hover:text-orange-300 dark:active:text-orange-300"
                    >
                        <Link
                            href={home(organization.slug)}
                            prefetch
                            data-test="sidebar-new-project"
                        >
                            <SquarePen />
                            <span>New project</span>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
                <SidebarMenuItem>
                    <SidebarMenuButton
                        onClick={() => setOpen(true)}
                        tooltip={{ children: 'Search' }}
                        className="bg-background text-muted-foreground shadow-xs ring-1 ring-sidebar-border hover:bg-background/60"
                        data-test="sidebar-search"
                    >
                        <Search />
                        <span>Search</span>
                    </SidebarMenuButton>
                </SidebarMenuItem>
                <SidebarMenuItem>
                    <SidebarMenuButton
                        asChild
                        tooltip={{ children: 'Apps' }}
                        isActive={currentUrl.startsWith(
                            apps.url(organization.slug),
                        )}
                    >
                        <Link
                            href={apps(organization.slug)}
                            prefetch
                            data-test="sidebar-apps"
                        >
                            <LayoutGrid />
                            <span>Apps</span>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
                {organization.computers && (
                    <SidebarMenuItem>
                        <SidebarMenuButton
                            asChild
                            tooltip={{ children: 'Computer' }}
                            isActive={currentUrl.startsWith(
                                computer.url(organization.slug),
                            )}
                        >
                            <Link
                                href={computer(organization.slug)}
                                data-test="sidebar-computer"
                            >
                                <Monitor />
                                <span>Computer</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                )}
                <SidebarMenuItem>
                    <SidebarMenuButton
                        asChild
                        tooltip={{ children: 'Drive' }}
                        isActive={currentUrl.startsWith(
                            drive.url(organization.slug),
                        )}
                    >
                        <Link
                            href={drive(organization.slug)}
                            prefetch
                            data-test="sidebar-drive"
                        >
                            <HardDrive />
                            <span>Drive</span>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
            <SearchDialog open={open} onOpenChange={setOpen} />
        </SidebarGroup>
    );
}

function SearchDialog({
    open,
    onOpenChange,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const organization = useOrganization();
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<SearchResult[] | null>(null);
    const [highlighted, setHighlighted] = useState(0);

    useEffect(() => {
        if (!open) {
            return;
        }

        let cancelled = false;
        const timer = setTimeout(() => {
            jsonRequest<{ projects: SearchResult[] }>(
                search.url(organization.slug, { query: { q: query } }),
            )
                .then(({ projects }) => {
                    if (!cancelled) {
                        setResults(projects);
                        setHighlighted(0);
                    }
                })
                .catch(() => !cancelled && setResults([]));
        }, 150);

        return () => {
            cancelled = true;
            clearTimeout(timer);
        };
    }, [open, query, organization.slug]);

    const close = (next: boolean) => {
        onOpenChange(next);

        if (!next) {
            setQuery('');
            setResults(null);
        }
    };

    const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        const count = results?.length ?? 0;

        if (event.key === 'ArrowDown' && count > 0) {
            event.preventDefault();
            setHighlighted((index) => (index + 1) % count);
        } else if (event.key === 'ArrowUp' && count > 0) {
            event.preventDefault();
            setHighlighted((index) => (index - 1 + count) % count);
        } else if (event.key === 'Enter' && results?.[highlighted]) {
            event.preventDefault();
            close(false);
            router.visit(show(results[highlighted].id));
        }
    };

    return (
        <Dialog open={open} onOpenChange={close}>
            <DialogContent className="top-[20%] translate-y-0 gap-0 p-0 sm:max-w-lg [&>button:last-child]:hidden">
                <DialogTitle className="sr-only">Search projects</DialogTitle>
                <DialogDescription className="sr-only">
                    Find a project by name.
                </DialogDescription>
                <div className="flex items-center gap-2 border-b px-3">
                    <Search className="size-4 shrink-0 text-muted-foreground" />
                    <input
                        autoFocus
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        onKeyDown={onKeyDown}
                        placeholder="Search projects…"
                        aria-label="Search projects"
                        className="h-11 w-full bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                        data-test="project-search-input"
                    />
                </div>
                <div className="max-h-80 overflow-y-auto p-1">
                    {results?.length === 0 && (
                        <p className="px-3 py-6 text-center text-sm text-muted-foreground">
                            No projects found.
                        </p>
                    )}
                    {results?.map((project, index) => (
                        <Link
                            key={project.id}
                            href={show(project.id)}
                            onClick={() => close(false)}
                            onMouseMove={() => setHighlighted(index)}
                            className={`flex items-center gap-2 rounded-md px-3 py-2 text-sm ${index === highlighted ? 'bg-accent text-accent-foreground' : ''}`}
                            data-test="project-search-result"
                        >
                            <span className="truncate">{project.name}</span>
                            {project.archived && (
                                <Archive
                                    className="ml-auto size-3.5 shrink-0 text-muted-foreground"
                                    aria-label="Archived"
                                />
                            )}
                        </Link>
                    ))}
                </div>
            </DialogContent>
        </Dialog>
    );
}
