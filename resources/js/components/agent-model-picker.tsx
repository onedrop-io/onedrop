import { Check, ChevronDown, ChevronRight, Search, Star } from "lucide-react";
import { useEffect, useMemo, useState } from "react";
import AgentModelController from "@/actions/App/Http/Controllers/AgentModelController";
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { cn } from "@/lib/utils";
import type {
    AgentProvider,
    AgentSelection,
    CatalogModel,
    CatalogProvider,
} from "@/types";

const EFFORT_LABELS: Record<string, string> = {
    none: "None",
    minimal: "Minimal",
    low: "Low",
    medium: "Medium",
    high: "High",
    xhigh: "Extra high",
    max: "Max",
};

export function effortLabel(effort: string | null): string {
    return effort ? (EFFORT_LABELS[effort] ?? effort) : "Default";
}

function ProviderIcon({
    provider,
    className,
}: {
    provider: AgentProvider;
    className?: string;
}) {
    const style = {
        claude: ["A", "bg-orange-500/15 text-orange-600 dark:text-orange-400"],
        codex: [
            "O",
            "bg-emerald-500/15 text-emerald-600 dark:text-emerald-400",
        ],
        openrouter: ["OR", "bg-sky-500/15 text-sky-600 dark:text-sky-400"],
    }[provider];

    return (
        <span
            className={cn(
                "inline-flex size-5 shrink-0 items-center justify-center rounded text-[10px] font-semibold",
                style[1],
                className,
            )}
            aria-hidden
        >
            {style[0]}
        </span>
    );
}

function formatContext(tokens: number | null): string | null {
    if (!tokens) {
        return null;
    }

    return tokens >= 1_000_000
        ? `${+(tokens / 1_000_000).toFixed(1)}M`
        : `${Math.round(tokens / 1000)}k`;
}

type Catalog = {
    providers: CatalogProvider[];
    favorites: string[];
    /** Recently chosen models ("provider:model"), newest first. */
    recent: string[];
};

const RECENT_LIMIT = 3;

let cachedCatalog: Promise<Catalog> | null = null;

function loadCatalog(): Promise<Catalog> {
    cachedCatalog ??= fetch(AgentModelController.index.url(), {
        headers: { Accept: "application/json" },
        credentials: "same-origin",
    }).then((response) => {
        if (!response.ok) {
            cachedCatalog = null;
            throw new Error("Could not load models");
        }

        return response.json() as Promise<Catalog>;
    });

    return cachedCatalog;
}

function csrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : "";
}

/**
 * The model and reasoning pickers shown in the chat composer.
 */
export default function AgentModelPicker({
    selection,
    onChange,
    disabled = false,
}: {
    selection: AgentSelection;
    onChange: (selection: AgentSelection) => void;
    disabled?: boolean;
}) {
    return (
        <div className="flex items-center gap-1" data-test="agent-pickers">
            <ModelMenu
                selection={selection}
                onChange={onChange}
                disabled={disabled}
            />
            {selection.efforts.length > 0 && (
                <ReasoningMenu
                    selection={selection}
                    onChange={onChange}
                    disabled={disabled}
                />
            )}
        </div>
    );
}

