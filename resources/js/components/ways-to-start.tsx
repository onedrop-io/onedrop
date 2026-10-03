import { Deferred } from '@inertiajs/react';
import { LayoutTemplate, Package } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import AppCoverflow from '@/components/app-coverflow';
import AppGallery, {
    GENERIC_TAGS,
    TemplateLogo,
    appGridClass,
    categoryName,
    templateIcons,
} from '@/components/app-gallery';
import TurnOnDocker from '@/components/turn-on-docker';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';
import type { AppTemplate, CatalogTemplate, FeaturedApp } from '@/types';

/**
 * The ways to start a project, shared by the new-project page (PRJ-001, PRJ-004, PRJ-012) and the home page
 * (HOME-004): a heading and colour for each, the built-in templates, and the free apps.
 */

/** Each way to start has its own colour, so the three read apart at a glance. */
export const tones = {
    scratch: {
        tile: 'bg-violet-500/15 text-violet-600 dark:text-violet-400',
        icon: 'text-violet-600 dark:text-violet-400',
        card: 'hover:border-violet-500/50 hover:bg-violet-500/5',
        pressed: 'border-violet-500 bg-violet-500/10',
        panel: 'border-violet-500/25 bg-violet-500/[0.04] dark:border-violet-500/30 dark:bg-violet-500/[0.07]',
    },
    template: {
        tile: 'bg-sky-500/15 text-sky-600 dark:text-sky-400',
        icon: 'text-sky-600 dark:text-sky-400',
        card: 'hover:border-sky-500/50 hover:bg-sky-500/5',
        pressed: 'border-sky-500 bg-sky-500/10',
        panel: 'border-sky-500/25 bg-sky-500/[0.04] dark:border-sky-500/30 dark:bg-sky-500/[0.07]',
    },
    app: {
        tile: 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400',
        icon: 'text-emerald-600 dark:text-emerald-400',
        card: 'hover:border-emerald-500/50 hover:bg-emerald-500/5',
        pressed: 'border-emerald-500 bg-emerald-500/10',
        panel: 'border-emerald-500/25 bg-emerald-500/[0.04] dark:border-emerald-500/30 dark:bg-emerald-500/[0.07]',
    },
};

type Tone = keyof typeof tones;

export const panelClass = 'rounded-2xl border p-4 sm:p-5';

/** A heading for one of the ways to start a project, in words a first-time user knows. */
export function WayToStart({
    tone,
    icon: Icon,
    title,
    description,
    className,
}: {
    tone: Tone;
    icon: LucideIcon;
    title: string;
    description: string;
    className?: string;
}) {
    return (
        <div className={cn('flex items-start gap-3', className)}>
            <span
                className={cn(
                    'flex size-10 shrink-0 items-center justify-center rounded-xl',
                    tones[tone].tile,
                )}
            >
                <Icon className="size-5" />
            </span>
            <div className="space-y-0.5">
                <h2 className="text-lg font-semibold">{title}</h2>
                <p className="text-sm text-muted-foreground">{description}</p>
            </div>
        </div>
    );
}

export function TemplateCard({
    tone,
    label,
    description,
    icon,
    pressed,
    dimmed = false,
    onClick,
}: {
    tone: Tone;
    label: string;
    description: string;
    icon: ReactNode;
    pressed: boolean;
    /** Can't be used here (it still opens, to read about it). */
    dimmed?: boolean;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={pressed}
            className={cn(
                'flex items-start gap-3 rounded-xl border border-input bg-background px-4 py-3 text-left transition-colors',
                dimmed && 'opacity-60',
                tones[tone].card,
                pressed && tones[tone].pressed,
            )}
        >
            {icon}
            <span className="min-w-0 space-y-0.5">
                <span className="block text-sm font-medium break-words">
                    {label}
                </span>
                <span className="line-clamp-2 text-xs break-words text-muted-foreground">
                    {description}
                </span>
            </span>
        </button>
    );
}

/** "Or start from a template": the built-in templates (PRJ-004). */
export function TemplatesPanel({
    templates,
    selected,
    onPick,
    bare = false,
}: {
    templates: AppTemplate[];
    selected: string | null;
    onPick: (template: AppTemplate) => void;
    /** Just the templates, without the panel and its heading (the new-project page's "From a template", PRJ-001). */
    bare?: boolean;
}) {
    return (
        <div
            className={cn(
                'space-y-4',
                !bare && [panelClass, tones.template.panel],
            )}
        >
            {!bare && (
                <WayToStart
                    tone="template"
                    icon={LayoutTemplate}
                    title="Or start from a template"
                    description="A ready-made starting point. Pick one, change anything, then send it."
                />
            )}
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                {templates.map((option) => {
                    const Icon = templateIcons[option.value] ?? LayoutTemplate;

                    return (
                        <TemplateCard
                            tone="template"
                            key={option.value}
                            label={option.label}
                            description={option.description}
                            icon={
                                <Icon
                                    className={cn(
                                        'mt-0.5 size-4 shrink-0',
                                        tones.template.icon,
                                    )}
                                />
                            }
                            pressed={selected === option.value}
                            onClick={() => onPick(option)}
                        />
                    );
                })}
            </div>
        </div>
    );
}

