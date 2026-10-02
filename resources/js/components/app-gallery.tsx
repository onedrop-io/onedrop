import {
    BookOpen,
    Boxes,
    BriefcaseBusiness,
    Bug,
    CalendarClock,
    CalendarDays,
    CalendarHeart,
    ClipboardCheck,
    Code,
    ExternalLink,
    Globe,
    HandCoins,
    Handshake,
    HeartHandshake,
    House,
    KanbanSquare,
    Laptop,
    LayoutTemplate,
    LifeBuoy,
    MessageSquareHeart,
    Milestone,
    Network,
    Newspaper,
    Receipt,
    Search,
    Target,
    UserSearch,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import AppScreenshots from '@/components/app-screenshots';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import TurnOnDocker from '@/components/turn-on-docker';
import { cn } from '@/lib/utils';
import type { CatalogTemplate } from '@/types';

/** The built-in templates' icons (PRJ-004). */
export const templateIcons: Record<string, LucideIcon> = {
    crm: Handshake,
    'project-tracker': KanbanSquare,
    'content-calendar': Newspaper,
    inventory: Boxes,
    hiring: UserSearch,
    events: CalendarHeart,
    'help-desk': LifeBuoy,
    'time-off': CalendarDays,
    expenses: Receipt,
    'product-roadmap': Milestone,
    'bug-tracker': Bug,
    okrs: Target,
    feedback: MessageSquareHeart,
    directory: Network,
    onboarding: ClipboardCheck,
    assets: Laptop,
    shifts: CalendarClock,
    'client-portal': BriefcaseBusiness,
    grants: HandCoins,
    volunteers: HeartHandshake,
    rentals: House,
};

/** Tags too general to filter by. */
const GENERIC_TAGS = ['self-hosted', 'open-source', 'opensource'];

/** Categories offered above the apps: the most used tags. */
const CATEGORIES = 10;

export const categoryName = (tag: string) =>
    tag.length <= 3
        ? tag.toUpperCase()
        : tag.charAt(0).toUpperCase() + tag.slice(1).replaceAll('-', ' ');

/**
 * Every free open-source app from the registries (PRJ-012), all shown, with a search box and the most used tags as
 * categories. Enter in the search opens the first match.
 */
export default function AppGallery({
    apps,
    onPick,
    renderApp,
}: {
    apps: CatalogTemplate[];
    onPick: (app: CatalogTemplate) => void;
    renderApp: (app: CatalogTemplate) => ReactNode;
}) {
    const [query, setQuery] = useState('');
    const [category, setCategory] = useState<string | null>(null);

    // Organized by what the apps do, not where they come from.
    const categories = useMemo(() => {
        const counts = new Map<string, number>();

        for (const app of apps) {
            for (const tag of app.tags) {
                if (!GENERIC_TAGS.includes(tag)) {
                    counts.set(tag, (counts.get(tag) ?? 0) + 1);
                }
            }
        }

        return [...counts.entries()]
            .sort((a, b) => b[1] - a[1])
            .slice(0, CATEGORIES)
            .map(([tag]) => tag);
    }, [apps]);

    const matches = useMemo(() => {
        const words = query.toLowerCase().split(/\s+/).filter(Boolean);

        return apps.filter((app) => {
            if (category && !app.tags.includes(category)) {
                return false;
            }

            const text = [app.label, app.description, ...app.tags]
                .join(' ')
                .toLowerCase();

            return words.every((word) => text.includes(word));
        });
    }, [apps, query, category]);

    if (apps.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                The free apps can’t be loaded right now. Try again in a few
                minutes.
            </p>
        );
    }

    return (
        <div className="space-y-3">
            <div className="flex h-10 items-center gap-2 rounded-xl border border-input bg-background px-3">
                <Search className="size-4 shrink-0 text-muted-foreground" />
                <input
                    type="search"
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter' && matches[0]) {
                            event.preventDefault();
                            onPick(matches[0]);
                        }
                    }}
                    placeholder={`Search ${apps.length} free apps…`}
                    aria-label="Search free apps"
                    className="w-full bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                    data-test="app-search-input"
                />
            </div>
            {categories.length > 0 && (
                <div className="flex flex-wrap gap-1.5">
                    {[null, ...categories].map((name) => (
                        <button
                            key={name ?? 'all'}
                            type="button"
                            onClick={() => setCategory(name)}
                            aria-pressed={category === name}
                            className={cn(
                                'rounded-full border border-input bg-background px-3 py-1 text-xs text-muted-foreground hover:text-foreground',
                                category === name &&
                                    'border-emerald-500 bg-emerald-500/10 text-foreground',
                            )}
                        >
                            {name ? categoryName(name) : 'All'}
                        </button>
                    ))}
                </div>
            )}
            {matches.length === 0 ? (
                <p className="py-6 text-center text-sm text-muted-foreground">
                    No apps found. Try another word, or start from scratch
                    above.
                </p>
            ) : (
                <div
                    className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3"
                    data-test="free-apps"
                >
                    {matches.map((app) => renderApp(app))}
                </div>
            )}
        </div>
    );
}

/** What setting up a free app does, in words a first-time user knows (matches the agent's steps in PRJ-012). */
const SETUP_STEPS = [
    'We put it in its own private space, just for this project.',
    'The AI creates its passwords and settings, then starts it.',
    'You see it running here, and the AI tells you how to sign in.',
];

/**
 * A free app's details (PRJ-012): what it is, its links, what setting it up does, and a button to use it. Shown for
 * apps that can't run here too, with the reason, so people can still read about them.
 */