function ModelMenu({
    selection,
    onChange,
    disabled,
}: {
    selection: AgentSelection;
    onChange: (selection: AgentSelection) => void;
    disabled: boolean;
}) {
    const [open, setOpen] = useState(false);
    const [catalog, setCatalog] = useState<Catalog | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [tab, setTab] = useState<AgentProvider | "favorites">(
        selection.provider,
    );
    const [query, setQuery] = useState("");
    const [showMore, setShowMore] = useState(false);

    useEffect(() => {
        if (!open || catalog) {
            return;
        }

        loadCatalog()
            .then(setCatalog)
            .catch((e: Error) => setError(e.message));
    }, [open, catalog]);

    const favorites = useMemo(
        () => new Set(catalog?.favorites ?? []),
        [catalog],
    );

    const rows = useMemo(() => {
        if (!catalog) {
            return { recent: [], featured: [], more: [] };
        }

        const entries = catalog.providers.flatMap((provider) =>
            provider.models.map((model) => ({ provider, model })),
        );
        const needle = query.trim().toLowerCase();
        const inTab = entries.filter(({ provider, model }) =>
            tab === "favorites"
                ? favorites.has(`${provider.id}:${model.id}`)
                : provider.id === tab,
        );

        if (needle) {
            const matches = inTab.filter(
                ({ model }) =>
                    model.name.toLowerCase().includes(needle) ||
                    model.id.toLowerCase().includes(needle),
            );

            return { recent: [], featured: matches.slice(0, 100), more: [] };
        }

        if (tab === "favorites") {
            return { recent: [], featured: inTab, more: [] };
        }

        const recentKeys = catalog.recent
            .filter((key) =>
                inTab.some(
                    ({ provider, model }) =>
                        `${provider.id}:${model.id}` === key,
                ),
            )
            .slice(0, RECENT_LIMIT);
        const rest = inTab.filter(
            ({ provider, model }) =>
                !recentKeys.includes(`${provider.id}:${model.id}`),
        );

        return {
            recent: recentKeys.map((key) =>
                inTab.find(
                    ({ provider, model }) =>
                        `${provider.id}:${model.id}` === key,
                )!,
            ),
            featured: rest.filter(({ model }) => model.featured),
            more: rest.filter(({ model }) => !model.featured),
        };
    }, [catalog, tab, query, favorites]);

    const choose = (provider: CatalogProvider, model: CatalogModel) => {
        onChange({
            provider: provider.id,
            model: model.id,
            name: model.name,
            efforts: model.efforts,
            // Keep the reasoning level when the new model supports it.
            variant:
                selection.variant && model.efforts.includes(selection.variant)
                    ? selection.variant
                    : null,
        });
        setOpen(false);
        setQuery("");

        if (catalog) {
            const key = `${provider.id}:${model.id}`;
            const next = {
                ...catalog,
                recent: [key, ...catalog.recent.filter((item) => item !== key)],
            };

            setCatalog(next);
            cachedCatalog = Promise.resolve(next);
        }
    };

    const toggleFavorite = (provider: AgentProvider, model: string) => {
        if (!catalog) {
            return;
        }

        const key = `${provider}:${model}`;
        const favorite = !favorites.has(key);
        const next = favorite
            ? [...catalog.favorites, key]
            : catalog.favorites.filter((item) => item !== key);

        setCatalog({ ...catalog, favorites: next });
        cachedCatalog = Promise.resolve({ ...catalog, favorites: next });

        void fetch(AgentModelController.favorite.url(), {
            method: "PUT",
            credentials: "same-origin",
            headers: {
                Accept: "application/json",
                "Content-Type": "application/json",
                "X-XSRF-TOKEN": csrfToken(),
            },
            body: JSON.stringify({ provider, model, favorite }),
        });
    };

    const renderRow = ({
        provider,
        model,
    }: {
        provider: CatalogProvider;
        model: CatalogModel;
    }) => {
        const key = `${provider.id}:${model.id}`;
        const selected =
            provider.id === selection.provider && model.id === selection.model;
        const details = [
            provider.label,
            formatContext(model.context),
            model.cost ? `$${model.cost.input}/$${model.cost.output}` : null,
        ].filter(Boolean);

        return (
            <div
                key={key}
                className={cn(
                    "group flex items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-muted",
                    selected && "bg-muted",
                )}
            >
                <button
                    type="button"
                    onClick={() => choose(provider, model)}
                    className="flex min-w-0 flex-1 items-center gap-2 text-left"
                    data-test={`model-${key}`}
                >
                    <ProviderIcon provider={provider.id} />
                    <span className="min-w-0 flex-1">
                        <span className="block truncate text-sm">
                            {model.name}
                        </span>
                        <span className="block truncate text-xs text-muted-foreground">
                            {details.join(" · ")}
                        </span>
                    </span>
                    {selected && <Check className="size-4 shrink-0" />}
                </button>
                <button
                    type="button"
                    onClick={() => toggleFavorite(provider.id, model.id)}
                    aria-label={
                        favorites.has(key)
                            ? `Unstar ${model.name}`
                            : `Star ${model.name}`
                    }
                    className={cn(
                        "shrink-0 rounded p-1 text-muted-foreground hover:text-foreground",
                        !favorites.has(key) &&
                            "opacity-0 group-hover:opacity-100 focus:opacity-100",
                    )}
                    data-test={`star-${key}`}
                >
                    <Star
                        className={cn(
                            "size-3.5",
                            favorites.has(key) &&
                                "fill-amber-400 text-amber-400",
                        )}
                    />
                </button>
            </div>
        );
    };

    return (
        <DropdownMenu modal={false} open={open} onOpenChange={setOpen}>
            <DropdownMenuTrigger asChild disabled={disabled}>
                <button
                    type="button"
                    className="inline-flex max-w-56 items-center gap-1.5 rounded-lg px-2 py-1 text-xs text-foreground hover:bg-muted disabled:opacity-50"
                    data-test="model-picker"
                >
                    <ProviderIcon
                        provider={selection.provider}
                        className="size-4 text-[9px]"
                    />
                    <span className="truncate">{selection.name}</span>
                    <ChevronDown className="size-3 shrink-0 text-muted-foreground" />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="start"
                side="top"
                className="flex h-[26rem] w-[min(34rem,calc(100vw-2rem))] p-0"
                onFocusOutside={(event) => event.preventDefault()}
                data-test="model-menu"
            >
                <nav
                    aria-label="Providers"
                    className="flex w-12 shrink-0 flex-col items-center gap-1 border-r py-2"
                >
                    <RailButton
                        active={tab === "favorites"}
                        label="Favorites"
                        onClick={() => setTab("favorites")}
                    >
                        <Star className="size-4" />
                    </RailButton>
                    {catalog?.providers.map((provider) => (
                        <RailButton
                            key={provider.id}
                            active={tab === provider.id}
                            label={provider.label}
                            onClick={() => setTab(provider.id)}
                        >
                            <ProviderIcon provider={provider.id} />
                        </RailButton>
                    ))}
                </nav>
                <div className="flex min-w-0 flex-1 flex-col">
                    <label className="flex items-center gap-2 border-b px-3 py-2">
                        <Search className="size-4 text-muted-foreground" />
                        <span className="sr-only">Search models</span>
                        <input
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            // Keep typing out of the menu's type-to-select.
                            onKeyDown={(event) => event.stopPropagation()}
                            placeholder="Search models…"
                            className="w-full bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                            autoFocus
                            data-test="model-search"
                        />
                    </label>
                    <div className="flex-1 overflow-y-auto p-1">
                        {error ? (
                            <p className="p-3 text-sm text-red-600">{error}</p>
                        ) : !catalog ? (
                            <p className="p-3 text-sm text-muted-foreground">
                                Loading models…
                            </p>
                        ) : rows.recent.length === 0 &&
                          rows.featured.length === 0 &&
                          rows.more.length === 0 ? (
                            <p className="p-3 text-sm text-muted-foreground">
                                {tab === "favorites"
                                    ? "Star a model to keep it here."
                                    : "No matching models."}
                            </p>
                        ) : (
                            <>
                                {rows.recent.length > 0 && (
                                    <div data-test="recent-models">
                                        <SectionLabel>Recent</SectionLabel>
                                        {rows.recent.map(renderRow)}
                                        {rows.featured.length > 0 && (
                                            <SectionLabel>
                                                Featured
                                            </SectionLabel>
                                        )}
                                    </div>
                                )}
                                {rows.featured.map(renderRow)}
                                {rows.more.length > 0 && (
                                    <>
                                        <button
                                            type="button"
                                            onClick={() =>
                                                setShowMore(!showMore)
                                            }
                                            className="flex w-full items-center justify-between rounded-lg px-2 py-2 text-left text-sm hover:bg-muted"
                                            data-test="more-models"
                                        >
                                            <span>
                                                More models
                                                <span className="block text-xs text-muted-foreground">
                                                    {rows.more.length} models
                                                </span>
                                            </span>
                                            <ChevronRight
                                                className={cn(
                                                    "size-4 text-muted-foreground transition-transform",
                                                    showMore && "rotate-90",
                                                )}
                                            />
                                        </button>
                                        {showMore && rows.more.map(renderRow)}
                                    </>
                                )}
                            </>
                        )}
                    </div>
                </div>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function SectionLabel({ children }: { children: React.ReactNode }) {
    return (
        <p className="px-2 pt-2 pb-1 text-xs font-medium text-muted-foreground">
            {children}
        </p>
    );
}

function RailButton({
    active,
    label,
    onClick,
    children,
}: {
    active: boolean;
    label: string;
    onClick: () => void;
    children: React.ReactNode;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-label={label}
            title={label}
            aria-pressed={active}
            className={cn(
                "flex size-9 items-center justify-center rounded-lg text-muted-foreground hover:bg-muted",
                active && "bg-muted text-foreground",
            )}
        >
            {children}
        </button>
    );
}

function ReasoningMenu({
    selection,
    onChange,
    disabled,
}: {
    selection: AgentSelection;
    onChange: (selection: AgentSelection) => void;
    disabled: boolean;
}) {
    return (
        <DropdownMenu modal={false}>
            <DropdownMenuTrigger asChild disabled={disabled}>
                <button
                    type="button"
                    className="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs text-muted-foreground hover:bg-muted disabled:opacity-50"
                    data-test="reasoning-picker"
                >
                    {effortLabel(selection.variant)}
                    <ChevronDown className="size-3" />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="start"
                side="top"
                onFocusOutside={(event) => event.preventDefault()}
            >
                <DropdownMenuLabel className="text-xs text-muted-foreground">
                    Reasoning
                </DropdownMenuLabel>
                {[null, ...selection.efforts].map((effort) => (
                    <DropdownMenuItem
                        key={effort ?? "default"}
                        onSelect={() =>
                            onChange({ ...selection, variant: effort })
                        }
                        data-test={`reasoning-${effort ?? "default"}`}
                    >
                        <span className="flex-1">{effortLabel(effort)}</span>
                        {effort === selection.variant && (
                            <Check className="size-4" />
                        )}
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