/** "Or install a free app": the popular ones in a coverflow, then all of them, searchable (PRJ-012). */
export function FreeAppsPanel({
    apps,
    featured,
    compose,
    selected,
    onView,
    limit,
    bare = false,
    onShortcut,
}: {
    /** Show only this many until they search or ask for all. */
    limit?: number;
    /** Just the apps, without the panel and its heading (the new-project page's "Something that exists", PRJ-001). */
    bare?: boolean;
    /** Called on ⌘K before the search is focused, to show the apps if they're hidden. */
    onShortcut?: () => void;
    /** Deferred, since a registry may be slow. */
    apps?: CatalogTemplate[];
    /** Deferred on their own, since finding pictures is slow. */
    featured?: FeaturedApp[];
    /** New projects' sandboxes can run Docker Compose. */
    compose: boolean;
    selected: string | null;
    onView: (app: CatalogTemplate) => void;
}) {
    return (
        <div
            className={cn('space-y-4', !bare && [panelClass, tones.app.panel])}
        >
            {!bare && (
                <WayToStart
                    tone="app"
                    icon={Package}
                    title="Or install a free app"
                    description="Free open-source apps, already built. Pick one and we set it up for you, ready to use."
                />
            )}
            {!compose && (
                <p className="text-xs text-muted-foreground">
                    These run with Docker. <TurnOnDocker />
                </p>
            )}
            <Deferred
                data="featured"
                fallback={<Skeleton className="h-56 rounded-xl sm:h-72" />}
            >
                <AppCoverflow apps={featured ?? []} onOpen={onView} />
            </Deferred>
            <Deferred
                data="apps"
                fallback={
                    <div className="@container">
                        <div className={appGridClass}>
                            {Array.from({ length: 6 }, (_, key) => (
                                <Skeleton
                                    key={key}
                                    className="h-44 rounded-xl"
                                />
                            ))}
                        </div>
                    </div>
                }
            >
                <AppGallery
                    apps={apps ?? []}
                    onPick={onView}
                    limit={limit}
                    onShortcut={onShortcut}
                    renderApp={(app) => (
                        <AppCard
                            key={app.value}
                            app={app}
                            pressed={selected === app.value}
                            dimmed={!compose}
                            onClick={() => onView(app)}
                        />
                    )}
                />
            </Deferred>
        </div>
    );
}

/** Tags shown on a free app's card. */
const CARD_TAGS = 3;

/** A free app in the list: its logo, name and version, a few lines about it, and its tags, like Dokploy's (PRJ-012). */
function AppCard({
    app,
    pressed,
    dimmed,
    onClick,
}: {
    app: CatalogTemplate;
    pressed: boolean;
    /** Can't be used here (it still opens, to read about it). */
    dimmed: boolean;
    onClick: () => void;
}) {
    const tags = app.tags
        .filter((tag) => !GENERIC_TAGS.includes(tag))
        .slice(0, CARD_TAGS);

    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={pressed}
            className={cn(
                'flex min-h-44 flex-col gap-3 rounded-xl border border-input bg-background p-5 text-left transition-colors',
                dimmed && 'opacity-60',
                tones.app.card,
                pressed && tones.app.pressed,
            )}
        >
            <span className="flex items-center gap-3">
                <TemplateLogo template={app} className="size-10 rounded-lg" />
                <span className="min-w-0">
                    <span className="block font-medium break-words">
                        {app.label}
                    </span>
                    {app.version && (
                        <span className="block text-xs text-muted-foreground">
                            Version {app.version}
                        </span>
                    )}
                </span>
            </span>
            <span className="line-clamp-3 text-sm break-words text-muted-foreground">
                {app.description}
            </span>
            {tags.length > 0 && (
                <span className="mt-auto flex flex-wrap gap-1.5">
                    {tags.map((tag) => (
                        <span
                            key={tag}
                            className="rounded-md bg-muted px-2 py-0.5 text-xs text-muted-foreground"
                        >
                            {categoryName(tag)}
                        </span>
                    ))}
                </span>
            )}
        </button>
    );
}
