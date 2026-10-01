import {
    Boxes,
    CalendarDays,
    CalendarHeart,
    Handshake,
    KanbanSquare,
    LayoutTemplate,
    LifeBuoy,
    Newspaper,
    Receipt,
    Search,
    UserSearch,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useMemo, useState } from 'react';
import type { KeyboardEvent } from 'react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
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
};

/** Tags too general to filter by. */
const GENERIC_TAGS = ['self-hosted', 'open-source', 'opensource'];

/** Categories offered above the list: the most used tags. */
const CATEGORIES = 10;

const categoryName = (tag: string) =>
    tag.length <= 3
        ? tag.toUpperCase()
        : tag.charAt(0).toUpperCase() + tag.slice(1).replaceAll('-', ' ');

/** Most matches shown at once; searching narrows the rest. */
const LIMIT = 200;

/**
 * "Browse all templates" (PRJ-012): the built-in templates and every registry's as one searchable list.
 * `templates` is undefined until the page has loaded them.
 */
export default function TemplateBrowser({
    open,
    onOpenChange,
    templates,
    compose,
    onPick,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    templates: CatalogTemplate[] | undefined;
    /** New projects' sandboxes can run Docker Compose, so registry templates can be picked. */
    compose: boolean;
    onPick: (template: CatalogTemplate) => void;
}) {
    const [query, setQuery] = useState('');
    const [category, setCategory] = useState<string | null>(null);
    const [highlighted, setHighlighted] = useState(0);

    // Organized by what the apps do, not where they come from.
    const categories = useMemo(() => {
        const counts = new Map<string, number>();

        for (const template of templates ?? []) {
            for (const tag of template.tags) {
                if (!GENERIC_TAGS.includes(tag)) {
                    counts.set(tag, (counts.get(tag) ?? 0) + 1);
                }
            }
        }

        return [...counts.entries()]
            .sort((a, b) => b[1] - a[1])
            .slice(0, CATEGORIES)
            .map(([tag]) => tag);
    }, [templates]);

    const matches = useMemo(() => {
        const words = query.toLowerCase().split(/\s+/).filter(Boolean);

        return (templates ?? []).filter((template) => {
            if (category && !template.tags.includes(category)) {
                return false;
            }

            const text = [
                template.label,
                template.description,
                ...template.tags,
            ]
                .join(' ')
                .toLowerCase();

            return words.every((word) => text.includes(word));
        });
    }, [templates, query, category]);

    const shown = matches.slice(0, LIMIT);
    const usable = (template: CatalogTemplate) => compose || !template.compose;

    const close = (next: boolean) => {
        onOpenChange(next);

        if (!next) {
            setQuery('');
            setCategory(null);
            setHighlighted(0);
        }
    };

    const pick = (template: CatalogTemplate) => {
        if (usable(template)) {
            onPick(template);
            close(false);
        }
    };

    const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        const count = shown.length;

        if (event.key === 'ArrowDown' && count > 0) {
            event.preventDefault();
            setHighlighted((index) => (index + 1) % count);
        } else if (event.key === 'ArrowUp' && count > 0) {
            event.preventDefault();
            setHighlighted((index) => (index - 1 + count) % count);
        } else if (event.key === 'Enter' && shown[highlighted]) {
            event.preventDefault();
            pick(shown[highlighted]);
        }
    };

    return (
        <Dialog open={open} onOpenChange={close}>
            <DialogContent className="top-[10%] translate-y-0 gap-0 p-0 sm:max-w-2xl [&>button:last-child]:hidden">
                <DialogTitle className="sr-only">Browse templates</DialogTitle>
                <DialogDescription className="sr-only">
                    Find a template to start the project from.
                </DialogDescription>
                <div className="flex items-center gap-2 border-b px-3">
                    <Search className="size-4 shrink-0 text-muted-foreground" />
                    <input
                        autoFocus
                        value={query}
                        onChange={(event) => {
                            setQuery(event.target.value);
                            setHighlighted(0);
                        }}
                        onKeyDown={onKeyDown}
                        placeholder="Search templates…"
                        aria-label="Search templates"
                        className="h-11 w-full bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                        data-test="template-search-input"
                    />
                </div>
                {categories.length > 0 && (
                    <div className="flex flex-wrap gap-1 border-b px-3 py-2">
                        {[null, ...categories].map((name) => (
                            <button
                                key={name ?? 'all'}
                                type="button"
                                onClick={() => {
                                    setCategory(name);
                                    setHighlighted(0);
                                }}
                                aria-pressed={category === name}
                                className={cn(
                                    'rounded-full border border-input px-2.5 py-0.5 text-xs text-muted-foreground hover:bg-muted',
                                    category === name &&
                                        'border-primary bg-muted text-foreground',
                                )}
                            >
                                {name ? categoryName(name) : 'All'}
                            </button>
                        ))}
                    </div>
                )}
                <div className="h-[28rem] overflow-y-auto p-1">
                    {templates === undefined &&
                        Array.from({ length: 6 }, (_, index) => (
                            <div key={index} className="flex gap-3 px-3 py-2">
                                <Skeleton className="size-8 shrink-0 rounded-md" />
                                <div className="flex-1 space-y-1.5">
                                    <Skeleton className="h-3.5 w-1/3" />
                                    <Skeleton className="h-3 w-2/3" />
                                </div>
                            </div>
                        ))}
                    {templates !== undefined && matches.length === 0 && (
                        <p className="px-3 py-6 text-center text-sm text-muted-foreground">
                            No templates found.
                        </p>
                    )}
                    {shown.map((template, index) => (
                        <TemplateRow
                            key={template.value}
                            template={template}
                            usable={usable(template)}
                            highlighted={index === highlighted}
                            onHover={() => setHighlighted(index)}
                            onPick={() => pick(template)}
                        />
                    ))}
                    {matches.length > LIMIT && (
                        <p className="px-3 py-3 text-center text-xs text-muted-foreground">
                            {matches.length - LIMIT} more. Search to narrow them
                            down.
                        </p>
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}

function TemplateRow({
    template,
    usable,
    highlighted,
    onHover,
    onPick,
}: {
    template: CatalogTemplate;
    usable: boolean;
    highlighted: boolean;
    onHover: () => void;
    onPick: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onPick}
            onMouseMove={onHover}
            aria-disabled={!usable}
            title={
                usable
                    ? undefined
                    : 'Runs with Docker Compose. An admin can turn on Docker inside sandboxes in Settings → Sandboxes.'
            }
            className={cn(
                'flex w-full items-start gap-3 rounded-md px-3 py-2 text-left',
                highlighted && 'bg-accent text-accent-foreground',
                !usable && 'cursor-not-allowed opacity-60',
            )}
            data-test="template-result"
        >
            <TemplateLogo template={template} />
            <span className="min-w-0 flex-1 space-y-0.5">
                <span className="flex items-center gap-2 text-sm font-medium">
                    <span className="truncate">{template.label}</span>
                </span>
                <span className="line-clamp-2 text-xs text-muted-foreground">
                    {usable
                        ? template.description
                        : `Needs Docker inside sandboxes. ${template.description}`}
                </span>
            </span>
        </button>
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