export function AppDetails({
    app,
    compose,
    starting = false,
    error = null,
    onOpenChange,
    onUse,
}: {
    app: CatalogTemplate | null;
    /** New projects' sandboxes can run Docker Compose, so the app can be used. */
    compose: boolean;
    /** The project is being created from it. */
    starting?: boolean;
    /** Why the project couldn't be created. */
    error?: string | null;
    onOpenChange: (open: boolean) => void;
    onUse: (app: CatalogTemplate) => void;
}) {
    const usable = app !== null && (compose || !app.compose);
    const links = app
        ? [
              { label: 'Website', href: app.links.website, icon: Globe },
              { label: 'Source code', href: app.links.github, icon: Code },
              { label: 'Documentation', href: app.links.docs, icon: BookOpen },
          ].filter((link) => link.href !== null)
        : [];

    return (
        <Dialog open={app !== null} onOpenChange={onOpenChange}>
            <DialogContent
                className={cn(
                    'max-h-[90vh] gap-5 overflow-y-auto sm:max-w-2xl',
                    // A phone gets the whole screen, like a page of its own.
                    'max-sm:top-0 max-sm:left-0 max-sm:flex max-sm:h-dvh max-sm:max-h-none max-sm:max-w-none max-sm:translate-x-0 max-sm:translate-y-0 max-sm:flex-col max-sm:rounded-none max-sm:border-0',
                )}
                data-test="app-details"
            >
                {app && (
                    <>
                        <AppScreenshots key={app.value} template={app.value} />

                        <div className="flex items-start gap-4">
                            <TemplateLogo
                                template={app}
                                className="size-14 rounded-xl"
                            />
                            <div className="min-w-0 space-y-1.5">
                                <DialogTitle className="text-xl">
                                    {app.label}
                                </DialogTitle>
                                <div className="flex flex-wrap items-center gap-1.5">
                                    <span className="rounded-full bg-emerald-500/15 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:text-emerald-400">
                                        Free and open source
                                    </span>
                                    {app.version && (
                                        <span className="rounded-full border border-input px-2 py-0.5 text-xs text-muted-foreground">
                                            Version {app.version}
                                        </span>
                                    )}
                                    {app.tags
                                        .filter(
                                            (tag) =>
                                                !GENERIC_TAGS.includes(tag),
                                        )
                                        .map((tag) => (
                                            <span
                                                key={tag}
                                                className="rounded-full border border-input px-2 py-0.5 text-xs text-muted-foreground"
                                            >
                                                {categoryName(tag)}
                                            </span>
                                        ))}
                                </div>
                            </div>
                        </div>

                        <DialogDescription className="text-sm leading-relaxed text-foreground">
                            {app.description ||
                                `${app.label} is a free, open-source app.`}
                        </DialogDescription>

                        <div className="space-y-2 rounded-xl bg-muted/60 p-4">
                            <p className="text-sm font-medium">
                                What happens when you use it
                            </p>
                            <p className="text-sm text-muted-foreground">
                                Clicking “Use {app.label}” starts your project
                                right away.
                            </p>
                            <ol className="space-y-1.5 text-sm text-muted-foreground">
                                {SETUP_STEPS.map((step, index) => (
                                    <li key={step} className="flex gap-2.5">
                                        <span className="flex size-5 shrink-0 items-center justify-center rounded-full bg-emerald-500/15 text-xs font-medium text-emerald-700 dark:text-emerald-400">
                                            {index + 1}
                                        </span>
                                        {step}
                                    </li>
                                ))}
                            </ol>
                            <p className="pt-1 text-xs text-muted-foreground">
                                Afterwards, ask the AI for any changes, like
                                “use New York time”.
                            </p>
                        </div>

                        {links.length > 0 && (
                            <div className="flex flex-wrap gap-x-4 gap-y-2">
                                {links.map(({ label, href, icon: Icon }) => (
                                    <a
                                        key={label}
                                        href={href!}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
                                    >
                                        <Icon className="size-4" />
                                        {label}
                                        <ExternalLink className="size-3" />
                                    </a>
                                ))}
                            </div>
                        )}

                        {!usable && (
                            <p
                                className="rounded-lg border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-sm"
                                data-test="app-details-unavailable"
                            >
                                This app needs Docker, which isn’t turned on
                                here yet. <TurnOnDocker />
                            </p>
                        )}

                        {error && (
                            <p
                                className="text-sm text-destructive"
                                data-test="app-details-error"
                            >
                                {error}
                            </p>
                        )}

                        {/* Pinned to the bottom while the rest scrolls, so "Use" is always in reach. */}
                        <DialogFooter className="sticky -bottom-6 -mx-6 -mb-6 border-t bg-background px-6 py-4 max-sm:mt-auto">
                            <Button
                                variant="outline"
                                onClick={() => onOpenChange(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                disabled={!usable || starting}
                                onClick={() => onUse(app)}
                                className="bg-emerald-600 text-white hover:bg-emerald-700"
                                data-test="app-details-use"
                            >
                                {starting && <Spinner />}
                                {starting ? 'Starting…' : `Use ${app.label}`}
                            </Button>
                        </DialogFooter>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}

export function TemplateLogo({
    template,
    className = 'size-8',
}: {
    template: CatalogTemplate;
    className?: string;
}) {
    const [failed, setFailed] = useState(false);
    const Icon = templateIcons[template.value] ?? LayoutTemplate;

    return (
        <span
            className={cn(
                'flex shrink-0 items-center justify-center overflow-hidden rounded-md border bg-background',
                // Most logos are drawn for a light page.
                template.logo && !failed && 'bg-white',
                className,
            )}
        >
            {template.logo && !failed ? (
                <img
                    src={template.logo}
                    alt=""
                    loading="lazy"
                    referrerPolicy="no-referrer"
                    onError={() => setFailed(true)}
                    className="size-3/4 object-contain"
                />
            ) : (
                <Icon className="size-4 text-muted-foreground" />
            )}
        </span>
    );
}
