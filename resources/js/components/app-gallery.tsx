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
import { useEffect, useMemo, useRef, useState } from 'react';
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
import type { AppTemplate, CatalogTemplate } from '@/types';

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
export const GENERIC_TAGS = ['self-hosted', 'open-source', 'opensource'];

/** Sized by the space it has: two across on the new-project page, three on the wider home page (like Dokploy's). */
export const appGridClass =
    'grid grid-cols-1 gap-3 @xl:grid-cols-2 @4xl:grid-cols-3';

/** Categories offered above the apps: the most used tags. */
const CATEGORIES = 10;

/** Apple systems say ⌘ for the shortcut; everything else says Ctrl. */
const isApple = () =>
    typeof navigator !== 'undefined' &&
    /Mac|iPhone|iPad|iPod/.test(navigator.userAgent);

export const categoryName = (tag: string) =>
    tag.length <= 3
        ? tag.toUpperCase()
        : tag.charAt(0).toUpperCase() + tag.slice(1).replaceAll('-', ' ');

/**
 * Every free open-source app from the registries (PRJ-012), all shown, with a search box and the most used tags as
 * categories. ⌘K (Ctrl K) anywhere on the page jumps to the search; Enter in it opens the first match.
 */
export default function AppGallery({
    apps,
    onPick,
    renderApp,
    limit,
    onShortcut,
}: {
    apps: CatalogTemplate[];
    onPick: (app: CatalogTemplate) => void;
    renderApp: (app: CatalogTemplate) => ReactNode;
    /** Show only this many until they search, pick a category or ask for all (the home page, HOME-004). */
    limit?: number;
    /** Called on ⌘K before the search is focused, to show the apps if they're hidden (PRJ-001). */
    onShortcut?: () => void;
}) {
    const [query, setQuery] = useState('');
    const [showingAll, setShowingAll] = useState(false);
    const [category, setCategory] = useState<string | null>(null);
    const searchInput = useRef<HTMLInputElement>(null);
    const shortcut = useMemo(() => (isApple() ? '⌘K' : 'Ctrl K'), []);
    const shortcutHandler = useRef(onShortcut);

    useEffect(() => {
        shortcutHandler.current = onShortcut;
    }, [onShortcut]);

    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            if (
                event.key.toLowerCase() === 'k' &&
                (event.metaKey || event.ctrlKey) &&
                !event.altKey &&
                !event.shiftKey &&
                searchInput.current
            ) {
                event.preventDefault();
                shortcutHandler.current?.();
                // After the apps have shown, if they were hidden.
                requestAnimationFrame(() => {
                    const input = searchInput.current;

                    input?.scrollIntoView({
                        block: 'center',
                        behavior: window.matchMedia(
                            '(prefers-reduced-motion: reduce)',
                        ).matches
                            ? 'auto'
                            : 'smooth',
                    });
                    input?.focus({ preventScroll: true });
                    input?.select();
                });
            }
        };

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, []);

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

    const shown =
        limit === undefined || showingAll || query.trim() !== '' || category
            ? matches
            : matches.slice(0, limit);

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
                    ref={searchInput}
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
                <kbd
                    className="shrink-0 rounded border border-input bg-muted px-1.5 py-0.5 font-sans text-[11px] text-muted-foreground pointer-coarse:hidden"
                    aria-hidden="true"
                    data-test="app-search-shortcut"
                >
                    {shortcut}
                </kbd>
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
                <div className="@container">
                    <div className={appGridClass} data-test="free-apps">
                        {shown.map((app) => renderApp(app))}
                    </div>
                    {shown.length < matches.length && (
                        <div className="mt-4 flex justify-center">
                            <Button
                                variant="outline"
                                onClick={() => setShowingAll(true)}
                                data-test="show-all-apps"
                            >
                                Show all {matches.length} apps
                            </Button>
                        </div>
                    )}
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
    signUpFirst = false,
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
    /** On the home page, for a visitor who isn't signed in (HOME-004). */
    signUpFirst?: boolean;
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
                                {signUpFirst
                                    ? `Clicking “Use ${app.label}” asks you to create a free account, then starts your project.`
                                    : `Clicking “Use ${app.label}” starts your project right away.`}
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

const TEMPLATE_STEPS = [
    'We make a new project named after it.',
    'The AI builds the app from the description above.',
    'You see it in the preview, with sample data to try.',
];

/**
 * A built-in template's details (PRJ-004), like a free app's: what it is, its full description to change in place,
 * and a button that starts the project from it right away.
 */
export function TemplateDetails({
    template,
    prompt,
    starting = false,
    error = null,
    onPromptChange,
    onOpenChange,
    onUse,
}: {
    template: AppTemplate | null;
    /** What the AI is asked to build: the template's description, as they've changed it. */
    prompt: string;
    /** The project is being created from it. */
    starting?: boolean;
    /** Why the project couldn't be created. */
    error?: string | null;
    onPromptChange: (prompt: string) => void;
    onOpenChange: (open: boolean) => void;
    onUse: (template: AppTemplate, prompt: string) => void;
}) {
    const Icon = template
        ? (templateIcons[template.value] ?? LayoutTemplate)
        : LayoutTemplate;

    return (
        <Dialog open={template !== null} onOpenChange={onOpenChange}>
            <DialogContent
                className={cn(
                    'max-h-[90vh] gap-5 overflow-y-auto sm:max-w-2xl',
                    // A phone gets the whole screen, like a page of its own.
                    'max-sm:top-0 max-sm:left-0 max-sm:flex max-sm:h-dvh max-sm:max-h-none max-sm:max-w-none max-sm:translate-x-0 max-sm:translate-y-0 max-sm:flex-col max-sm:rounded-none max-sm:border-0',
                )}
                data-test="template-details"
            >
                {template && (
                    <>
                        <div className="flex items-start gap-4">
                            <span className="flex size-14 shrink-0 items-center justify-center rounded-xl bg-sky-500/15 text-sky-600 dark:text-sky-400">
                                <Icon className="size-6" />
                            </span>
                            <div className="min-w-0 space-y-1.5">
                                <DialogTitle className="text-xl">
                                    {template.label}
                                </DialogTitle>
                                <span className="inline-block rounded-full bg-sky-500/15 px-2 py-0.5 text-xs font-medium text-sky-700 dark:text-sky-400">
                                    Template
                                </span>
                            </div>
                        </div>

                        <DialogDescription className="text-sm leading-relaxed text-foreground">
                            {template.description}.
                        </DialogDescription>

                        <div className="space-y-2">
                            <label
                                htmlFor="template-prompt"
                                className="text-sm font-medium"
                            >
                                What the AI will build
                            </label>
                            <textarea
                                id="template-prompt"
                                value={prompt}
                                onChange={(event) =>
                                    onPromptChange(event.target.value)
                                }
                                rows={10}
                                className="w-full resize-y rounded-lg border border-input bg-transparent px-3 py-2 text-sm leading-relaxed shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                data-test="template-details-prompt"
                            />
                            <p className="text-xs text-muted-foreground">
                                Change anything first, like “for our three
                                warehouses” or “add a field for the lead
                                source”. You can ask for more in the chat later.
                            </p>
                        </div>

                        <div className="space-y-2 rounded-xl bg-muted/60 p-4">
                            <p className="text-sm font-medium">
                                What happens when you use it
                            </p>
                            <ol className="space-y-1.5 text-sm text-muted-foreground">
                                {TEMPLATE_STEPS.map((step, index) => (
                                    <li key={step} className="flex gap-2.5">
                                        <span className="flex size-5 shrink-0 items-center justify-center rounded-full bg-sky-500/15 text-xs font-medium text-sky-700 dark:text-sky-400">
                                            {index + 1}
                                        </span>
                                        {step}
                                    </li>
                                ))}
                            </ol>
                        </div>

                        {error && (
                            <p
                                className="text-sm text-destructive"
                                data-test="template-details-error"
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
                                disabled={prompt.trim() === '' || starting}
                                onClick={() => onUse(template, prompt)}
                                className="bg-sky-600 text-white hover:bg-sky-700"
                                data-test="template-details-use"
                            >
                                {starting && <Spinner />}
                                {starting
                                    ? 'Starting…'
                                    : `Use ${template.label}`}
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
